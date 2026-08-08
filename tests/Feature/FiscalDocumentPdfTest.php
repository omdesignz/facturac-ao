<?php

use App\Fiscal\Documents\FiscalDocumentPdf;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use App\Notifications\FiscalDocumentIssued;
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
