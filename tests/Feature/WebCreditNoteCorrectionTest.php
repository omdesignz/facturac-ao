<?php

use App\Actions\SignInMobileDevice;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\WithholdingType;
use App\WorkspaceRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../PosFixtures.php';

/**
 * A company with one invoice for 2 × Óleo at 100,00 and 3 × Arroz at 50,00,
 * both at 14 % IVA, issued through the usual pipeline.
 *
 * @return array<string, mixed>
 */
function correctionCompany(bool $validated = true): array
{
    $company = posCompany();
    $company['owner']->forceFill(['password' => Hash::make('a-very-good-password')])->save();
    $company['token'] = $company['owner']->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
    $company['customer'] = Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'name' => 'Mavinga & Filhos',
        'tax_identification_number' => '5417000000',
    ]);
    posSeries($company['legalEntity'], $company['establishment'], $company['connection'], [
        'series_code' => 'FTWEB',
        'document_type' => FiscalDocumentType::Invoice,
    ]);
    $company['noteSeries'] = posSeries($company['legalEntity'], $company['establishment'], $company['connection'], [
        'series_code' => 'NCWEB',
        'document_type' => FiscalDocumentType::CreditNote,
    ]);
    $oil = posItem($company, ['code' => 'OLEO', 'name' => 'Óleo']);
    $rice = posItem($company, ['code' => 'ARROZ', 'name' => 'Arroz', 'description' => 'Arroz', 'unit_price_minor' => 5_000]);

    test()->withToken($company['token'])
        ->postJson(route('mobile.v1.invoices.store'), [
            'client_key' => (string) Str::uuid(),
            'customer_public_id' => $company['customer']->public_id,
            'lines' => [
                ['catalogue_item_public_id' => $oil->public_id, 'quantity' => '2'],
                ['catalogue_item_public_id' => $rice->public_id, 'quantity' => '3'],
            ],
            'expected_total_minor' => 22_800 + 17_100,
            'issue' => true,
            'password' => 'a-very-good-password',
        ])
        ->assertCreated();
    app('auth')->forgetGuards();

    $company['invoice'] = FiscalDocument::query()->where('document_type', FiscalDocumentType::Invoice)->sole();

    if ($validated) {
        recordAuthoritativeAgtAcceptance($company['invoice']);
    }

    return $company;
}

/**
 * An earlier note, issued from the phone, that takes all the oil and one rice.
 *
 * @param  array<string, mixed>  $company
 */
function creditOilAndOneRice(array $company): void
{
    test()->withToken($company['token'])
        ->postJson(route('mobile.v1.credit-notes.store', $company['invoice']->public_id), [
            'client_key' => (string) Str::uuid(),
            'reason' => 'Devolução de mercadoria',
            'lines' => [
                ['line_number' => 1, 'quantity' => '2'],
                ['line_number' => 2, 'quantity' => '1'],
            ],
            'expected_total_minor' => 22_800 + 5_700,
            'password' => 'a-very-good-password',
        ])
        ->assertCreated();
    app('auth')->forgetGuards();
}

/**
 * The draft the guided page would save: its lines exactly as pre-filled.
 *
 * @param  array<string, mixed>  $company
 * @param  list<array{reference_line_number: int, quantity: string, product_code: string, product_description: string, unit_price: string}>  $lines
 * @return array<string, mixed>
 */
function guidedNotePayload(array $company, array $lines): array
{
    return [
        'document_type' => FiscalDocumentType::CreditNote->value,
        'document_date' => now('Africa/Luanda')->toDateString(),
        'due_date' => null,
        'currency_code' => 'AOA',
        'establishment_public_id' => $company['establishment']->public_id,
        'customer_public_id' => $company['customer']->public_id,
        'customer' => [
            'name' => $company['customer']->name,
            'tax_identification_number' => $company['customer']->tax_identification_number,
            'country_code' => 'AO',
            'address_line' => '',
        ],
        'notes' => null,
        'references_document_public_id' => $company['invoice']->public_id,
        'adjustment_reason' => 'Devolução de mercadoria',
        'lines' => array_map(fn (array $line): array => [
            ...$line,
            'operation_type' => 'TB',
            'unit_of_measure' => 'UN',
            'discount_percentage' => '0',
            'tax' => ['type' => 'IVA', 'code' => 'NOR', 'percentage' => '14', 'exemption_code' => null],
        ], $lines),
    ];
}

beforeEach(function () {
    Queue::fake();
});

test('the document panel offers a credit note for a validated invoice', function () {
    $company = correctionCompany();

    $this->actingAs($company['owner'])
        ->get(route('documents.index', ['documento' => $company['invoice']->public_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected.public_id', $company['invoice']->public_id)
            ->where('selected.credit_note.available', true)
            ->where('selected.credit_note.reason', null)
        );
});

test('the document panel says why a credit note cannot be drafted from it', function (Closure $arrange, string $reason) {
    $company = correctionCompany(validated: false);
    $arrange($company);

    $this->actingAs($company['owner'])
        ->get(route('documents.index', ['documento' => $company['invoice']->public_id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected.credit_note.available', false)
            ->where('selected.credit_note.reason', $reason)
        );

    $this->get(route('invoices.create', ['corrigir' => $company['invoice']->public_id]))
        ->assertRedirect(route('documents.index', ['documento' => $company['invoice']->public_id]))
        ->assertSessionHas('error', $reason);
})->with([
    'not yet validated by the AGT' => [
        fn (array $company) => null,
        'A AGT ainda não validou este documento. Poderá corrigi-lo quando estiver válido.',
    ],
    'with withholdings' => [
        function (array $company): void {
            recordAuthoritativeAgtAcceptance($company['invoice']);
            DB::table('fiscal_document_withholdings')->insert([
                'workspace_id' => $company['invoice']->workspace_id,
                'legal_entity_id' => $company['invoice']->legal_entity_id,
                'fiscal_document_id' => $company['invoice']->id,
                'withholding_type' => WithholdingType::IncomeTax->value,
                'base_minor' => 35_000,
                'rate_basis_points' => 650,
                'amount_minor' => 2_275,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        },
        'Este documento tem moeda estrangeira ou retenções. Faça a nota de crédito à mão, em Novo documento.',
    ],
    'in a foreign currency' => [
        function (array $company): void {
            recordAuthoritativeAgtAcceptance($company['invoice']);
            $company['legalEntity']->forceFill(['currency_code' => 'USD'])->saveQuietly();
        },
        'Este documento tem moeda estrangeira ou retenções. Faça a nota de crédito à mão, em Novo documento.',
    ],
    'already credited in full' => [
        function (array $company): void {
            recordAuthoritativeAgtAcceptance($company['invoice']);
            creditOilAndOneRice($company);
            test()->withToken($company['token'])
                ->postJson(route('mobile.v1.credit-notes.store', $company['invoice']->public_id), [
                    'client_key' => (string) Str::uuid(),
                    'reason' => 'Devolução do resto',
                    'lines' => [['line_number' => 2, 'quantity' => '2']],
                    'expected_total_minor' => 11_400,
                    'password' => 'a-very-good-password',
                ])
                ->assertCreated();
            app('auth')->forgetGuards();
        },
        'Este documento já foi creditado por inteiro.',
    ],
]);

test('only an invoice or invoice-receipt is offered the guided note', function () {
    $company = correctionCompany();
    creditOilAndOneRice($company);
    $note = FiscalDocument::query()->where('document_type', FiscalDocumentType::CreditNote)->sole();

    $this->actingAs($company['owner'])
        ->get(route('documents.index', ['documento' => $note->public_id]))
        ->assertInertia(fn (Assert $page) => $page->where('selected.credit_note', null));
});

test('a viewer is offered no credit note and cannot open one', function () {
    $company = correctionCompany();
    $viewer = posMember($company, WorkspaceRole::Viewer);

    $this->actingAs($viewer)
        ->get(route('documents.index', ['documento' => $company['invoice']->public_id]))
        ->assertInertia(fn (Assert $page) => $page->where('selected.credit_note', null));

    $this->get(route('invoices.create', ['corrigir' => $company['invoice']->public_id]))
        ->assertForbidden();
});

test('the note opens drafted from the invoice, each line at what is left of it', function () {
    $company = correctionCompany();
    creditOilAndOneRice($company);

    $this->actingAs($company['owner'])
        ->get(route('invoices.create', ['corrigir' => $company['invoice']->public_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Create')
            ->where('document.public_id', null)
            ->where('document.document_type', 'NC')
            ->where('document.references_document_public_id', $company['invoice']->public_id)
            ->where('document.references_document_no', $company['invoice']->document_no)
            ->where('document.currency_code', 'AOA')
            ->where('document.due_date', null)
            ->where('document.establishment_public_id', $company['establishment']->public_id)
            ->where('document.customer_public_id', $company['customer']->public_id)
            ->where('document.customer.name', 'Mavinga & Filhos')
            ->where('document.adjustment_reason', '')
            // The oil is gone; only the two rice left come pre-filled.
            ->has('document.lines', 1)
            ->where('document.lines.0.reference_line_number', '2')
            ->where('document.lines.0.product_code', 'ARROZ')
            ->where('document.lines.0.product_description', 'Arroz')
            ->where('document.lines.0.quantity', '2')
            ->where('document.lines.0.unit_of_measure', 'UN')
            ->where('document.lines.0.unit_price', '50')
            ->where('document.lines.0.discount_percentage', '0')
            ->where('document.lines.0.tax_treatment', 'IVA_NOR_14')
            ->where('creditGuide.source.document_no', $company['invoice']->document_no)
            ->has('creditGuide.lines', 2)
            ->where('creditGuide.lines.0.line_number', 1)
            ->where('creditGuide.lines.0.quantity', '2')
            ->where('creditGuide.lines.0.credited_quantity', '2')
            ->where('creditGuide.lines.0.creditable_quantity', '0')
            ->where('creditGuide.lines.1.line_number', 2)
            ->where('creditGuide.lines.1.quantity', '3')
            ->where('creditGuide.lines.1.credited_quantity', '1')
            ->where('creditGuide.lines.1.creditable_quantity', '2')
        );
});

test('a credit note opened from the blank form has no guide', function () {
    $company = correctionCompany();

    $this->actingAs($company['owner'])
        ->get(route('invoices.create', ['type' => 'NC']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('creditGuide', null));
});

test('another company\'s invoice cannot be corrected', function () {
    $company = correctionCompany();
    $other = posMember(correctionCompanyElsewhere(), WorkspaceRole::Owner);

    $this->actingAs($other)
        ->get(route('invoices.create', ['corrigir' => $company['invoice']->public_id]))
        ->assertNotFound();
});

test('the pre-filled note saves and issues on the usual path, password included', function () {
    $company = correctionCompany();
    $payload = guidedNotePayload($company, [
        ['reference_line_number' => 2, 'product_code' => 'ARROZ', 'product_description' => 'Arroz', 'quantity' => '1', 'unit_price' => '50'],
    ]);

    $this->actingAs($company['owner'])->post(route('invoices.store'), $payload)->assertSessionHasNoErrors();
    $draft = FiscalDocument::query()->where('document_type', FiscalDocumentType::CreditNote)->sole();

    $this->post(route('invoices.issue', $draft), [
        'revision' => $draft->revision,
        'series_public_id' => $company['noteSeries']->public_id,
    ])->assertRedirect(route('password.confirm'));
    expect($draft->fresh()->status)->toBe(FiscalDocumentStatus::Draft);

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('invoices.issue', $draft), [
            'revision' => $draft->revision,
            'series_public_id' => $company['noteSeries']->public_id,
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $note = $draft->fresh();

    expect($note->status)->toBe(FiscalDocumentStatus::Issued)
        ->and($note->references_document_id)->toBe($company['invoice']->id)
        ->and($note->gross_total_minor)->toBe(5_700)
        ->and($note->lines()->sole()->reference_line_number)->toBe(2);
});

test('the pre-filled note still cannot credit past what is left', function () {
    $company = correctionCompany();
    creditOilAndOneRice($company);
    $payload = guidedNotePayload($company, [
        ['reference_line_number' => 2, 'product_code' => 'ARROZ', 'product_description' => 'Arroz', 'quantity' => '3', 'unit_price' => '50'],
    ]);

    $this->actingAs($company['owner'])->post(route('invoices.store'), $payload)
        ->assertSessionHasErrors(['lines.0.quantity' => 'Só pode creditar até 2 UN de Arroz.']);
});

test('a guided draft reopens guided, its lines back on the invoice\'s terms', function () {
    $company = correctionCompany();
    $payload = guidedNotePayload($company, [
        ['reference_line_number' => 2, 'product_code' => 'ARROZ', 'product_description' => 'Arroz', 'quantity' => '1.5', 'unit_price' => '50'],
    ]);
    $this->actingAs($company['owner'])->post(route('invoices.store'), $payload)->assertSessionHasNoErrors();
    $draft = FiscalDocument::query()->where('document_type', FiscalDocumentType::CreditNote)->sole();

    $this->get(route('invoices.edit', $draft))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('creditGuide.source.public_id', $company['invoice']->public_id)
            // Drafts take nothing: the whole of each line is still there.
            ->where('creditGuide.lines.1.creditable_quantity', '3')
            ->has('document.lines', 1)
            ->where('document.lines.0.reference_line_number', '2')
            ->where('document.lines.0.quantity', '1.5')
            ->where('document.lines.0.unit_price', '50')
        );
});

test('a credit note written by hand reopens as it was written', function () {
    $company = correctionCompany();
    $payload = guidedNotePayload($company, [
        ['reference_line_number' => 2, 'product_code' => 'ARROZ', 'product_description' => 'Arroz', 'quantity' => '1', 'unit_price' => '50'],
        ['reference_line_number' => 2, 'product_code' => 'ARROZ', 'product_description' => 'Arroz', 'quantity' => '1', 'unit_price' => '50'],
    ]);
    $this->actingAs($company['owner'])->post(route('invoices.store'), $payload)->assertSessionHasNoErrors();
    $draft = FiscalDocument::query()->where('document_type', FiscalDocumentType::CreditNote)->sole();

    $this->get(route('invoices.edit', $draft))
        ->assertInertia(fn (Assert $page) => $page
            ->where('creditGuide', null)
            ->has('document.lines', 2)
        );
});

test('the phone keeps its own wording for the same rules', function () {
    $company = correctionCompany(validated: false);

    $this->withToken($company['token'])->getJson(route('mobile.v1.credit-notes.show', $company['invoice']->public_id))
        ->assertJsonPath('data.can_issue', false)
        ->assertJsonPath('data.reason', 'A AGT ainda não validou este documento. Poderá corrigi-lo quando estiver válido.');
});

/**
 * A second, unrelated company, for scoping.
 *
 * @return array<string, mixed>
 */
function correctionCompanyElsewhere(): array
{
    return posCompany();
}
