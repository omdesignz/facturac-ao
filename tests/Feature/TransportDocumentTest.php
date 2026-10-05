<?php

use App\Actions\IssueTransportDocument;
use App\Actions\SaveTransportDocumentDraft;
use App\Exceptions\BillingActionRefused;
use App\Fiscal\Documents\PdfSheet;
use App\Fiscal\Documents\TransportDocumentPdf;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\TransportDocument;
use App\Models\TransportDocumentSequence;
use App\Models\User;
use App\TransportDocumentStatus;
use App\TransportDocumentType;
use App\WorkspaceRole;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use Mockery\MockInterface;

/**
 * @return array{owner: User, legalEntity: LegalEntity, establishment: Establishment}
 */
function transportModuleFixture(): array
{
    $owner = User::factory()->withWorkspace('VAP Guias')->create();
    $owner->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('transport-secret'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['transport-recovery'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $workspace = $owner->currentWorkspace()->firstOrFail();
    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
        'legal_name' => 'VAP Logística, Lda.',
        'tax_identification_number' => '5412345678',
    ]);
    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'address_line' => 'Rua do Porto, 12',
        'municipality' => 'Luanda',
    ]);

    return compact('owner', 'legalEntity', 'establishment');
}

/** @param array<string, mixed> $overrides */
function transportModulePayload(array $fixture, array $overrides = []): array
{
    return array_replace_recursive([
        'document_type' => TransportDocumentType::DeliveryNote->value,
        'establishment_public_id' => $fixture['establishment']->public_id,
        'customer_public_id' => null,
        'movement_date' => '2026-08-13',
        'movement_start_at' => '2026-08-13 09:30',
        'movement_end_at' => '2026-08-13 12:00',
        'recipient' => [
            'name' => 'Comercial Maianga, Lda.',
            'tax_identification_number' => '5411111111',
            'country_code' => 'AO',
            'address' => 'Avenida Revolução de Outubro, 45',
            'city' => 'Luanda',
            'province' => 'Luanda',
        ],
        'origin' => [
            'address' => 'Rua do Porto, 12',
            'city' => 'Luanda',
            'province' => 'Luanda',
            'country_code' => 'AO',
        ],
        'destination' => [
            'address' => 'Avenida Revolução de Outubro, 45',
            'city' => 'Luanda',
            'province' => 'Luanda',
            'country_code' => 'AO',
        ],
        'transporter' => [
            'name' => 'Transporte VAP',
            'tax_identification_number' => '5412345678',
            'vehicle_registration' => 'LD-42-81-AA',
        ],
        'gross_weight_kg' => '125.500',
        'package_count' => 4,
        'notes' => 'Entregar no armazém principal.',
        'lines' => [[
            'catalogue_item_public_id' => null,
            'product_code' => 'CX-001',
            'product_description' => 'Caixas de consumíveis',
            'quantity' => '2.500',
            'unit_of_measure' => 'CX',
            'unit_price' => '100.00',
        ]],
    ], $overrides);
}

test('a company can save and revise a transport document draft', function () {
    $fixture = transportModuleFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('transport-documents.store'), transportModulePayload($fixture))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $document = TransportDocument::query()->sole();

    expect($document->status)->toBe(TransportDocumentStatus::Draft)
        ->and($document->revision)->toBe(1)
        ->and($document->movement_start_at->format('Y-m-d H:i'))->toBe('2026-08-13 09:30')
        ->and($document->movement_end_at?->format('Y-m-d H:i'))->toBe('2026-08-13 12:00')
        ->and($document->gross_weight_grams)->toBe(125500)
        ->and($document->lines)->toHaveCount(1)
        ->and($document->lines->first()->quantity_units)->toBe(2500)
        ->and($document->gross_total_minor)->toBe(25000);

    $this->actingAs($fixture['owner'])
        ->put(route('transport-documents.update', $document), transportModulePayload($fixture, [
            'notes' => 'Carga conferida pelo destinatário.',
            'lines' => [[
                'catalogue_item_public_id' => null,
                'product_code' => 'CX-001',
                'product_description' => 'Caixas de consumíveis',
                'quantity' => '3.000',
                'unit_of_measure' => 'CX',
                'unit_price' => '100.00',
            ]],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($document->fresh()->revision)->toBe(2)
        ->and($document->fresh()->gross_total_minor)->toBe(30000)
        ->and($document->fresh()->notes)->toBe('Carga conferida pelo destinatário.');
});

test('the register lists the company documents and hides another tenant', function () {
    $fixture = transportModuleFixture();
    $document = app(SaveTransportDocumentDraft::class)->execute(
        $fixture['legalEntity'],
        $fixture['owner'],
        transportActionProfile(transportModulePayload($fixture)),
    );
    $stranger = User::factory()->withWorkspace('Outra empresa')->create();
    $strangerWorkspace = $stranger->currentWorkspace()->firstOrFail();
    $strangerEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $strangerWorkspace->id,
    ]);
    $strangerEstablishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $strangerWorkspace->id,
        'legal_entity_id' => $strangerEntity->id,
    ]);
    $foreignFixture = [
        'owner' => $stranger,
        'legalEntity' => $strangerEntity,
        'establishment' => $strangerEstablishment,
    ];
    $foreignDocument = app(SaveTransportDocumentDraft::class)->execute(
        $strangerEntity,
        $stranger,
        transportActionProfile(transportModulePayload($foreignFixture)),
    );

    $this->actingAs($fixture['owner'])
        ->get(route('transport-documents.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('TransportDocuments/Index')
            ->where('documents.total', 1)
            ->where('documents.data.0.public_id', $document->public_id)
        );

    $this->get(route('transport-documents.edit', $foreignDocument))
        ->assertNotFound();
});

test('a consultation-only member can read guides without seeing write actions', function () {
    $fixture = transportModuleFixture();
    $document = app(SaveTransportDocumentDraft::class)->execute(
        $fixture['legalEntity'],
        $fixture['owner'],
        transportActionProfile(transportModulePayload($fixture)),
    );
    $fixture['owner']->workspaceMemberships()
        ->where('workspace_id', $fixture['legalEntity']->workspace_id)
        ->update(['role' => WorkspaceRole::Viewer->value]);

    $this->actingAs($fixture['owner'])
        ->get(route('transport-documents.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.create', false)
            ->where('documents.data.0.public_id', $document->public_id)
            ->where('documents.data.0.can_edit', false)
        );

    $this->get(route('transport-documents.create'))->assertForbidden();
});

test('issued transport documents use a sequential annual series and freeze their lines', function () {
    $fixture = transportModuleFixture();

    $this->actingAs($fixture['owner']);
    $this->post(route('transport-documents.store'), transportModulePayload($fixture));
    $first = TransportDocument::query()->firstOrFail();
    $this->post(route('transport-documents.store'), transportModulePayload($fixture, [
        'recipient' => ['name' => 'Segundo Destinatário, Lda.'],
    ]));
    $second = TransportDocument::query()->latest('id')->firstOrFail();

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('transport-documents.issue', $first), ['expected_revision' => 1])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $this->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('transport-documents.issue', $second), ['expected_revision' => 1])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $first = $first->fresh('lines');
    $second = $second->fresh();

    expect($first->status)->toBe(TransportDocumentStatus::Issued)
        ->and($first->document_no)->toBe('GR SEDE2026/1')
        ->and($second->document_no)->toBe('GR SEDE2026/2')
        ->and($first->document_hash)->toHaveLength(64)
        ->and($first->software_product_id)->toContain('/VAP SOLUÇÕES, LDA');

    expect(fn () => $first->lines->first()->update(['quantity_units' => 9000]))
        ->toThrow(DomainException::class);

    $this->put(route('transport-documents.update', $first), transportModulePayload($fixture))
        ->assertForbidden();
});

test('issuance refuses a stale browser revision without consuming a number', function () {
    $fixture = transportModuleFixture();
    $document = app(SaveTransportDocumentDraft::class)->execute(
        $fixture['legalEntity'],
        $fixture['owner'],
        transportActionProfile(transportModulePayload($fixture)),
    );

    expect(fn () => app(IssueTransportDocument::class)->execute(
        $document,
        $fixture['owner'],
        99,
    ))->toThrow(BillingActionRefused::class);

    expect($document->fresh()->document_no)->toBeNull()
        ->and(TransportDocumentSequence::query()->count())->toBe(0);
});

test('an issued guide can be cancelled but remains printable and auditable', function () {
    $fixture = transportModuleFixture();
    $draft = app(SaveTransportDocumentDraft::class)->execute(
        $fixture['legalEntity'],
        $fixture['owner'],
        transportActionProfile(transportModulePayload($fixture)),
    );
    $document = app(IssueTransportDocument::class)->execute($draft, $fixture['owner'], 1);

    $this->actingAs($fixture['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('transport-documents.cancel', $document), [
            'reason' => 'Transporte cancelado pelo destinatário',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($document->fresh()->status)->toBe(TransportDocumentStatus::Cancelled)
        ->and($document->fresh()->cancellation_reason)
        ->toBe('Transporte cancelado pelo destinatário');

    $this->actingAs($fixture['owner'])
        ->get(route('transport-documents.print', $document))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('TransportDocuments/Print')
            ->where('transportDocument.status', 'cancelled')
        );
});

test('the official guide PDF is rendered and isolated from another workspace', function () {
    $fixture = transportModuleFixture();
    $draft = app(SaveTransportDocumentDraft::class)->execute(
        $fixture['legalEntity'],
        $fixture['owner'],
        transportActionProfile(transportModulePayload($fixture)),
    );
    $document = app(IssueTransportDocument::class)->execute($draft, $fixture['owner'], 1);
    $pdf = app(TransportDocumentPdf::class)->render($document);

    expect($pdf)->toStartWith('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(5000);

    $this->actingAs($fixture['owner'])
        ->get(route('transport-documents.pdf', $document))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $stranger = User::factory()->withWorkspace('Outro transportador')->create();

    $this->actingAs($stranger)
        ->get(route('transport-documents.pdf', $document))
        ->assertForbidden();
});

test('transport input rejects an inconsistent movement date', function () {
    $fixture = transportModuleFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('transport-documents.store'), transportModulePayload($fixture, [
            'movement_start_at' => '2026-08-14 09:30',
            'movement_end_at' => '2026-08-14 12:00',
        ]))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(TransportDocument::query()->count())->toBe(0);
});

test('a return guide cannot identify a saved customer as its supplier', function () {
    $fixture = transportModuleFixture();
    $customer = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
    ]);

    $this->actingAs($fixture['owner'])
        ->post(route('transport-documents.store'), transportModulePayload($fixture, [
            'document_type' => TransportDocumentType::ReturnNote->value,
            'customer_public_id' => $customer->public_id,
        ]))
        ->assertRedirect()
        ->assertSessionHasErrors('customer_public_id');

    expect(TransportDocument::query()->count())->toBe(0);
});

/**
 * Converts the HTTP-shaped payload into the action profile used in isolated domain tests.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function transportActionProfile(array $payload): array
{
    $scaled = static function (string $value, int $scale): int {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) ($whole.substr(str_pad($fraction, $scale, '0'), 0, $scale));
    };

    return [
        ...$payload,
        'gross_weight_grams' => $scaled((string) $payload['gross_weight_kg'], 3),
        'lines' => array_map(static fn (array $line): array => [
            ...$line,
            'quantity_units' => $scaled((string) $line['quantity'], 3),
            'quantity_scale' => 3,
            'unit_price_minor' => $scaled((string) $line['unit_price'], 2),
        ], $payload['lines']),
    ];
}

test('a cancelled guide is stamped ANULADO on every page by the PDF engine', function () {
    $fixture = transportModuleFixture();
    $draft = app(SaveTransportDocumentDraft::class)->execute(
        $fixture['legalEntity'],
        $fixture['owner'],
        transportActionProfile(transportModulePayload($fixture)),
    );
    $document = app(IssueTransportDocument::class)->execute($draft, $fixture['owner'], 1);
    $watermarks = [];

    $this->mock(PdfSheet::class, function (MockInterface $sheet) use (&$watermarks): void {
        $sheet->shouldReceive('logo')->andReturnNull();
        $sheet->shouldReceive('render')->andReturnUsing(function (string $view, array $data, array $metadata) use (&$watermarks): string {
            $watermarks[] = $metadata['watermark'];

            return '%PDF-';
        });
    });

    app(TransportDocumentPdf::class)->render($document);
    $document->forceFill(['status' => TransportDocumentStatus::Cancelled])->saveQuietly();
    app(TransportDocumentPdf::class)->render($document->fresh());

    expect($watermarks)->toBe([null, 'ANULADO']);
});
