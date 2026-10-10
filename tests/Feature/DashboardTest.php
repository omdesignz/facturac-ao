<?php

use App\AgtSubmissionStatus;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\AgtSubmission;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, legalEntity: LegalEntity, establishment: Establishment}
 */
function dashboardFixture(): array
{
    $owner = User::factory()->withWorkspace('VAP Painel')->create();
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

/**
 * @param  array{owner: User, legalEntity: LegalEntity, establishment: Establishment}  $fixture
 * @param  array<string, mixed>  $attributes
 */
function dashboardDocument(array $fixture, array $attributes = []): FiscalDocument
{
    return FiscalDocument::factory()->issued()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'document_type' => FiscalDocumentType::Invoice,
        // Numbers are unique per workspace, as they are in a real series.
        'document_no' => 'FT TESTE/'.fake()->unique()->numberBetween(1, 99_999),
        'customer_name' => 'Mavinga & Filhos, Lda.',
        'gross_total_minor' => 136_800_000,
        'tax_payable_minor' => 16_800_000,
        'net_total_minor' => 120_000_000,
        ...$attributes,
    ]);
}

test('a workspace that has not set up its company sees the checklist and no figures', function () {
    $owner = User::factory()->withWorkspace('VAP Sem Empresa')->create();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('companyReadiness.configured', false)
            ->where('kpis', null)
            ->where('focus', null)
            ->where('review', null)
            ->has('now')
        );
});

test('a configured company with nothing issued gets zeros and no focus document', function () {
    $fixture = dashboardFixture();

    $this->actingAs($fixture['owner'])
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('kpis.issued_today', 0)
            ->where('kpis.billed_today_minor', 0)
            ->where('kpis.outstanding_minor', 0)
            ->where('focus', null)
            ->where('review.attention_count', 0)
            ->where('review.overdue_count', 0)
        );
});

test('today is billed from issued documents only and compared with the same weekday last week', function () {
    $fixture = dashboardFixture();
    $today = CarbonImmutable::now();

    dashboardDocument($fixture, ['document_date' => $today->toDateString(), 'gross_total_minor' => 50_000_000]);
    dashboardDocument($fixture, ['document_date' => $today->subWeek()->toDateString(), 'gross_total_minor' => 40_000_000]);

    // A draft is not revenue: nobody has been asked for that money yet.
    FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'document_date' => $today->toDateString(),
        'gross_total_minor' => 99_000_000,
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('kpis.billed_today_minor', 50_000_000)
            ->where('kpis.billed_last_week_minor', 40_000_000)
            ->where('kpis.issued_today', 1)
            ->where('review.drafts_open', 1)
        );
});

test('a document the AGT marked invalid leads the page with the AGT explanation', function () {
    $fixture = dashboardFixture();

    // An overdue invoice is urgent too, but an invalid document comes first.
    dashboardDocument($fixture, [
        'document_no' => 'FT 2026/90',
        'document_date' => now()->subDays(40)->toDateString(),
        'due_date' => now()->subDays(10)->toDateString(),
    ]);

    $invalid = dashboardDocument($fixture, [
        'document_no' => 'FT 2026/148',
        'status' => FiscalDocumentStatus::Invalid,
    ]);

    AgtSubmission::factory()->create([
        'fiscal_document_id' => $invalid->id,
        'status' => AgtSubmissionStatus::Invalid,
        'safe_message' => 'O NIF do adquirente não foi reconhecido.',
        'last_error_codes' => ['E-104'],
        'submitted_at' => now()->subMinutes(3),
        'failed_at' => now()->subMinutes(2),
    ]);
    recordAuthoritativeAgtAcceptance($invalid, 'I');

    $this->actingAs($fixture['owner'])
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('focus.reason', 'invalid')
            ->where('focus.document_no', 'FT 2026/148')
            ->where('focus.agt_message', 'A AGT reportou o documento inválido na data indicada.')
            ->where('focus.agt_error_codes', [])
            ->where('focus.steps.3.key', 'agt')
            ->where('focus.steps.3.state', 'error')
            ->where('kpis.attention_count', 2)
            ->where('review.attention_count', 2)
        );
});

test('without an AGT problem the most overdue invoice leads, with how late it is', function () {
    $fixture = dashboardFixture();

    dashboardDocument($fixture, [
        'document_no' => 'FT 2026/90',
        'status' => FiscalDocumentStatus::Valid,
        'document_date' => now()->subDays(40)->toDateString(),
        'due_date' => now()->subDays(10)->toDateString(),
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('focus.reason', 'overdue')
            ->where('focus.document_no', 'FT 2026/90')
            ->where('focus.days_past_due', 10)
            ->where('focus.outstanding_minor', 136_800_000)
            ->where('focus.steps.4.key', 'paid')
            ->where('focus.steps.4.state', 'error')
            ->where('kpis.overdue_count', 1)
        );
});

test('on a quiet day the latest document leads and an invoice-receipt counts as paid', function () {
    $fixture = dashboardFixture();

    $document = dashboardDocument($fixture, [
        'document_no' => 'FR 2026/22',
        'document_type' => FiscalDocumentType::InvoiceReceipt,
        'status' => FiscalDocumentStatus::Valid,
    ]);

    recordAuthoritativeAgtAcceptance($document);

    $this->actingAs($fixture['owner'])
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('focus.reason', 'latest')
            ->where('focus.document_no', 'FR 2026/22')
            ->where('focus.agt_message', 'A AGT reportou validação na data indicada.')
            ->where('focus.steps.4.state', 'done')
            ->where('kpis.valid_today', 1)
        );
});

test('collections arrive after first paint, oldest debt first', function () {
    $fixture = dashboardFixture();

    $customer = fn (string $name): int => Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'name' => $name,
    ])->id;

    dashboardDocument($fixture, [
        'customer_id' => $customer('Hotel Baía Azul'),
        'customer_name' => 'Hotel Baía Azul',
        'document_date' => now()->subDays(60)->toDateString(),
        'due_date' => now()->subDays(30)->toDateString(),
        'gross_total_minor' => 98_000_000,
    ]);
    dashboardDocument($fixture, [
        'customer_id' => $customer('Escola Ngola Kiluanje'),
        'customer_name' => 'Escola Ngola Kiluanje',
        'due_date' => now()->addDays(20)->toDateString(),
        'gross_total_minor' => 121_000_000,
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('collections')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('collections.customer_count', 2)
                ->where('collections.outstanding_minor', 219_000_000)
                ->where('collections.customers.0.name', 'Hotel Baía Azul')
                ->where('collections.customers.0.oldest_days_past_due', 30)
            )
        );
});
