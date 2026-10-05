<?php

namespace App\Fiscal\Documents;

use App\Models\LegalEntity;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf;

/**
 * What every printed fiscal sheet shares: the engine settings, the logo, and
 * the guard rails that keep a render deterministic in production.
 *
 * The invoice, receipt and transport guide differ in content, never in how
 * the PDF is made, so they all come through here.
 */
class PdfSheet
{
    /** Enough for a several-hundred-line document; mPDF holds every page until output. */
    private const string MEMORY_LIMIT = '512M';

    public function __construct(private PrintFormat $format) {}

    /**
     * Render a Blade sheet to PDF bytes.
     *
     * @param  array<string, mixed>  $data
     * @param  array{title: string, author: string, subject: string, margin_top?: int, watermark?: string|null}  $metadata
     */
    public function render(string $view, array $data, array $metadata): string
    {
        $this->ensureMemory();

        $pdf = LaravelMpdf::loadView(
            $view,
            [
                ...$data,
                // Passed in rather than done in the template: a sheet formats
                // figures in a dozen places and they must not drift apart.
                'money' => fn (int $minor): string => $this->format->money($minor),
                'number' => fn (string $value, int $minimumDecimals = 0): string => $this->format->decimal($value, $minimumDecimals),
                'percent' => fn (string $value): string => $this->format->percent($value),
            ],
            [],
            $this->configuration($metadata),
        );

        $pdf->getMpdf()->SetCreator((string) config('app.name'));

        return $pdf->output();
    }

    /**
     * The company logo as a data URI.
     *
     * Inlined because mPDF would otherwise have to fetch it, and a document
     * that renders differently depending on whether the disk is reachable is
     * not a document you can rely on.
     */
    public function logo(LegalEntity $legalEntity): ?string
    {
        $path = $legalEntity->logo_path;

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $contents = Storage::disk('local')->get($path);
        $mime = Storage::disk('local')->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) $contents);
    }

    /**
     * @param  array{title: string, author: string, subject: string, margin_top?: int, watermark?: string|null}  $metadata
     * @return array<string, mixed>
     */
    private function configuration(array $metadata): array
    {
        return [
            'format' => (string) config('fiscal.print.paper', 'A4'),
            'orientation' => 'P',
            /*
             * mPDF draws the repeating header inside the top margin, so the
             * margin has to be as tall as the header or the body prints over
             * it. Measured against the header block in the templates.
             */
            'margin_top' => $metadata['margin_top'] ?? 34,
            'margin_bottom' => 18,
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_header' => 8,
            'margin_footer' => 9,
            'title' => $metadata['title'],
            'author' => $metadata['author'],
            'subject' => $metadata['subject'],
            // Angolan documents carry accented Portuguese throughout; the
            // default core fonts do not cover it.
            'mode' => 'utf-8',
            'default_font' => 'dejavusans',
            /*
             * mPDF's default squeezes a table that will not fit the rest of a
             * page, by up to 40%. On a fiscal sheet that shrinks the QR and
             * the totals on whichever page happens to be nearly full; a block
             * that does not fit has to move to the next page whole instead.
             */
            'shrink_tables_to_fit' => 1,
            // Font metrics and image scratch files. Kept out of storage/app,
            // which is the private disk holding customers' files.
            'temp_dir' => $this->temporaryDirectory(),
            /*
             * A cancelled document is stamped on every page by the engine
             * itself. A positioned CSS block would only land on the first,
             * and mPDF does not honour the transform it would need.
             */
            'watermark' => $metadata['watermark'] ?? '',
            'show_watermark' => ($metadata['watermark'] ?? null) !== null,
            'watermark_font' => 'dejavusans',
            'watermark_text_alpha' => 0.1,
        ];
    }

    private function temporaryDirectory(): string
    {
        $directory = storage_path('framework/cache/mpdf');

        File::ensureDirectoryExists($directory);

        return $directory;
    }

    /** Raise, never lower, the limit for the length of one render. */
    private function ensureMemory(): void
    {
        $current = $this->bytes((string) ini_get('memory_limit'));

        if ($current !== -1 && $current < $this->bytes(self::MEMORY_LIMIT)) {
            ini_set('memory_limit', self::MEMORY_LIMIT);
        }
    }

    private function bytes(string $limit): int
    {
        $limit = trim($limit);

        if ($limit === '' || $limit === '-1') {
            return -1;
        }

        $units = ['k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3];
        $unit = strtolower(substr($limit, -1));

        return isset($units[$unit])
            ? (int) substr($limit, 0, -1) * $units[$unit]
            : (int) $limit;
    }
}
