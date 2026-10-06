<?php

use App\Fiscal\Documents\ArchivedPdfCompromised;
use App\Fiscal\Documents\FiscalDocumentArchive;
use App\Fiscal\Documents\FiscalDocumentPdf;
use App\Fiscal\Documents\FiscalDocumentPresenter;
use App\Fiscal\Documents\PrintFormat;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Jobs\ArchiveFiscalDocumentPdf;
use App\Models\ArchivedPdf;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use App\Notifications\FiscalDocumentIssued;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

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
    $pdf = app(FiscalDocumentPdf::class)->render(documentWithLines($fixture));

    expect($pdf)->toStartWith('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(5_000)
        ->and(pageCount($pdf))->toBe(1);
});

test('line items flow onto further pages rather than being cut off', function () {
    $fixture = pdfFixture();

    // The reason for a PDF engine rather than a print stylesheet.
    $pdf = app(FiscalDocumentPdf::class)->render(documentWithLines($fixture, lines: 60));

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
    $pdf = app(FiscalDocumentPdf::class)->render(documentWithLines($fixture, $lines));

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
    $pdf = app(FiscalDocumentPdf::class)->render(
        documentWithLines($fixture, lines: 1, type: FiscalDocumentType::Receipt),
    );

    expect(drawnImageSizes($pdf))->toHaveCount(1)
        ->and(drawnImageSizes($pdf)[0][0])->toBe(drawnImageSizes($pdf)[0][1]);
});

test('the PDF says what made it and keeps its scratch files off the private disk', function () {
    $fixture = pdfFixture();
    $pdf = app(FiscalDocumentPdf::class)->render(documentWithLines($fixture));

    // mPDF writes metadata as UTF-16 text strings.
    $creator = "\xFE\xFF".mb_convert_encoding((string) config('app.name'), 'UTF-16BE', 'UTF-8');

    expect($pdf)->toContain('/Creator ('.$creator.')')
        ->and(is_dir(storage_path('framework/cache/mpdf')))->toBeTrue();
});

test('figures print the Angolan way, not as machine decimals', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture, lines: 1);

    $html = view('documents.pdf.invoice', [
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

    expect(app(FiscalDocumentPdf::class)->render($receipt))->toStartWith('%PDF-');
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

    $document = documentWithLines($fixture);
    $withLogo = app(FiscalDocumentPdf::class)->render($document->fresh());

    $fixture['legalEntity']->forceFill(['logo_path' => null])->save();
    $withoutLogo = app(FiscalDocumentPdf::class)->render($document->fresh());

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

// ---------------------------------------------------------------- archive

/** The disk issued PDFs are archived to, faked for every test in TestCase. */
function archiveDisk(): Filesystem
{
    return Storage::disk((string) config('fiscal.print.archive_disk'));
}

test('the first request keeps the PDF and later ones serve those exact bytes', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);
    $archive = app(FiscalDocumentArchive::class);

    $first = $archive->pdf($document);

    // A later change to the company would show in a fresh render. The
    // archived copy must not move.
    $fixture['legalEntity']->forceFill(['legal_name' => 'Nome Novo, Lda.'])->saveQuietly();
    $second = $archive->pdf($document->fresh());

    $record = ArchivedPdf::query()->sole();

    expect($second)->toBe($first)
        ->and($record->fiscal_document_id)->toBe($document->id)
        ->and($record->sha256)->toBe(hash('sha256', $first))
        ->and($record->byte_size)->toBe(strlen($first))
        ->and($record->renderer)->toStartWith('mPDF ')
        ->and($record->path)->toStartWith("fiscal-documents/{$document->workspace_id}/{$document->legal_entity_id}/")
        ->and(archiveDisk()->get($record->path))->toBe($first);
});

test('storing twice keeps one record and one file', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);
    $archive = app(FiscalDocumentArchive::class);

    $first = $archive->store($document);
    $second = $archive->store($document->fresh());

    expect($second->is($first))->toBeTrue()
        ->and(ArchivedPdf::query()->count())->toBe(1)
        ->and(archiveDisk()->allFiles('fiscal-documents'))->toHaveCount(1);
});

test('the PDF route serves the archived copy with its hash as the entity tag', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);
    $stored = app(FiscalDocumentArchive::class)->pdf($document);

    $response = $this->actingAs($fixture['owner'])
        ->get(route('documents.pdf', $document))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('ETag', '"'.hash('sha256', $stored).'"')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->getContent())->toBe($stored);
});

test('an archived copy that no longer matches its hash is refused, never re-rendered', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);
    $record = app(FiscalDocumentArchive::class)->store($document);

    archiveDisk()->put($record->path, '%PDF-1.4 altered');

    $this->withoutExceptionHandling();

    expect(fn () => app(FiscalDocumentArchive::class)->pdf($document->fresh()))
        ->toThrow(ArchivedPdfCompromised::class)
        ->and(ArchivedPdf::query()->sole()->sha256)->toBe($record->sha256);
});

test('a missing archived file is refused rather than replaced', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);
    $record = app(FiscalDocumentArchive::class)->store($document);

    archiveDisk()->delete($record->path);

    $this->actingAs($fixture['owner'])
        ->get(route('documents.pdf', $document))
        ->assertServerError();

    expect(archiveDisk()->exists($record->path))->toBeFalse();
});

test('an archived PDF record can be neither changed nor deleted', function () {
    $record = ArchivedPdf::factory()->create();

    expect(fn () => $record->update(['sha256' => str_repeat('0', 64)]))->toThrow(DomainException::class)
        ->and(fn () => $record->delete())->toThrow(DomainException::class);
});

test('a draft has nothing to archive', function () {
    $fixture = pdfFixture();
    $draft = FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'status' => FiscalDocumentStatus::Draft,
        'document_no' => null,
    ]);

    app(FiscalDocumentArchive::class)->store($draft);
})->throws(LogicException::class);

test('the archive job keeps the PDF of the document it was queued for', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);

    (new ArchiveFiscalDocumentPdf($document->id))->handle(app(FiscalDocumentArchive::class));

    expect($document->archivedPdf()->exists())->toBeTrue();
});

test('the backfill archives every issued document that has no PDF yet', function () {
    $fixture = pdfFixture();
    $archived = documentWithLines($fixture, lines: 1);
    app(FiscalDocumentArchive::class)->store($archived);
    documentWithLines($fixture, lines: 1);
    documentWithLines($fixture, lines: 1);

    $this->artisan('fiscal:archive-pdfs', ['--dry-run' => true])
        ->expectsOutputToContain('2 documento(s) sem PDF arquivado.')
        ->assertSuccessful();

    expect(ArchivedPdf::query()->count())->toBe(1);

    $this->artisan('fiscal:archive-pdfs')->assertSuccessful();

    expect(ArchivedPdf::query()->count())->toBe(3);
});

test('the emailed attachment is byte for byte the archived copy', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);
    $stored = app(FiscalDocumentArchive::class)->pdf($document);

    $mail = FiscalDocumentIssued::fromModel($document)->toMail((object) ['email' => 'cliente@exemplo.ao']);

    expect($mail->rawAttachments[0]['data'])->toBe($stored);
});
