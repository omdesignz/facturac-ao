<?php

/**
 * Turns the demo company's till shelf into one trade's, for "Por ofício": only that
 * trade's articles and customer stay active, so the app's pickers show them alone.
 *
 *   . <dir>/env.sh && php84 tools/demo/trade.php oficina|farmacia|restaurante|loja
 *
 * Prices are multiples of 50 Kz at 14%, so every total is a whole number of kwanzas.
 */

use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\LegalEntity;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DEMO_DIR') === false) {
    exit("refusing: source <dir>/env.sh from tools/demo/setup.sh first\n");
}

$repo = dirname(__DIR__, 4);
chdir($repo);
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.connections.sqlite.database') !== getenv('DB_DATABASE')) {
    exit("refusing: not the demo database\n");
}

$finalConsumer = ['Consumidor final', '999999999'];

$trades = [
    'oficina' => [['Transportes Kifangondo, Lda.', '5417330921'], [
        ['OF-ALIN', 'Alinhamento', 1_350_000],
        ['OF-FILT', 'Filtro de óleo', 650_000],
        ['OF-LAVA', 'Lavagem completa', 400_000],
        ['OF-MUDA', 'Mudança de óleo', 1_750_000],
        ['OF-MAOB', 'Mão de obra (hora)', 950_000],
        ['OF-PAST', 'Pastilhas de travão', 3_100_000],
        ['OF-VELA', 'Velas (jogo)', 1_800_000],
        ['OF-OLEO', 'Óleo 5W-30 (5 L)', 2_850_000],
    ]],
    'farmacia' => [$finalConsumer, [
        ['FA-AMOX', 'Amoxicilina 500 mg, caixa', 340_000],
        ['FA-COMP', 'Compressas esterilizadas', 95_000],
        ['FA-IBUP', 'Ibuprofeno 400 mg, caixa', 180_000],
        ['FA-PARA', 'Paracetamol 500 mg, caixa', 120_000],
        ['FA-PROT', 'Protector solar FPS 50', 1_250_000],
        ['FA-SORO', 'Soro fisiológico 500 ml', 150_000],
        ['FA-TERM', 'Termómetro digital', 550_000],
        ['FA-VITC', 'Vitamina C 1 g, tubo', 260_000],
    ]],
    'restaurante' => [$finalConsumer, [
        ['RE-CALU', 'Calulu de peixe', 900_000],
        ['RE-CERV', 'Cerveja 33 cl', 120_000],
        ['RE-DOCE', 'Doce de ginguba', 200_000],
        ['RE-FUNG', 'Funge', 150_000],
        ['RE-MUAM', 'Muamba de galinha', 850_000],
        ['RE-MUFE', 'Mufete', 950_000],
        ['RE-SUMO', 'Sumo natural', 180_000],
        ['RE-AGUA', 'Água 1,5 L', 80_000],
    ]],
    'loja' => [$finalConsumer, [
        ['LO-BONE', 'Boné', 450_000],
        ['LO-CALC', 'Calças de ganga', 1_850_000],
        ['LO-CAMI', 'Camisa de algodão', 1_250_000],
        ['LO-CINT', 'Cinto de couro', 750_000],
        ['LO-MOCH', 'Mochila', 1_450_000],
        ['LO-TSHI', 'T-shirt', 650_000],
        ['LO-TENI', 'Ténis', 2_700_000],
        ['LO-VEST', 'Vestido', 2_200_000],
    ]],
];

$trade = $argv[1] ?? '';
if (! isset($trades[$trade])) {
    exit('usage: trade.php '.implode('|', array_keys($trades))."\n");
}

$entity = LegalEntity::query()->where('trade_name', 'Distribuidora Ngola')->firstOrFail();
[[$customerName, $nif], $items] = $trades[$trade];

// The app's pickers list inactive records too, so the rest of the shelf is moved out of
// the company's sight (negative entity id). This database is thrown away after filming.
DB::statement('PRAGMA foreign_keys = OFF');
foreach (['catalogue_items', 'customers'] as $table) {
    DB::table($table)->where('legal_entity_id', -$entity->id)->update(['legal_entity_id' => $entity->id]);
}
CatalogueItem::query()->where('legal_entity_id', $entity->id)->update(['is_active' => false]);
Customer::query()->where('legal_entity_id', $entity->id)->update(['is_active' => false]);

foreach ($items as [$code, $name, $price]) {
    CatalogueItem::query()->updateOrCreate(
        ['legal_entity_id' => $entity->id, 'code' => $code],
        CatalogueItem::factory()->raw([
            'workspace_id' => $entity->workspace_id,
            'legal_entity_id' => $entity->id,
            'code' => $code,
            'name' => $name,
            'type' => 'product',
            'unit_of_measure' => 'UN',
            'unit_price_minor' => $price,
            'tax_type' => 'IVA',
            'tax_code' => 'NOR',
            'tax_percentage' => '14.00',
            'tax_exemption_code' => null,
            'is_active' => true,
        ]),
    );
}

Customer::query()->updateOrCreate(
    ['legal_entity_id' => $entity->id, 'name' => $customerName],
    Customer::factory()->raw([
        'workspace_id' => $entity->workspace_id,
        'legal_entity_id' => $entity->id,
        'name' => $customerName,
        'tax_identification_number' => $nif,
        'payment_terms_days' => 0,
        'is_active' => true,
    ]),
);

foreach (['catalogue_items', 'customers'] as $table) {
    DB::table($table)->where('legal_entity_id', $entity->id)->where('is_active', false)->update(['legal_entity_id' => -$entity->id]);
}

echo "shelf set for $trade\n";
