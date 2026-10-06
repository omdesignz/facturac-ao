<?php

use App\Fiscal\Documents\FiscalDocumentPdf;
use App\Fiscal\Documents\FiscalDocumentPresenter;
use App\Fiscal\Documents\FiscalDocumentPrints;
use App\Fiscal\Documents\PrintFormat;
use App\Fiscal\Documents\PrintRecordMismatch;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentPrint;
use App\Models\LegalEntity;
use App\Models\User;
use App\Notifications\FiscalDocumentIssued;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The fingerprint of each layout's files. Documents are re-rendered with
 * exactly the files of the version they were issued under; see the
 * frozen-layout test.
 */
const FROZEN_LAYOUTS = [
    'v1' => 'c590bc229e456e4ce1843557bde3bf825d711c28dac0e53231b4c6b75692ff69',
    'v2' => '6c48131289231a5edd5e79eabad494df9731dbfaf98c7fa63bc367e01ce1b93e',
];

/**
 * @return array{owner: User, legalEntity: LegalEntity, establishment: Establishment}
 */
function pdfFixture(): array
{
    $owner = User::factory()->withWorkspace('VAP PDF')->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();

    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
    ]);

    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);

    return compact('owner', 'legalEntity', 'establishment');
}

/** @param array<string, mixed> $fixture */
function documentWithLines(array $fixture, int $lines = 3, FiscalDocumentType $type = FiscalDocumentType::Invoice): FiscalDocument
{
    // Built as a draft and issued afterwards, because the lines of an issued
    // document are immutable — which is the app working, not a test problem.
    $document = FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'document_type' => $type,
        'status' => FiscalDocumentStatus::Draft,
        'document_no' => null,
    ]);

    for ($i = 1; $i <= $lines; $i++) {
        $net = 250_000;
        $tax = intdiv($net * 1400, 10_000);

        $line = $document->lines()->create([
            'workspace_id' => $document->workspace_id,
            'legal_entity_id' => $document->legal_entity_id,
            'line_number' => $i,
            'operation_type' => 'SG',
            'product_code' => 'ART-'.$i,
            'product_description' => "Artigo de demonstração {$i}",
            'quantity_units' => 1_000,
            'quantity_scale' => 3,
            'unit_of_measure' => 'UN',
            'unit_price_base_minor' => $net,
            'unit_price_micros' => $net * 1_000_000,
            'discount_rate_basis_points' => 0,
            'base_amount_minor' => $net,
            'settlement_amount_minor' => 0,
            'net_amount_minor' => $net,
            'tax_amount_minor' => $tax,
            'gross_amount_minor' => $net + $tax,
        ]);

        $line->taxes()->create([
            'workspace_id' => $document->workspace_id,
            'legal_entity_id' => $document->legal_entity_id,
            'fiscal_document_id' => $document->id,
            'tax_type' => 'IVA',
            'tax_country_region' => 'AO',
            'tax_code' => 'NOR',
            'tax_rate_basis_points' => 1400,
            'tax_contribution_minor' => $tax,
        ]);
    }

    $document->forceFill([
        'status' => FiscalDocumentStatus::Valid,
        'document_no' => 'FT 2026/'.fake()->unique()->numberBetween(1000, 9999),
        'agt_document_status' => 'V',
        'software_validation_number' => '123/AGT/2026',
        'document_payload_sha256' => hash('sha256', 'exemplo'),
        'issued_at' => now(),
        'net_total_minor' => $document->lines()->sum('net_amount_minor'),
        'tax_payable_minor' => $document->lines()->sum('tax_amount_minor'),
        'gross_total_minor' => $document->lines()->sum('gross_amount_minor'),
    ])->saveQuietly();

    return $document->fresh();
}

function pageCount(string $pdf): int
{
    return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
}

/**
 * Every image the PDF draws, as [width, height] in points, read from the
 * placement matrix in the page content ("w 0 0 h x y cm /I1 Do").
 *
 * @return list<array{0: float, 1: float}>
 */
function drawnImageSizes(string $pdf): array
{
    preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $pdf, $streams);
    $sizes = [];

    foreach ($streams[1] as $stream) {
        $content = @gzuncompress($stream);

        if ($content === false) {
            continue;
        }

        preg_match_all('#([-\d.]+) 0 0 ([-\d.]+) [-\d.]+ [-\d.]+ cm\s*/I\d+ Do#', $content, $placements, PREG_SET_ORDER);

        foreach ($placements as $placement) {
            $sizes[] = [(float) $placement[1], (float) $placement[2]];
        }
    }

    return $sizes;
}

/** @return list<string> the image dictionaries embedded in the PDF */
function embeddedImages(string $pdf): array
{
    preg_match_all('#<<[^>]*?/Subtype /Image.*?>>\r?\nstream#s', $pdf, $images);

    return $images[0];
}

// ------------------------------------------------------------------ rendering

test('an issued document renders as a PDF', function () {
    $fixture = pdfFixture();
    $pdf = app(FiscalDocumentPrints::class)->pdf(documentWithLines($fixture));

    expect($pdf)->toStartWith('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(5_000)
        ->and(pageCount($pdf))->toBe(1);
});

test('line items flow onto further pages rather than being cut off', function () {
    $fixture = pdfFixture();

    // The reason for a PDF engine rather than a print stylesheet.
    $pdf = app(FiscalDocumentPrints::class)->pdf(documentWithLines($fixture, lines: 60));

    expect(pageCount($pdf))->toBeGreaterThan(1);
});

test('the filename is the document number, safe for a filesystem', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);

    // "FT 2026/1234" is a path with a directory in it as far as a disk cares.
    expect(app(FiscalDocumentPdf::class)->filename($document))
        ->not->toContain('/')
        ->and(app(FiscalDocumentPdf::class)->filename($document))->toEndWith('.pdf');
});

test('the verification QR is the 350 × 350 PNG, drawn square at 30 mm', function (int $lines) {
    $fixture = pdfFixture();
    $pdf = app(FiscalDocumentPrints::class)->pdf(documentWithLines($fixture, $lines));

    $qrImages = array_values(array_filter(
        embeddedImages($pdf),
        fn (string $image): bool => str_contains($image, '/Width 350') && str_contains($image, '/Height 350'),
    ));

    // 30 mm in PDF points. Same size whether the totals end page one or
    // land at the foot of page three: never squeezed to fit.
    $thirtyMillimetres = 30 / 25.4 * 72;
    $drawn = drawnImageSizes($pdf);

    expect($qrImages)->toHaveCount(1)
        ->and($qrImages[0])->not->toContain('/SMask')
        ->and($drawn)->toHaveCount(1)
        ->and($drawn[0][0])->toBe($drawn[0][1])
        ->and($drawn[0][0])->toEqualWithDelta($thirtyMillimetres, 0.01);
})->with([
    'one page' => 3,
    'totals at a page break' => 18,
    'three pages' => 60,
]);

test('a receipt carries the same square QR', function () {
    $fixture = pdfFixture();
    $pdf = app(FiscalDocumentPrints::class)->pdf(
        documentWithLines($fixture, lines: 1, type: FiscalDocumentType::Receipt),
    );

    expect(drawnImageSizes($pdf))->toHaveCount(1)
        ->and(drawnImageSizes($pdf)[0][0])->toBe(drawnImageSizes($pdf)[0][1]);
});

test('the PDF says what made it and keeps its scratch files off the private disk', function () {
    $fixture = pdfFixture();
    $pdf = app(FiscalDocumentPrints::class)->pdf(documentWithLines($fixture));

    // mPDF writes metadata as UTF-16 text strings.
    $creator = "\xFE\xFF".mb_convert_encoding((string) config('app.name'), 'UTF-16BE', 'UTF-8');

    expect($pdf)->toContain('/Creator ('.$creator.')')
        ->and(is_dir(storage_path('framework/cache/mpdf')))->toBeTrue();
});

test('figures print the Angolan way, not as machine decimals', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture, lines: 1);

    $html = view('documents.pdf.v1.invoice', [
        'document' => app(FiscalDocumentPresenter::class)->forPrint($document),
        'settlements' => [],
        'logo' => null,
        'money' => fn (int $minor): string => app(PrintFormat::class)->money($minor),
        'number' => fn (string $value, int $minimumDecimals = 0): string => app(PrintFormat::class)->decimal($value, $minimumDecimals),
        'percent' => fn (string $value): string => app(PrintFormat::class)->percent($value),
    ])->render();

    // "1.000 UN" reads as a thousand units to an Angolan reader.
    expect($html)->toContain('1 UN')
        ->not->toContain('1.000 UN')
        ->toContain('Imposto a 14%')
        ->not->toContain('14.00%');
});

test('a receipt uses its own sheet', function () {
    $fixture = pdfFixture();

    $receipt = documentWithLines($fixture, lines: 1, type: FiscalDocumentType::Receipt);

    expect(app(FiscalDocumentPrints::class)->pdf($receipt))->toStartWith('%PDF-');
});

test('a draft has no PDF to serve', function () {
    $fixture = pdfFixture();

    $draft = FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'status' => FiscalDocumentStatus::Draft,
        'document_no' => null,
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('documents.pdf', $draft))
        ->assertNotFound();
});

test('the PDF is served inline to the company', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);

    $this->actingAs($fixture['owner'])
        ->get(route('documents.pdf', $document))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('another company cannot fetch the PDF', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);
    $stranger = User::factory()->withWorkspace('Outra')->create();

    $this->actingAs($stranger)
        ->get(route('documents.pdf', $document))
        ->assertForbidden();
});

// ---------------------------------------------------------------------- logo

test('a logo is accepted and appears on the company profile', function () {
    Storage::fake('local');
    $fixture = pdfFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('company.logo.store'), [
            'logo' => UploadedFile::fake()->image('marca.png', 600, 200),
        ])->assertRedirect();

    $legalEntity = $fixture['legalEntity']->fresh();

    expect($legalEntity->logo_path)->not->toBeNull();
    Storage::disk('local')->assertExists($legalEntity->logo_path);
});

test('the company screen shows whether a logo is set', function () {
    Storage::fake('local');
    $fixture = pdfFixture();

    $this->actingAs($fixture['owner'])
        ->get(route('onboarding'))
        ->assertInertia(fn (Assert $page) => $page->where('company.has_logo', false));

    $this->actingAs($fixture['owner'])->post(route('company.logo.store'), [
        'logo' => UploadedFile::fake()->image('marca.png', 600, 200),
    ]);

    /*
     * The timestamp goes with it so the preview reloads. Without it the browser
     * keeps showing the old mark from cache, and the screen quietly disagrees
     * with the documents.
     */
    $this->actingAs($fixture['owner'])
        ->get(route('onboarding'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('company.has_logo', true)
            ->where('company.logo_updated_at', fn (?int $at): bool => is_int($at))
        );
});

test('an SVG is refused however it is named', function () {
    Storage::fake('local');
    $fixture = pdfFixture();

    // mPDF renders what it is handed, and an SVG can carry script and remote
    // references.
    $this->actingAs($fixture['owner'])
        ->post(route('company.logo.store'), [
            'logo' => UploadedFile::fake()->create('marca.svg', 10, 'image/svg+xml'),
        ])->assertSessionHasErrors('logo');
});

test('replacing the logo does not leave the old file behind', function () {
    Storage::fake('local');
    $fixture = pdfFixture();

    $this->actingAs($fixture['owner'])->post(route('company.logo.store'), [
        'logo' => UploadedFile::fake()->image('primeiro.png'),
    ]);

    $first = $fixture['legalEntity']->fresh()->logo_path;

    $this->actingAs($fixture['owner'])->post(route('company.logo.store'), [
        'logo' => UploadedFile::fake()->image('segundo.png'),
    ]);

    Storage::disk('local')->assertMissing($first);
});

test('the logo is embedded in the PDF rather than linked', function () {
    Storage::fake('local');
    $fixture = pdfFixture();

    $this->actingAs($fixture['owner'])->post(route('company.logo.store'), [
        'logo' => UploadedFile::fake()->image('marca.png', 600, 200),
    ]);

    $withLogo = app(FiscalDocumentPrints::class)->pdf(documentWithLines($fixture));

    // A document issued once the logo is gone prints without one; the first
    // keeps its own, frozen at issue.
    $fixture['legalEntity']->forceFill(['logo_path' => null])->save();
    $withoutLogo = app(FiscalDocumentPrints::class)->pdf(documentWithLines($fixture));

    // A document that renders differently depending on whether a disk is
    // reachable is not one you can rely on, so the image travels inside it.
    expect(strlen($withLogo))->toBeGreaterThan(strlen($withoutLogo));
});

// ------------------------------------------------------------------ the email

test('the customer receives the document as an attachment', function () {
    Notification::fake();
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);

    $notification = FiscalDocumentIssued::fromModel($document);

    expect($notification->attachmentName)->toEndWith('.pdf');

    $mail = $notification->toMail((object) ['email' => 'cliente@exemplo.ao']);
    $attachments = $mail->rawAttachments;

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]['data'])->toStartWith('%PDF-')
        ->and($attachments[0]['name'])->toBe($notification->attachmentName);
});

test('the email still carries the link as well as the file', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);

    $mail = FiscalDocumentIssued::fromModel($document)
        ->toMail((object) ['email' => 'cliente@exemplo.ao']);

    expect($mail->actionUrl)->toContain('/documentos/');
});

// ------------------------------------------------------------ print records

test('a document prints from a record frozen on first use, not from today\'s profile', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);
    $prints = app(FiscalDocumentPrints::class);
    $issuedName = $fixture['legalEntity']->legal_name;

    $record = $prints->record($document);

    // The company renames and moves after issuing.
    $fixture['legalEntity']->forceFill(['legal_name' => 'Nome Novo, Lda.'])->saveQuietly();
    $fixture['establishment']->forceFill(['address_line' => 'Rua Nova, 1'])->saveQuietly();

    $printed = app(FiscalDocumentPresenter::class)->forPrint($document->fresh(), $prints->verified($document->fresh()));

    expect($record->layout_version)->toBe(config('fiscal.print.layout'))
        ->and($record->source_sha256)->toHaveLength(64)
        ->and($printed['company']['legal_name'])->toBe($issuedName)
        ->and($printed['company']['address_line'])->not->toBe('Rua Nova, 1')
        ->and(FiscalDocumentPrint::query()->count())->toBe(1);
});

test('recording twice keeps one print record', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);
    $prints = app(FiscalDocumentPrints::class);

    expect($prints->record($document)->is($prints->record($document->fresh())))->toBeTrue()
        ->and(FiscalDocumentPrint::query()->count())->toBe(1);
});

test('a document whose stored values changed after issue is refused, not printed', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);
    $prints = app(FiscalDocumentPrints::class);
    $prints->record($document);

    // Past the model guards, the way only a direct database edit could.
    DB::table('fiscal_document_lines')
        ->where('fiscal_document_id', $document->id)
        ->where('line_number', 1)
        ->update(['product_description' => 'Alterado depois da emissão']);

    expect(fn () => $prints->pdf($document->fresh()))->toThrow(PrintRecordMismatch::class);

    $this->actingAs($fixture['owner'])
        ->get(route('documents.pdf', $document))
        ->assertServerError();
});

test('a later AGT result or a resend does not disturb the fingerprint', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);
    $prints = app(FiscalDocumentPrints::class);
    $prints->record($document);

    DB::table('fiscal_documents')->where('id', $document->id)->update([
        'agt_document_status' => 'I',
        'sent_to_customer_at' => now(),
        'send_count' => 3,
        'updated_at' => now()->addDay(),
    ]);

    expect($prints->pdf($document->fresh()))->toStartWith('%PDF-');
});

test('the PDF route renders on demand and is never cached by the browser', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);

    $response = $this->actingAs($fixture['owner'])
        ->get(route('documents.pdf', $document))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->toStartWith('%PDF-')
        ->and($document->printRecord()->exists())->toBeTrue();
});

test('repeat requests within the cache window share one render', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);
    config(['fiscal.print.cache_seconds' => 600]);
    $prints = app(FiscalDocumentPrints::class);

    expect($prints->pdf($document))->toBe($prints->pdf($document->fresh()));
});

test('an old logo stays on disk while an issued document still prints it', function () {
    Storage::fake('local');
    $fixture = pdfFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('company.logo.store'), ['logo' => UploadedFile::fake()->image('antigo.png', 300, 120)]);
    $oldLogo = $fixture['legalEntity']->fresh()->logo_path;

    $document = documentWithLines($fixture);
    $record = app(FiscalDocumentPrints::class)->record($document);

    $this->actingAs($fixture['owner'])
        ->post(route('company.logo.store'), ['logo' => UploadedFile::fake()->image('novo.png', 300, 120)]);

    expect($record->logo_path)->toBe($oldLogo)
        ->and(Storage::disk('local')->exists($oldLogo))->toBeTrue()
        ->and($fixture['legalEntity']->fresh()->logo_path)->not->toBe($oldLogo)
        ->and(app(FiscalDocumentPrints::class)->pdf($document->fresh()))->toStartWith('%PDF-');
});

test('a missing logo that a document was issued with is refused', function () {
    Storage::fake('local');
    $fixture = pdfFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('company.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png', 300, 120)]);

    $document = documentWithLines($fixture);
    $record = app(FiscalDocumentPrints::class)->record($document);
    Storage::disk('local')->delete((string) $record->logo_path);

    app(FiscalDocumentPrints::class)->pdf($document->fresh());
})->throws(PrintRecordMismatch::class);

test('a print record can be neither changed nor deleted', function () {
    $record = FiscalDocumentPrint::factory()->create();

    expect(fn () => $record->update(['layout_version' => 'v2']))->toThrow(DomainException::class)
        ->and(fn () => $record->delete())->toThrow(DomainException::class);
});

test('a draft has nothing to print', function () {
    $fixture = pdfFixture();
    $draft = FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'status' => FiscalDocumentStatus::Draft,
        'document_no' => null,
    ]);

    app(FiscalDocumentPrints::class)->record($draft);
})->throws(LogicException::class);

test('the backfill freezes a print record for every issued document without one', function () {
    $fixture = pdfFixture();
    app(FiscalDocumentPrints::class)->record(documentWithLines($fixture, lines: 1));
    documentWithLines($fixture, lines: 1);
    documentWithLines($fixture, lines: 1);

    $this->artisan('fiscal:record-prints', ['--dry-run' => true])
        ->expectsOutputToContain('2 documento(s) sem registo de impressão.')
        ->assertSuccessful();

    expect(FiscalDocumentPrint::query()->count())->toBe(1);

    $this->artisan('fiscal:record-prints')->assertSuccessful();

    expect(FiscalDocumentPrint::query()->count())->toBe(3);
});

test('a used layout version is frozen: changing it means adding a new version', function (string $version) {
    // Documents issued under a version are re-rendered with these exact files
    // for as long as they are kept. A fix or redesign goes in a new version
    // and config('fiscal.print.layout') moves to it; a used one never changes.
    $files = collect(File::files(resource_path("views/documents/pdf/{$version}")))
        ->sortBy(fn ($file): string => $file->getFilename())
        ->map(fn ($file): string => $file->getFilename().':'.hash_file('sha256', $file->getPathname()))
        ->implode("\n");

    expect(hash('sha256', $files))->toBe(FROZEN_LAYOUTS[$version]);
})->with(array_keys(FROZEN_LAYOUTS));

test('every layout version in use has its engine settings and is pinned', function () {
    $current = config('fiscal.print.layout');

    expect(array_keys(config('fiscal.print.layouts')))->toBe(array_keys(FROZEN_LAYOUTS))
        ->and(FROZEN_LAYOUTS)->toHaveKey($current);
});

test('new documents print with the current layout; older ones keep theirs', function () {
    $fixture = pdfFixture();
    $prints = app(FiscalDocumentPrints::class);

    config(['fiscal.print.layout' => 'v1']);
    $older = documentWithLines($fixture, lines: 1);
    $prints->record($older);

    config(['fiscal.print.layout' => 'v2']);
    $newer = documentWithLines($fixture, lines: 1);

    // Font names sit in the PDF's uncompressed font dictionaries: v1 is set
    // in DejaVu Sans, v2 in Hanken Grotesk.
    expect($prints->pdf($older->fresh()))->toContain('DejaVuSans')->not->toContain('HankenGrotesk')
        ->and($prints->pdf($newer))->toContain('HankenGrotesk')
        ->and($prints->record($newer)->layout_version)->toBe('v2');
});

test('a customer name outside the Latin script still prints, from a face that has it', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture, lines: 1);
    DB::table('fiscal_documents')->where('id', $document->id)
        ->update(['customer_name' => '中国铁建 (Angola), Lda.']);

    expect(app(FiscalDocumentPrints::class)->pdf($document->fresh()))->toContain('Sun-ExtA');
});

test('a correction says which document it corrects and why', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture, lines: 1, type: FiscalDocumentType::CreditNote);
    DB::table('fiscal_documents')->where('id', $document->id)->update([
        'references_document_no' => 'FT 2026/417',
        'adjustment_reason' => 'Devolução de mercadoria',
    ]);
    $document = $document->fresh();
    $format = app(PrintFormat::class);

    $html = view('documents.pdf.v2.invoice', [
        'document' => app(FiscalDocumentPresenter::class)->forPrint($document, app(FiscalDocumentPrints::class)->record($document)),
        'settlements' => [],
        'logo' => null,
        'money' => fn (int $minor): string => $format->money($minor),
        'number' => fn (string $value, int $minimumDecimals = 0): string => $format->decimal($value, $minimumDecimals),
        'percent' => fn (string $value): string => $format->percent($value),
    ])->render();

    expect($html)->toContain('Documento de origem')
        ->toContain('FT 2026/417')
        ->toContain('Devolução de mercadoria');
});
