<?php

namespace App\Fiscal\Documents;

use App\Models\FiscalDocument;
use App\Models\FiscalDocumentPrint;
use App\Models\LegalEntity;
use App\Models\PlatformSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Fiscal document PDFs, rendered on demand and always as issued.
 *
 * Nothing is stored but a small print record made at issue. Every render is
 * drawn from the document's immutable rows, the issuer details frozen on that
 * day, the logo it carried and the layout version it was issued under, and
 * only after the fingerprint of all of that is checked against the record.
 * A later template, profile or logo change therefore never reaches a document
 * already issued, and a document whose stored values have been altered is
 * refused rather than printed.
 */
class FiscalDocumentPrints
{
    public function __construct(
        private FiscalDocumentPdf $renderer,
        private FiscalDocumentPrintSource $source,
    ) {}

    /**
     * Freeze how the document prints. Called inside the issuing transaction;
     * safe to call again, and from documents issued before records existed.
     */
    public function record(FiscalDocument $document): FiscalDocumentPrint
    {
        if ($document->document_no === null) {
            throw new LogicException('A draft has no number and nothing to print.');
        }

        $existing = $document->printRecord()->first();

        if ($existing instanceof FiscalDocumentPrint) {
            return $existing;
        }

        $document->loadMissing(['legalEntity', 'establishment']);
        $layout = (string) config('fiscal.print.layout');
        $issuer = $this->issuer($document);
        [$logoPath, $logoSha256] = $this->logo($document->legalEntity);

        try {
            return FiscalDocumentPrint::query()->create([
                'workspace_id' => $document->workspace_id,
                'legal_entity_id' => $document->legal_entity_id,
                'fiscal_document_id' => $document->id,
                'layout_version' => $layout,
                'issuer' => $issuer,
                'logo_path' => $logoPath,
                'logo_sha256' => $logoSha256,
                'source_sha256' => $this->source->fingerprint($document, $layout, $issuer, $logoSha256),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $document->printRecord()->firstOrFail();
        }
    }

    /** The print record, after checking the document still matches it. */
    public function verified(FiscalDocument $document): FiscalDocumentPrint
    {
        $print = $this->record($document);

        $fingerprint = $this->source->fingerprint(
            $document,
            $print->layout_version,
            $print->issuer,
            $print->logo_sha256,
        );

        if (! hash_equals($print->source_sha256, $fingerprint)) {
            $this->refuse($document, 'Stored document values no longer match what was issued.');
        }

        if ($print->logo_path !== null) {
            $logo = Storage::disk('local')->get($print->logo_path);

            if ($logo === null || ! hash_equals((string) $print->logo_sha256, hash('sha256', $logo))) {
                $this->refuse($document, 'The logo the document was issued with is missing or altered.');
            }
        }

        return $print;
    }

    /**
     * The PDF, rendered now. Repeat requests within a few minutes (a viewer
     * re-fetching, an email and a download together) share one render.
     */
    public function pdf(FiscalDocument $document): string
    {
        $print = $this->verified($document);
        $seconds = (int) config('fiscal.print.cache_seconds', 600);
        $render = fn (): string => $this->renderer->render($document, $print);

        if ($seconds <= 0) {
            return $render();
        }

        return Cache::store(config('fiscal.print.cache_store'))->remember(
            "fiscal-document-pdf:{$document->id}:{$print->source_sha256}",
            $seconds,
            $render,
        );
    }

    /** Whether any issued document still prints this logo file. */
    public function logoInUse(LegalEntity $legalEntity, string $path): bool
    {
        return FiscalDocumentPrint::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('logo_path', $path)
            ->exists();
    }

    /** @return array<string, string|null> */
    private function issuer(FiscalDocument $document): array
    {
        return [
            'legal_name' => $document->legalEntity->legal_name,
            'trade_name' => $document->legalEntity->trade_name,
            'tax_identification_number' => $document->legalEntity->tax_identification_number,
            'establishment' => $document->establishment->name,
            'address_line' => $document->establishment->address_line,
            'municipality' => $document->establishment->municipality,
            'province_code' => $document->establishment->province_code,
            'support_email' => PlatformSetting::get('support_email') ?: null,
        ];
    }

    /** @return array{0: string|null, 1: string|null} */
    private function logo(LegalEntity $legalEntity): array
    {
        $path = $legalEntity->logo_path;
        $contents = $path === null ? null : Storage::disk('local')->get($path);

        return $contents === null ? [null, null] : [$path, hash('sha256', $contents)];
    }

    private function refuse(FiscalDocument $document, string $reason): never
    {
        Log::critical('Fiscal document refused for printing.', [
            'reason' => $reason,
            'document_public_id' => $document->public_id,
            'document_no' => $document->document_no,
        ]);

        throw new PrintRecordMismatch("{$document->document_no}: {$reason}");
    }
}
