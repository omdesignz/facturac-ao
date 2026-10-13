<?php

use App\Analytics\TransactionReport;
use App\Analytics\TransactionWorkbook;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Jobs\GenerateTransactionExport;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\LegalEntity;
use App\Models\TransactionExport;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function transactionFixture(): array
{
    $user = User::factory()->withWorkspace()->create();
    $company = LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);
    $establishment = Establishment::factory()->headOffice()->create(['workspace_id' => $company->workspace_id, 'legal_entity_id' => $company->id]);

    return compact('user', 'company', 'establishment');
}

function transactionDocument(array $fixture, array $attributes = []): FiscalDocument
{
    return FiscalDocument::factory()->create([
        'workspace_id' => $fixture['company']->workspace_id,
        'legal_entity_id' => $fixture['company']->id,
        'establishment_id' => $fixture['establishment']->id,
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Valid,
        'document_date' => '2026-10-13',
        'document_no' => 'FT TEST/'.Str::ulid(),
        'customer_name' => '=HYPERLINK("https://example.com","Cliente")',
        'customer_tax_identification_number' => '0012345678',
        'net_total_minor' => 10000, 'tax_payable_minor' => 1400, 'gross_total_minor' => 11400,
        'payment_amount_minor' => null,
        ...$attributes,
    ]);
}

function transactionFilters(array $overrides = []): array
{
    return ['title' => 'Fecho mensal', 'from' => '2026-10-01', 'to' => '2026-10-31',
        'columns' => TransactionReport::DEFAULT_COLUMNS, 'types' => [], 'statuses' => [],
        'include_lines' => false, 'include_payments' => false, ...$overrides];
}

test('transaction reports require authentication and active workspace access', function () {
    $this->get(route('transaction-reports.index'))->assertRedirect(route('login'));
    $fixture = transactionFixture();
    $this->actingAs($fixture['user'])->get(route('transaction-reports.index'))
        ->assertInertia(fn (Assert $page) => $page->component('Analytics/Export')->where('companyName', $fixture['company']->legal_name)->has('columns.document_no'));
    DB::table('workspace_memberships')->where('user_id', $fixture['user']->id)->update(['is_active' => false]);
    $this->postJson(route('transaction-reports.download', transactionFilters()))->assertRedirect();
});

test('preview and export scope to company and include the complete final day but no drafts', function () {
    $fixture = transactionFixture();
    transactionDocument($fixture, ['document_date' => '2026-10-31 23:59:59']);
    transactionDocument($fixture, ['status' => FiscalDocumentStatus::Draft]);
    transactionDocument($fixture, ['document_date' => '2026-11-01']);
    transactionDocument(transactionFixture());
    $this->actingAs($fixture['user'])->getJson(route('transaction-reports.preview', transactionFilters()))
        ->assertOk()->assertJsonPath('count', 1)->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.customer_nif', '0012345678');
    $book = app(TransactionWorkbook::class)->build($fixture['company'], transactionFilters());
    expect($book->getSheetByName('Transacções')->getHighestRow())->toBe(7);
    $book->disconnectWorksheets();
});

test('combined filters select the same rows as the workbook', function () {
    $fixture = transactionFixture();
    transactionDocument($fixture, ['document_type' => FiscalDocumentType::InvoiceReceipt, 'currency_code' => 'USD', 'payment_method' => PaymentMethod::BankTransfer, 'customer_name' => 'Cliente Alfa']);
    transactionDocument($fixture);
    $filters = transactionFilters(['types' => ['FR'], 'statuses' => ['valid'], 'customer' => 'alfa', 'currency' => 'USD', 'payment_method' => 'TB', 'establishment' => $fixture['establishment']->public_id]);
    $this->actingAs($fixture['user'])->getJson(route('transaction-reports.preview', $filters))->assertOk()->assertJsonPath('count', 1);
    $book = app(TransactionWorkbook::class)->build($fixture['company'], $filters);
    expect($book->getSheetByName('Transacções')->getCell('G7')->getValue())->toBe('USD');
    $book->disconnectWorksheets();
});

test('exports reject invalid and foreign filters without leaking data', function (array $override, string $error) {
    $fixture = transactionFixture();
    $this->actingAs($fixture['user'])->postJson(route('transaction-reports.download', transactionFilters($override)))
        ->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    [['to' => '2026-09-30'], 'to'],
    [['from' => '2026-02-31'], 'from'],
    [['columns' => ['document_no', 'document_date', 'currency_code', 'document_jws']], 'columns.3'],
    [['columns' => ['document_no', 'document_date', 'gross']], 'columns'],
    [['columns' => ['document_no', 'document_date', 'currency_code', 'document_no']], 'columns.0'],
    [['statuses' => ['draft']], 'statuses.0'],
    [['types' => ['NOT_A_TYPE']], 'types.0'],
    [['establishment' => 'another-company'], 'establishment'],
]);

test('download is a real editable Excel file with numeric amounts dates safe text and custom column order', function () {
    $fixture = transactionFixture();
    transactionDocument($fixture);
    $columns = ['customer_name', 'customer_nif', 'gross', 'document_no', 'document_date', 'currency_code'];
    $response = $this->actingAs($fixture['user'])->post(route('transaction-reports.download', transactionFilters(['columns' => $columns])));
    $response->assertOk()->assertDownload('transaccoes-2026-10-01-2026-10-31.xlsx');
    $path = $response->baseResponse->getFile()->getPathname();
    try {
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName('Transacções');
        expect($sheet->getCell('A6')->getValue())->toBe('Cliente')
            ->and($sheet->getCell('A7')->getDataType())->toBe(DataType::TYPE_STRING)
            ->and($sheet->getCell('A7')->getValue())->toStartWith('=HYPERLINK')
            ->and($sheet->getCell('B7')->getValue())->toBe('0012345678')
            ->and($sheet->getCell('C7')->getValue())->toEqual(114)
            ->and($sheet->getCell('C7')->getDataType())->toBe(DataType::TYPE_NUMERIC)
            ->and($sheet->getCell('E7')->getValue())->toEqual(Date::stringToExcel('2026-10-13'))
            ->and($sheet->getFreezePane())->toBe('C7')
            ->and($sheet->getTableCollection())->toHaveCount(1)
            ->and($book->getSheetNames())->toBe(['Resumo', 'Transacções', 'Por mês']);
        $book->disconnectWorksheets();
    } finally {
        @unlink($path);
    }
});

test('summary separates currencies and counts receipts once while credit notes reduce billing', function () {
    $fixture = transactionFixture();
    $invoice = transactionDocument($fixture);
    $receipt = transactionDocument($fixture, ['document_type' => FiscalDocumentType::Receipt, 'gross_total_minor' => 0, 'payment_amount_minor' => 4000]);
    $receipt->settlements()->create(['workspace_id' => $invoice->workspace_id, 'legal_entity_id' => $invoice->legal_entity_id, 'settled_document_id' => $invoice->id, 'settled_document_no' => $invoice->document_no, 'amount_minor' => 4000]);
    transactionDocument($fixture, ['document_type' => FiscalDocumentType::CreditNote, 'gross_total_minor' => 2000, 'references_document_id' => $invoice->id]);
    transactionDocument($fixture, ['document_type' => FiscalDocumentType::InvoiceReceipt, 'gross_total_minor' => 5000, 'payment_amount_minor' => 5000]);
    transactionDocument($fixture, ['currency_code' => 'USD', 'gross_total_minor' => 1200]);
    $book = app(TransactionWorkbook::class)->build($fixture['company'], transactionFilters(['include_payments' => true]));
    $monthly = $book->getSheetByName('Por mês');
    expect($monthly->getCell('B7')->getValue())->toBe('AOA')
        ->and($monthly->getCell('D7')->getValue())->toEqual(144)
        ->and($monthly->getCell('E7')->getValue())->toEqual(90)
        ->and($monthly->getCell('B8')->getValue())->toBe('USD')
        ->and($monthly->getCell('D8')->getValue())->toEqual(12)
        ->and($book->getSheetByName('Transacções')->getCell('M7')->getValue())->toEqual(54)
        ->and($book->getSheetByName('Liquidações')->getCell('G7')->getValue())->toEqual(40);
    $book->disconnectWorksheets();
});

test('line export preserves fractional quantities and tax amounts', function () {
    $fixture = transactionFixture();
    $document = transactionDocument($fixture, ['status' => FiscalDocumentStatus::Draft]);
    $line = $document->lines()->create([
        'workspace_id' => $document->workspace_id, 'legal_entity_id' => $document->legal_entity_id,
        'line_number' => 1, 'operation_type' => 'SG', 'product_code' => '000123',
        'product_description' => '+SUM(1,2)', 'quantity_units' => 12345, 'quantity_scale' => 4,
        'unit_of_measure' => 'UN', 'unit_price_base_minor' => 10000, 'unit_price_micros' => 10000000000,
        'discount_rate_basis_points' => 0, 'base_amount_minor' => 12345, 'settlement_amount_minor' => 0,
        'net_amount_minor' => 12345, 'tax_amount_minor' => 1728, 'gross_amount_minor' => 14073,
    ]);
    $line->taxes()->create(['workspace_id' => $document->workspace_id, 'legal_entity_id' => $document->legal_entity_id,
        'fiscal_document_id' => $document->id, 'tax_type' => 'IVA', 'tax_country_region' => 'AO', 'tax_code' => 'NOR', 'tax_rate_basis_points' => 1400, 'tax_contribution_minor' => 1728]);
    $document->update(['status' => FiscalDocumentStatus::Valid]);
    $book = app(TransactionWorkbook::class)->build($fixture['company'], transactionFilters(['include_lines' => true]));
    $sheet = $book->getSheetByName('Artigos');
    expect($sheet->getCell('F7')->getValue())->toBe('000123')
        ->and($sheet->getCell('G7')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('H7')->getValue())->toEqual(1.2345)
        ->and($sheet->getCell('J7')->getValue())->toEqual(100)
        ->and($sheet->getCell('M7')->getValue())->toEqual(17.28)
        ->and($sheet->getCell('O7')->getValue())->toBe('IVA NOR 14%');
    $book->disconnectWorksheets();
});

test('empty report remains a readable valid workbook', function () {
    $fixture = transactionFixture();
    $book = app(TransactionWorkbook::class)->build($fixture['company'], transactionFilters(['include_lines' => true, 'include_payments' => true]));
    expect($book->getSheetCount())->toBe(5)
        ->and($book->getSheetByName('Transacções')->getCell('A7')->getValue())->toContain('Sem movimentos')
        ->and($book->getSheetByName('Transacções')->getTableCollection())->toHaveCount(0);
    $path = tempnam(sys_get_temp_dir(), 'empty-report-');
    try {
        (new Xlsx($book))->save($path);
        $reopened = IOFactory::load($path);
        expect($reopened->getSheetCount())->toBe(5);
        $reopened->disconnectWorksheets();
    } finally {
        unlink($path);
        $book->disconnectWorksheets();
    }
});

test('oversized reports fail explicitly instead of silently truncating', function () {
    $fixture = transactionFixture();
    $base = transactionDocument($fixture)->getRawOriginal();
    unset($base['id']);
    $rows = [];
    for ($i = 0; $i < TransactionReport::MAX_DOCUMENTS; $i++) {
        $rows[] = [...$base, 'public_id' => (string) Str::ulid(), 'document_no' => 'FT LIMIT/'.$i];
        if (count($rows) === 100) {
            DB::table('fiscal_documents')->insert($rows);
            $rows = [];
        }
    }
    $this->actingAs($fixture['user'])->postJson(route('transaction-reports.download', transactionFilters()))->assertUnprocessable()->assertJsonValidationErrors('from');
});

test('customer names containing wildcard characters are searched literally', function () {
    $fixture = transactionFixture();
    transactionDocument($fixture, ['customer_name' => '100%_Empresa']);
    transactionDocument($fixture, ['customer_name' => '100 Outra Empresa']);
    $this->actingAs($fixture['user'])->getJson(route('transaction-reports.preview', transactionFilters(['customer' => '100%_'])))->assertOk()->assertJsonPath('count', 1);
});

test('an existing establishment from another company is rejected', function () {
    $fixture = transactionFixture();
    $other = transactionFixture();
    $this->actingAs($fixture['user'])->getJson(route('transaction-reports.preview', transactionFilters(['establishment' => $other['establishment']->public_id])))->assertUnprocessable()->assertJsonValidationErrors('establishment');
});

function backgroundTransactionExport(array $fixture, array $overrides = []): TransactionExport
{
    return TransactionExport::factory()->create([
        'workspace_id' => $fixture['company']->workspace_id,
        'legal_entity_id' => $fixture['company']->id,
        'user_id' => $fixture['user']->id,
        'filters' => transactionFilters(),
        ...$overrides,
    ]);
}

test('large exports queue a captured selection without generating a workbook in the request', function () {
    Queue::fake();
    $fixture = transactionFixture();
    $base = transactionDocument($fixture)->getRawOriginal();
    unset($base['id']);
    $rows = [];
    for ($i = 0; $i < 200; $i++) {
        $rows[] = [...$base, 'public_id' => (string) Str::ulid(), 'document_no' => 'FT ASYNC/'.$i];
    }
    DB::table('fiscal_documents')->insert($rows);
    transactionDocument(transactionFixture());
    $response = $this->actingAs($fixture['user'])->postJson(route('transaction-reports.download'), transactionFilters())
        ->assertStatus(202)->assertJsonPath('status', 'queued')->assertJsonPath('total', 201)->assertJsonPath('processed', 0);
    $export = TransactionExport::where('public_id', $response->json('id'))->firstOrFail();
    expect($export->document_ids)->toHaveCount(201);
    Queue::assertPushed(GenerateTransactionExport::class, fn ($job) => $job->exportId === $export->id && $job->connection === 'database');
    $this->getJson(route('transaction-reports.history'))->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $export->public_id)->assertJsonMissingPath('0.document_ids');
    $this->getJson(route('transaction-reports.file', $export))->assertStatus(409);
});

test('detail heavy exports queue even when there are few documents', function () {
    Queue::fake();
    $fixture = transactionFixture();
    $document = transactionDocument($fixture, ['status' => FiscalDocumentStatus::Draft]);
    $base = FiscalDocumentLine::factory()->create(['fiscal_document_id' => $document->id])->getRawOriginal();
    unset($base['id']);
    $rows = [];
    for ($i = 2; $i <= 1001; $i++) {
        $rows[] = [...$base, 'public_id' => (string) Str::ulid(), 'line_number' => $i];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('fiscal_document_lines')->insert($chunk);
    }
    $document->update(['status' => FiscalDocumentStatus::Valid]);
    $this->actingAs($fixture['user'])->postJson(route('transaction-reports.download'), transactionFilters(['include_lines' => true]))
        ->assertStatus(202)->assertJsonPath('total', 1);
    Queue::assertPushed(GenerateTransactionExport::class);
});

test('background job writes a private valid workbook with progress and does not add later documents', function () {
    Storage::fake('local');
    $fixture = transactionFixture();
    $document = transactionDocument($fixture);
    transactionDocument(transactionFixture());
    $export = backgroundTransactionExport($fixture, ['document_ids' => [$document->id]]);
    transactionDocument($fixture);
    $job = new GenerateTransactionExport($export->id);
    $job->handle(app(TransactionWorkbook::class));
    $export->refresh();
    expect($export->status)->toBe('ready')->and($export->processed_documents)->toBe(1);
    $disk = Storage::disk('local');
    $disk->assertExists($export->storagePath());
    $disk->assertMissing($export->storagePath().'.part');
    $book = IOFactory::load($disk->path($export->storagePath()));
    expect($book->getSheetByName('Transacções')->getHighestRow())->toBe(7)
        ->and($book->getSheetByName('Transacções')->getCell('A7')->getValue())->toBe($document->document_no);
    $book->disconnectWorksheets();
    $hash = hash_file('sha256', $disk->path($export->storagePath()));
    $job->handle(app(TransactionWorkbook::class));
    expect(hash_file('sha256', $disk->path($export->storagePath())))->toBe($hash);
    $this->actingAs($fixture['user'])->get(route('transaction-reports.file', $export))->assertOk()->assertDownload();
    $this->actingAs(User::factory()->withWorkspace()->create())->getJson(route('transaction-reports.file', $export))->assertNotFound();
});

test('export history and files are private even to another member of the same company', function () {
    Storage::fake('local');
    $fixture = transactionFixture();
    $export = backgroundTransactionExport($fixture, ['status' => 'ready']);
    $colleague = User::factory()->create(['current_workspace_id' => $fixture['company']->workspace_id]);
    WorkspaceMembership::factory()->create(['user_id' => $colleague->id, 'workspace_id' => $fixture['company']->workspace_id, 'is_active' => true]);
    $this->actingAs($colleague)->getJson(route('transaction-reports.history'))->assertOk()->assertExactJson([]);
    $this->getJson(route('transaction-reports.file', $export))->assertNotFound();
    $this->actingAs($fixture['user'])->get(route('transaction-reports.index'))->assertInertia(fn (Assert $page) => $page->has('exports', 1));
    $export->update(['expires_at' => now()->subSecond()]);
    $this->getJson(route('transaction-reports.file', $export))->assertGone();
});

test('revoked membership prevents background generation and subsequent download', function () {
    Storage::fake('local');
    $fixture = transactionFixture();
    $export = backgroundTransactionExport($fixture);
    DB::table('workspace_memberships')->where('user_id', $fixture['user']->id)->update(['is_active' => false]);
    (new GenerateTransactionExport($export->id))->handle(app(TransactionWorkbook::class));
    expect($export->refresh()->status)->toBe('failed');
    Storage::disk('local')->assertMissing($export->storagePath());
    $this->actingAs($fixture['user'])->getJson(route('transaction-reports.file', $export))->assertRedirect();
});

test('failed jobs clean partial files and expose only a safe failure message', function () {
    Storage::fake('local');
    $fixture = transactionFixture();
    $export = backgroundTransactionExport($fixture, ['status' => 'processing']);
    $disk = Storage::disk('local');
    $disk->put($export->storagePath().'.part', 'partial');
    $disk->put($export->storagePath(), 'partial');
    (new GenerateTransactionExport($export->id))->failed(new RuntimeException('secret database details'));
    expect($export->refresh()->status)->toBe('failed')->and($export->failure_message)->not->toContain('secret');
    $disk->assertMissing([$export->storagePath(), $export->storagePath().'.part']);
    $this->actingAs($fixture['user'])->getJson(route('transaction-reports.history'))->assertJsonPath('0.status', 'failed');
});

test('interrupted jobs can resume and expired jobs do not produce new files', function () {
    Storage::fake('local');
    $fixture = transactionFixture();
    $document = transactionDocument($fixture);
    $export = backgroundTransactionExport($fixture, ['status' => 'processing', 'document_ids' => [$document->id], 'processed_documents' => 1]);
    (new GenerateTransactionExport($export->id))->handle(app(TransactionWorkbook::class));
    expect($export->refresh()->status)->toBe('ready');
    $expired = backgroundTransactionExport($fixture, ['expires_at' => now()->subSecond()]);
    (new GenerateTransactionExport($expired->id))->handle(app(TransactionWorkbook::class));
    Storage::disk('local')->assertMissing($expired->storagePath());
});

test('scheduled cleanup removes expired exports but retains available downloads', function () {
    Storage::fake('local');
    $fixture = transactionFixture();
    $expired = backgroundTransactionExport($fixture, ['expires_at' => now()->subMinute()]);
    $active = backgroundTransactionExport($fixture, ['status' => 'ready']);
    $disk = Storage::disk('local');
    $disk->put($expired->storagePath(), 'expired');
    $disk->put($expired->storagePath().'.part', 'partial');
    $disk->put($active->storagePath(), 'available');
    $this->artisan('reports:prune-exports')->assertSuccessful();
    expect($expired->fresh())->toBeNull()->and($active->fresh())->not->toBeNull();
    $disk->assertMissing([$expired->storagePath(), $expired->storagePath().'.part']);
    $disk->assertExists($active->storagePath());
});

test('database queue worker executes a serialized export job and acknowledges it', function () {
    Storage::fake('local');
    $fixture = transactionFixture();
    $document = transactionDocument($fixture);
    $export = backgroundTransactionExport($fixture, ['document_ids' => [$document->id]]);
    $queue = Queue::connection('database');
    $queue->push((new GenerateTransactionExport($export->id))->beforeCommit(), '', 'default');
    expect(DB::table('jobs')->count())->toBe(1);
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($export->refresh()->status)->toBe('ready')->and(DB::table('jobs')->count())->toBe(0);
    Storage::disk('local')->assertExists($export->storagePath());
});
