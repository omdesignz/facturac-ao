<?php

/**
 * A year of trading at Distribuidora Ngola, Lda., a Luanda food and drinks
 * wholesaler that sells to restaurants, hotels and shops on 30-day terms.
 *
 * Every document goes through the real pipeline at its own date: the clock is
 * set to the morning it was issued, the phone's invoice action numbers and
 * signs it, the AGT's answer is replayed through the tests' own helper, and
 * each payment is a receipt issued the day the money arrived. So the screens
 * show exactly what a customer of a year would see. Seeded randomness is
 * fixed, so a rebuild gives the same company.
 *
 * Run only through setup.sh, which points it at a throwaway SQLite database.
 */

use App\Actions\CreateMobileInvoice;
use App\Actions\RecordMobilePayment;
use App\Fiscal\Agt\Contracts\JwsSigner;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\WorkspaceSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DEMO_DIR') === false) {
    exit("refusing: run through tools/demo/setup.sh\n");
}

$repo = dirname(__DIR__, 4);
chdir($repo);
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (config('database.connections.sqlite.database') !== getenv('DB_DATABASE')) {
    exit("refusing: not the demo database\n");
}

require 'tests/PosFixtures.php';
$pest = file_get_contents('tests/Pest.php');
preg_match('/function recordAuthoritativeAgtAcceptance.*?\n}\n/s', $pest, $match);
$uses = implode("\n", array_filter(explode("\n", $pest), fn ($line) => str_starts_with($line, 'use App') || str_starts_with($line, 'use Illuminate')));
eval($uses."\n".$match[0]);

$dir = getenv('DEMO_DIR');
$tz = 'Africa/Luanda';
$today = CarbonImmutable::now($tz)->startOfDay();
$first = CarbonImmutable::parse('2025-11-03', $tz);
mt_srand(2026);

// ------------------------------------------------------------------ company

Carbon::setTestNow($first->subDays(3)->setTime(9, 0));
$company = posCompany();
app()->forgetInstance(JwsSigner::class);
$signer = app(JwsSigner::class);
$company['connection']->forceFill([
    'software_key_reference' => 'software/demo', 'taxpayer_key_reference' => 'taxpayer/demo',
    'software_key_fingerprint' => $signer->fingerprint('software/demo'),
    'taxpayer_key_fingerprint' => $signer->fingerprint('taxpayer/demo'),
])->save();

$secret = (new Google2FA)->generateSecretKey();
file_put_contents("$dir/secret.txt", $secret);
$owner = $company['owner'];
$owner->forceFill([
    'name' => 'Ana Manuel',
    'email' => 'ana@ngola.test',
    'email_verified_at' => now(),
    'password' => Hash::make(trim(file_get_contents("$dir/password.txt"))),
    'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
])->save();

$legalEntity = $company['legalEntity'];
$legalEntity->forceFill([
    'legal_name' => 'Distribuidora Ngola, Lda.',
    'trade_name' => 'Distribuidora Ngola',
    'tax_identification_number' => '5417263580',
])->save();
$legalEntity->workspace->forceFill(['name' => 'Distribuidora Ngola'])->save();
$company['establishment']->forceFill(['name' => 'Armazém do Cazenga'])->save();

WorkspaceSubscription::factory()->create([
    'workspace_id' => $legalEntity->workspace_id,
    'current_period_started_at' => $first->subDays(3),
    'current_period_ends_at' => $today->addYears(2),
]);

foreach ([2025, 2026] as $year) {
    $yy = substr((string) $year, 2);
    foreach (["FT$yy" => FiscalDocumentType::Invoice, "RG$yy" => FiscalDocumentType::Receipt] as $code => $type) {
        posSeries($legalEntity, $company['establishment'], $company['connection'], [
            'series_code' => $code,
            'series_year' => $year,
            'document_type' => $type,
            'last_authorized_number' => 9999,
        ]);
    }
}

// ---------------------------------------------------------------- catalogue

// Prices are multiples of 20 Kz at 5% and of 50 Kz at 14%, so every tax
// amount, and so every total, is a whole number of kwanzas.

$items = [];
foreach ([
    ['ARR-25', 'Arroz agulha 25 kg', 2_480_000, '5.00'],
    ['ACU-50', 'Açúcar branco 50 kg', 4_350_000, '5.00'],
    ['FAR-50', 'Farinha de trigo 50 kg', 3_790_000, '5.00'],
    ['OLE-5', 'Óleo alimentar 5 L', 940_000, '5.00'],
    ['AGU-6', 'Água mineral 1,5 L, pack de 6', 180_000, '14.00'],
    ['LEI-12', 'Leite UHT 1 L, caixa de 12', 960_000, '14.00'],
    ['SUM-6', 'Sumo de manga 1 L, pack de 6', 420_000, '14.00'],
    ['REF-24', 'Refrigerante 33 cl, grade de 24', 690_000, '14.00'],
    ['MAS-5', 'Massa esparguete, fardo de 5 kg', 610_000, '14.00'],
    ['FEI-10', 'Feijão manteiga 10 kg', 1_150_000, '5.00'],
] as [$code, $name, $price, $rate]) {
    $items[$code] = posItem($company, [
        'code' => $code,
        'name' => $name,
        'unit_price_minor' => $price,
        'tax_percentage' => $rate,
    ]);
}

// ---------------------------------------------------------------- customers

// [name, NIF, e-mail, terms, how many days they usually take, weight, usual basket]
$customerRows = [
    ['Restaurante Muxima', '5000418273', 'compras@muxima.test', 30, 24, 5, ['ARR-25', 'OLE-5', 'FEI-10', 'REF-24', 'AGU-6']],
    ['Hotel Baía Azul, Lda.', '5417002196', 'contabilidade@baiaazul.test', 30, 41, 4, ['AGU-6', 'LEI-12', 'SUM-6', 'REF-24', 'ACU-50']],
    ['Mercearia Kilamba', '5000412778', 'mercearia.kilamba@gmail.test', 15, 13, 5, ['ARR-25', 'ACU-50', 'MAS-5', 'OLE-5', 'LEI-12']],
    ['Pastelaria Kianda', '5000937410', 'pastelaria.kianda@test', 30, 19, 3, ['FAR-50', 'ACU-50', 'LEI-12', 'OLE-5']],
    ['Supermercado Talatona', '5417558821', 'fornecedores@talatona.test', 30, 33, 4, ['ARR-25', 'ACU-50', 'AGU-6', 'SUM-6', 'MAS-5', 'FEI-10']],
    ['Mavinga & Filhos, Lda.', '5411111111', 'financeiro@mavinga.test', 30, 52, 3, ['ARR-25', 'FAR-50', 'OLE-5', 'REF-24']],
    ['Cantina Escolar Ngola Kiluanje', '5000201946', 'cantina.ngola@test', 30, 28, 2, ['ARR-25', 'FEI-10', 'OLE-5', 'LEI-12']],
];

$customers = [];
foreach ($customerRows as [$name, $nif, $email, $terms, $pays, $weight, $basket]) {
    $customers[] = [
        'model' => Customer::factory()->create([
            'workspace_id' => $legalEntity->workspace_id,
            'legal_entity_id' => $legalEntity->id,
            'name' => $name,
            'tax_identification_number' => $nif,
            'email' => $email,
            'phone' => '+244 9'.mt_rand(10, 49).' '.mt_rand(100, 999).' '.mt_rand(100, 999),
            'payment_terms_days' => $terms,
            'is_active' => true,
        ]),
        'pays' => $pays,
        'weight' => $weight,
        'basket' => $basket,
    ];
}

$pick = function () use ($customers): array {
    $total = array_sum(array_column($customers, 'weight'));
    $roll = mt_rand(1, $total);
    foreach ($customers as $customer) {
        $roll -= $customer['weight'];
        if ($roll <= 0) {
            return $customer;
        }
    }

    return $customers[0];
};

// ------------------------------------------------------------------- events

/** @var list<array{at: CarbonImmutable, kind: string, data: array<string, mixed>}> $events */
$events = [];
$sale = 0;

for ($day = $first; $day->lte($today); $day = $day->addDay()) {
    if ($day->isSunday()) {
        continue;
    }

    // Trade grows over the year, and the last month is the busiest.
    $age = $first->diffInDays($day) / max(1, $first->diffInDays($today));
    $perDay = 0.7 + 1.6 * $age + ($today->diffInDays($day, true) < 30 ? 0.8 : 0.0);
    $count = (int) floor($perDay) + (mt_rand() / mt_getrandmax() < fmod($perDay, 1.0) ? 1 : 0);
    if ($day->eq($today)) {
        $count = 3;
    }

    for ($n = 0; $n < $count; $n++) {
        $customer = $pick();
        $basket = $customer['basket'];
        shuffle($basket);
        $lines = [];
        foreach (array_slice($basket, 0, mt_rand(2, min(4, count($basket)))) as $code) {
            $lines[] = ['catalogue_item_public_id' => $items[$code]->public_id, 'quantity' => (string) mt_rand(2, 9)];
        }

        $at = $day->setTime(8 + 2 * $n + mt_rand(0, 1), mt_rand(0, 59));
        $id = ++$sale;
        $events[] = ['at' => $at, 'kind' => 'invoice', 'data' => ['id' => $id, 'customer' => $customer['model'], 'lines' => $lines]];

        // Most invoices are paid around the customer's habit; the newest are
        // still open, and two customers carry debts that have gone late.
        $late = in_array($customer['model']->name, ['Hotel Baía Azul, Lda.', 'Mavinga & Filhos, Lda.'], true);
        $pays = $customer['pays'] + mt_rand(-6, 9);
        $paidOn = $at->addDays($pays)->setTime(mt_rand(9, 16), mt_rand(0, 59));
        $leftOpen = $late && $today->diffInDays($at, true) < 80 && mt_rand(1, 3) === 1;
        if (! $leftOpen && $paidOn->lt($today->setTime(7, 0))) {
            $events[] = ['at' => $paidOn, 'kind' => 'payment', 'data' => ['id' => $id, 'customer' => $customer['model']]];
        }
    }
}

usort($events, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

// ------------------------------------------------------------------- replay

$documents = [];
$issued = 0;
$receipts = 0;

foreach ($events as $event) {
    Carbon::setTestNow($event['at']);
    Queue::fake();

    if ($event['kind'] === 'invoice') {
        $customer = $event['data']['customer'];
        $preview = app(CreateMobileInvoice::class)->preview($legalEntity, $customer->public_id, $event['data']['lines']);
        $invoice = app(CreateMobileInvoice::class)->execute($legalEntity, $owner, [
            'client_key' => (string) Str::uuid(),
            'customer_public_id' => $customer->public_id,
            'lines' => $event['data']['lines'],
            'expected_total_minor' => $preview['gross_total_minor'],
            'issue' => true,
        ]);
        $document = FiscalDocument::query()->findOrFail($invoice->fiscal_document_id);
        recordAuthoritativeAgtAcceptance($document);
        $documents[$event['data']['id']] = $document;
        $issued++;

        continue;
    }

    $document = $documents[$event['data']['id']] ?? null;
    if ($document === null) {
        continue;
    }

    $receipt = app(RecordMobilePayment::class)->execute($legalEntity, $owner, $event['data']['customer'], [
        'client_key' => (string) Str::uuid(),
        'payment_method' => mt_rand(1, 4) === 1 ? 'NU' : 'TB',
        'payment_date' => $event['at']->toDateString(),
        'settlements' => [['document_public_id' => $document->public_id, 'amount_minor' => (int) $document->refresh()->gross_total_minor]],
    ]);
    recordAuthoritativeAgtAcceptance(FiscalDocument::query()->findOrFail($receipt->fiscal_document_id));
    $receipts++;
}

Carbon::setTestNow();

echo "Distribuidora Ngola: $issued invoices, $receipts receipts, ".count($customers)." customers\n";
echo "sign in as ana@ngola.test with the password in $dir/password.txt and the code from $dir/totp.sh\n";
