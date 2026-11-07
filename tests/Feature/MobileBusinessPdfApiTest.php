<?php

use App\Actions\SignInMobileDevice;
use App\Fiscal\Documents\PdfSheet;
use App\Fiscal\Documents\PrintFormat;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Str;

require_once __DIR__.'/../PosFixtures.php';

function businessPdfToken(User $user): string
{
    return $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
}

/**
 * The sheet's HTML instead of the PDF, so a test can read what was laid out.
 */
function captureBusinessSheets(): void
{
    app()->instance(PdfSheet::class, new class(app(PrintFormat::class)) extends PdfSheet
    {
        public function __construct(private PrintFormat $printFormat)
        {
            parent::__construct($printFormat);
        }

        public function render(string $view, array $data, array $metadata): string
        {
            return view($view, [
                ...$data,
                'money' => fn (int $minor): string => $this->printFormat->money($minor),
                'number' => fn (string $value, int $minimumDecimals = 0): string => $this->printFormat->decimal($value, $minimumDecimals),
                'percent' => fn (string $value): string => $this->printFormat->percent($value),
            ])->render();
        }
    });
}

/**
 * @param  array<string, mixed>  $company
 */
function statementCustomer(array $company): Customer
{
    return Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'name' => 'Mavinga & Filhos',
        'tax_identification_number' => '5417000000',
    ]);
}

/**
 * @param  array<string, mixed>  $company
 * @param  array<string, mixed>  $overrides
 */
function statementInvoice(array $company, Customer $customer, string $number, int $grossMinor, int $daysLate, array $overrides = []): FiscalDocument
{
    return FiscalDocument::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'establishment_id' => $company['establishment']->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Valid,
        'document_no' => $number,
        'document_date' => now()->subDays($daysLate + 30)->toDateString(),
        'due_date' => now()->subDays($daysLate)->toDateString(),
        'gross_total_minor' => $grossMinor,
        'net_total_minor' => $grossMinor,
        'issued_at' => now()->subDays($daysLate + 30),
        ...$overrides,
    ]);
}

it('renders a customer statement as a PDF on demand, never cached', function (): void {
    $company = posCompany();
    $customer = statementCustomer($company);
    statementInvoice($company, $customer, 'FT LOJA2026/1', 1_000_000, 12);

    $response = $this->withToken(businessPdfToken($company['owner']))
        ->get(route('mobile.v1.customers.statement', $customer->public_id))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($response->getContent())->toStartWith('%PDF-')
        ->and($response->headers->get('Content-Disposition'))->toContain('Extracto-mavinga-filhos-');
});

it('lays the statement out from the customer page\'s own figures', function (): void {
    captureBusinessSheets();
    $company = posCompany();
    $customer = statementCustomer($company);
    statementInvoice($company, $customer, 'FT LOJA2026/1', 1_000_000, 12);
    statementInvoice($company, $customer, 'FT LOJA2026/2', 250_000, -10);

    $html = (string) $this->withToken(businessPdfToken($company['owner']))
        ->get(route('mobile.v1.customers.statement', $customer->public_id))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('Extracto de conta')
        ->toContain('Mavinga &amp; Filhos')
        ->toContain('NIF 5417000000')
        ->toContain('FT LOJA2026/1')
        ->toContain('FT LOJA2026/2')
        // Owed: both; overdue: only the first; the running balance ends on the sum.
        ->toContain('12 500,00 Kz')
        ->toContain('10 000,00 Kz')
        ->toContain('12 dias em atraso')
        ->toContain('Documento não fiscal');
});

it('only calls an invoice late while something on it is still owed', function (): void {
    captureBusinessSheets();
    $company = posCompany();
    $customer = statementCustomer($company);
    $paid = statementInvoice($company, $customer, 'FT LOJA2026/1', 400_000, 40);
    statementInvoice($company, $customer, 'FT LOJA2026/2', 100_000, 5);
    $receipt = statementInvoice($company, $customer, 'RG LOJA2026/1', 0, -1, [
        'document_type' => FiscalDocumentType::Receipt,
        'due_date' => null,
    ]);
    $receipt->settlements()->create([
        'workspace_id' => $paid->workspace_id,
        'legal_entity_id' => $paid->legal_entity_id,
        'settled_document_id' => $paid->id,
        'settled_document_no' => $paid->document_no,
        'amount_minor' => 400_000,
    ]);

    $html = (string) $this->withToken(businessPdfToken($company['owner']))
        ->get(route('mobile.v1.customers.statement', $customer->public_id))
        ->getContent();

    expect($html)->toContain('5 dias em atraso')
        ->not->toContain('40 dias em atraso')
        ->toContain('−4 000,00');
});

it('has no statement for another company\'s customer', function (): void {
    $company = posCompany();
    $other = posCompany();
    $stranger = statementCustomer($other);

    $this->withToken(businessPdfToken($company['owner']))
        ->get(route('mobile.v1.customers.statement', $stranger->public_id))
        ->assertNotFound();
});

/**
 * @param  array<string, mixed>  $company
 */
function pdfQuote($test, array $company, Customer $customer): Quote
{
    $item = posItem($company, ['unit_price_minor' => 10000, 'name' => 'Cimento Portland 50 kg']);

    $test->withToken(businessPdfToken($company['owner']))->postJson(route('mobile.v1.quotes.store'), [
        'client_key' => (string) Str::uuid(),
        'customer_public_id' => $customer->public_id,
        'lines' => [['catalogue_item_public_id' => $item->public_id, 'quantity' => '2']],
        'valid_for_days' => 15,
        'expected_total_minor' => 22_800,
    ])->assertCreated();

    return Quote::query()->latest('id')->firstOrFail();
}

it('renders a quote as a PDF on demand', function (): void {
    $company = posCompany();
    $quote = pdfQuote($this, $company, statementCustomer($company));

    $response = $this->withToken(businessPdfToken($company['owner']))
        ->get(route('mobile.v1.quotes.pdf', $quote->public_id))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->getContent())->toStartWith('%PDF-')
        ->and($response->headers->get('Content-Disposition'))->toContain('Orcamento-orc-');
});

it('lays the quote out from its stored lines and totals, and says it is not fiscal', function (): void {
    captureBusinessSheets();
    $company = posCompany();
    $quote = pdfQuote($this, $company, statementCustomer($company));

    $html = (string) $this->withToken(businessPdfToken($company['owner']))
        ->get(route('mobile.v1.quotes.pdf', $quote->public_id))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('Orçamento')
        ->toContain(e($quote->reference))
        ->toContain('Cimento Portland 50 kg')
        ->toContain('2 UN')
        ->toContain('14%')
        ->toContain('228,00 Kz')
        ->toContain('Válido até')
        ->toContain($quote->valid_until->format('d/m/Y'))
        ->toContain('sem valor fiscal');
});

it('has no PDF for another company\'s quote', function (): void {
    $company = posCompany();
    $other = posCompany();
    $quote = pdfQuote($this, $other, statementCustomer($other));
    app('auth')->forgetGuards();

    $this->withToken(businessPdfToken($company['owner']))
        ->get(route('mobile.v1.quotes.pdf', $quote->public_id))
        ->assertNotFound();
});
