<?php

use App\Fiscal\CommandCapability;
use App\Fiscal\CommandIdempotency;
use App\Fiscal\CustomerCreateInput;
use App\Fiscal\ExternalCustomerCommand;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(app()->environment())->toBe('testing');
    expect((DB::getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() === ':memory:') || (DB::getDriverName() === 'pgsql' && str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_')))->toBeTrue();
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    config(['integrations.enabled' => true, 'integrations.commands_enabled' => true]);
});

require_once __DIR__.'/../ServiceCommandFixtures.php';

use App\Fiscal\CustomerCommands;
use App\Fiscal\ExternalServiceCommand;
use App\Fiscal\HumanServiceCommandContext;
use App\Fiscal\ServiceCommands;
use App\Fiscal\ServiceCreateInput;
use App\Fiscal\ServiceTaxProfile;
use App\Fiscal\SupportedTaxTreatment;
use App\Models\CatalogueItem;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\PriceListItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

test('service command commits complete profile and frozen replay without current defaults', function (string $token, string $price) {
    $f = serviceFixture();
    $input = serviceInput(['tax_treatment' => $token, 'unit_price_minor' => $price]);
    $first = app(ExternalServiceCommand::class)->execute($f['context'], $input, 'one');
    $item = CatalogueItem::firstOrFail();
    expect($item->type->value)->toBe('service')->and($item->tracks_stock)->toBeFalse()->and($item->stock_scale)->toBe(3)
        ->and($item->reorder_level_units)->toBeNull()->and($item->unit_price_minor)->toBe((int) $price)->and($item->currency_code)->toBe('AOA')
        ->and($item->description)->toBeNull()->and($item->unit_of_measure)->toBe('UN');
    $profile = SupportedTaxTreatment::from($token)->profile();
    expect($item->tax_type)->toBe($profile['type'])->and($item->tax_code)->toBe($profile['code'])->and($item->tax_exemption_code)->toBe($profile['exemption_code']);
    $f['entity']->update(['currency_code' => 'USD']);
    config(['fiscal.currencies' => []]);
    $item->delete();
    $second = app(ExternalServiceCommand::class)->execute($f['context'], $input, 'one');
    expect($first['body'])->toBe($second['body'])->and($second['replayed'])->toBeTrue()
        ->and(DB::table('external_command_operations')->count())->toBe(1)->and((int) DB::table('external_command_capacity')->value('completed_count'))->toBe(1)
        ->and(Activity::where('event', 'catalogue.service.created')->count())->toBe(1)->and(Activity::where('event', 'external.command.replayed')->count())->toBe(1);
})->with(['IVA_NOR_14', 'IVA_ISE_M00', 'NS_M02', 'IVA_ISE_M04'])->with(['0', '1', '9007199254740993', '9223372036854775807']);

test('service canonicalization uses seven normalized keys and independent command namespace', function () {
    $f = serviceFixture(['customers:create', 'catalogue:services:create']);
    $input = serviceInput(['code' => " consult-01\t", 'name' => " Cafe\u{0301} ", 'unit_of_measure' => ' un ']);
    $same = serviceInput(['name' => 'Café']);
    $commands = app(ExternalServiceCommand::class);
    expect($commands->canonical($f['context'], $input))->toBe($commands->canonical($f['context'], $same));
    $decoded = json_decode($commands->canonical($f['context'], $input), true);
    expect($decoded['input'])->toHaveCount(7)->and($decoded['input']['unit_price_minor'])->toBe('10000')->and($decoded['defaults'])->toBe('catalogue-service-create-v1');
    $first = $commands->execute($f['context'], $input, 'same-key');
    $customer = app(ExternalCustomerCommand::class)->execute($f['context'], CustomerCreateInput::external('{"name":"Café","tax_identification_number":"5401234567"}'), 'same-key');
    expect($first['body'])->not->toBe($customer['body'])->and(DB::table('external_command_operations')->count())->toBe(2)
        ->and((int) DB::table('external_command_capacity')->value('completed_count'))->toBe(2);
    expect(fn () => $commands->execute($f['context'], serviceInput(['name' => 'Changed']), 'same-key'))->toThrow(HttpException::class);
    expect(CatalogueItem::count())->toBe(1);
});

test('service duplicates include existing products and inactive references without upsert', function (bool $active) {
    $f = serviceFixture();
    CatalogueItem::factory()->create(['workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id, 'code' => 'CONSULT-01', 'type' => 'product', 'is_active' => $active]);
    try {
        app(ExternalServiceCommand::class)->execute($f['context'], serviceInput(), 'duplicate');
        $this->fail('Must conflict');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(409)->and($error->getMessage())->toBe('CATALOGUE_CONFLICT');
    }
    expect(CatalogueItem::count())->toBe(1)->and(DB::table('external_command_operations')->count())->toBe(0)->and(Activity::where('event', 'catalogue.service.created')->count())->toBe(0);
})->with([true, false]);

test('service cancelled or poisoned persistence cannot acknowledge a complete effect', function (string $event) {
    $f = serviceFixture();
    if ($event === 'poison') {
        CatalogueItem::created(fn ($item) => DB::table('catalogue_items')->where('id', $item->id)->update(['tracks_stock' => true]));
    } else {
        CatalogueItem::$event(fn () => false);
    }
    try {
        expect(fn () => app(ExternalServiceCommand::class)->execute($f['context'], serviceInput(), 'cancel'))->toThrow(HttpException::class);
    } finally {
        CatalogueItem::flushEventListeners();
        CatalogueItem::clearBootedModels();
    }
    expect(CatalogueItem::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0)->and(Activity::where('event', 'catalogue.service.created')->count())->toBe(0);
})->with(['creating', 'saving', 'poison']);

test('service effect configuration drift refuses new effects but does not invalidate replay', function () {
    $f = serviceFixture();
    $first = app(ExternalServiceCommand::class)->execute($f['context'], serviceInput(), 'old');
    app()->instance(ServiceTaxProfile::class, new class extends ServiceTaxProfile
    {
        public function resolve(string $token): array
        {
            return ['type' => 'IVA', 'code' => 'NOR', 'percentage' => '7', 'exemption_code' => null];
        }
    });
    app()->forgetInstance(ExternalServiceCommand::class);
    expect(app(ExternalServiceCommand::class)->execute($f['context'], serviceInput(), 'old')['body'])->toBe($first['body']);
    expect(fn () => app(ExternalServiceCommand::class)->execute($f['context'], serviceInput(['code' => 'OTHER']), 'new'))->toThrow(HttpException::class);
    expect(CatalogueItem::count())->toBe(1);
});

test('service replay revalidates current authority', function (string $withdrawal) {
    $f = serviceFixture();
    app(ExternalServiceCommand::class)->execute($f['context'], serviceInput(), 'old');
    match ($withdrawal) {
        'credential' => DB::table('integration_credentials')->where('id', $f['context']->credentialId)->update(['revoked_at' => now()]),
        'parent' => DB::table('integrations')->where('id', $f['context']->integrationId)->update(['revoked_at' => now()]),
        'scope' => DB::table('integration_scopes')->where('integration_id', $f['context']->integrationId)->delete(),
        'role' => DB::table('workspace_memberships')->where('id', $f['context']->membershipId)->update(['role' => 'viewer']),
        'mfa' => DB::table('users')->where('id', $f['context']->sponsorId)->update(['two_factor_confirmed_at' => null]),
        'email' => DB::table('users')->where('id', $f['context']->sponsorId)->update(['email_verified_at' => null]),
        'expiry' => DB::table('integration_credentials')->where('id', $f['context']->credentialId)->update(['expires_at' => CarbonImmutable::parse(DB::table('integration_credentials')->where('id', $f['context']->credentialId)->value('created_at'))->addSecond()]),
    };
    if ($withdrawal === 'expiry') {
        usleep(1_100_000);
    }
    expect(fn () => app(ExternalServiceCommand::class)->execute($f['context'], serviceInput(), 'old'))->toThrow(HttpException::class);
    expect(CatalogueItem::count())->toBe(1)->and(Activity::where('event', 'external.command.replayed')->count())->toBe(0);
})->with(['credential', 'parent', 'scope', 'role', 'mfa', 'email', 'expiry']);

test('human service adapter preserves active choice and exact decimal pricing', function () {
    $f = serviceFixture();
    $input = ServiceCreateInput::human(['code' => 'HUMAN', 'name' => 'Human', 'description' => null, 'unit_of_measure' => 'UN', 'unit_price' => '123.45', 'tax_type' => 'IVA', 'tax_code' => 'NOR', 'tax_percentage' => '14', 'tax_exemption_code' => null, 'is_active' => false]);
    $item = app(ServiceCommands::class)->create(HumanServiceCommandContext::resolve($f['user'], $f['entity']), $input);
    expect($item->unit_price_minor)->toBe(12345)->and($item->is_active)->toBeFalse()->and($item->tracks_stock)->toBeFalse()
        ->and(Activity::where('event', 'catalogue.service.created')->count())->toBe(1);
});

test('human service decimal overflow is a field error without float conversion', function () {
    expect(ServiceCreateInput::decimalMinor('90071992547409.93'))->toBe(9007199254740993);
    expect(fn () => ServiceCreateInput::decimalMinor('999999999999999999'))->toThrow(ValidationException::class);
});

test('customer persistence rejects a service reservation even when both grants exist', function () {
    $f = serviceFixture(['customers:create', 'catalogue:services:create']);
    expect(fn () => app(CommandIdempotency::class)->execute($f['context'], 'swap', str_repeat('a', 64),
        fn (string $id) => app(CustomerCommands::class)->persist($f['context'], CustomerCreateInput::external('{"name":"N","tax_identification_number":"5401234567"}'), $id)->public_id, CommandCapability::ServiceCreate))->toThrow(HttpException::class);
    expect(Customer::count())->toBe(0)->and(CatalogueItem::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0);
});

test('service required audit cancellation rolls back human and machine rows', function (bool $human) {
    $f = serviceFixture();
    Activity::creating(fn (Activity $audit) => $audit->event === 'catalogue.service.created' ? false : null);
    try {
        if ($human) {
            $input = ServiceCreateInput::human(['code' => 'H', 'name' => 'N', 'unit_of_measure' => 'UN', 'unit_price' => '1', 'tax_type' => 'IVA', 'tax_code' => 'NOR', 'tax_percentage' => '14', 'is_active' => true]);
            expect(fn () => app(ServiceCommands::class)->create(HumanServiceCommandContext::resolve($f['user'], $f['entity']), $input))->toThrow(RuntimeException::class);
        } else {
            expect(fn () => app(ExternalServiceCommand::class)->execute($f['context'], serviceInput(), 'cancel-audit'))->toThrow(RuntimeException::class);
        }
    } finally {
        Activity::flushEventListeners();
        Activity::clearBootedModels();
    }
    expect(CatalogueItem::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0);
})->with([false, true]);

test('both command canonical bytes match independent ordered golden envelopes', function () {
    $f = serviceFixture(['customers:create', 'catalogue:services:create']);
    $c = $f['context'];
    $prefix = '{"canonicalizer":"command-json-v1","command":"';
    $context = '"context":{"environment":"production","legal_entity_public_id":"'.$c->entityPublicId.'","workspace_public_id":"'.$c->workspacePublicId.'"}';
    $principal = '"principal":{"kind":"integration","public_id":"'.$c->integrationPublicId.'"},"version":1}';
    $service = $prefix.'catalogue.services.create",'.$context.',"defaults":"catalogue-service-create-v1","input":{"code":"CONSULT-01","currency_code":"AOA","description":null,"name":"Consultoria","tax_treatment":"IVA_NOR_14","unit_of_measure":"UN","unit_price_minor":"10000"},'.$principal;
    $customer = $prefix.'customers.create",'.$context.',"defaults":"customer-create-v1","input":{"country_code":"AO","name":"Customer","tax_identification_number":"5401234567"},'.$principal;
    expect(app(ExternalServiceCommand::class)->canonical($c, serviceInput()))->toBe($service);
    $input = CustomerCreateInput::external('{"name":"Customer","tax_identification_number":"5401234567"}');
    expect(app(ExternalCustomerCommand::class)->canonical($c, $input))->toBe($customer);
    app(ExternalServiceCommand::class)->execute($c, serviceInput(), 'golden');
    expect(DB::table('external_command_operations')->value('fingerprint_hash'))->toBe(hash('sha256', $service));
});

test('service UI storage bounds return field validation without changing product paths', function (string $field, string $value) {
    $f = serviceFixture();
    $values = ['code' => 'UI-SERVICE', 'type' => 'service', 'name' => 'UI Service', 'unit_of_measure' => 'UN', 'unit_price' => '100.00', 'tax_type' => 'IVA', 'tax_code' => 'NOR', 'tax_percentage' => '14', 'is_active' => true, 'tracks_stock' => false];
    $this->actingAs($f['user'])->post(route('catalogue.store'), [...$values, $field => $value])->assertSessionHasErrors($field);
    expect(CatalogueItem::count())->toBe(0)->and(Activity::where('event', 'catalogue.service.created')->count())->toBe(0);
})->with([['description', str_repeat('x', 256)], ['unit_price', '999999999999999999']]);

test('services with the same name retain code uniqueness only within each entity and create no stock or fiscal state', function () {
    $f = serviceFixture();
    $other = serviceFixture();
    app(ExternalServiceCommand::class)->execute($f['context'], serviceInput(), 'first');
    app(ExternalServiceCommand::class)->execute($f['context'], serviceInput(['code' => 'OTHER']), 'second');
    app(ExternalServiceCommand::class)->execute($other['context'], serviceInput(), 'first');
    expect(CatalogueItem::count())->toBe(3)->and(StockLevel::count())->toBe(0)->and(StockMovement::count())->toBe(0)
        ->and(PriceListItem::count())->toBe(0)->and(FiscalDocument::count())->toBe(0)->and(Payment::count())->toBe(0);
});

test('service uniqueness classifier cannot mistake a payload string for constraint metadata', function () {
    $f = serviceFixture();
    $existing = CatalogueItem::factory()->create(['workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id, 'code' => 'EXISTING']);
    CatalogueItem::creating(fn (CatalogueItem $item) => $item->public_id = $existing->public_id);
    try {
        try {
            app(ExternalServiceCommand::class)->execute($f['context'], serviceInput(['code' => 'CATALOGUE_ITEMS_ENTITY_CODE_UNIQUE', 'name' => 'catalogue_items_entity_code_unique']), 'other-constraint');
            $this->fail('Must reject unique identity collision');
        } catch (HttpException $error) {
            expect($error->getStatusCode())->toBe(503);
        }
    } finally {
        CatalogueItem::flushEventListeners();
        CatalogueItem::clearBootedModels();
    }
    expect(CatalogueItem::count())->toBe(1)->and(DB::table('external_command_operations')->count())->toBe(0);
});
