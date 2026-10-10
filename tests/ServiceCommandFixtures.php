<?php

use App\Fiscal\ServiceCreateInput;

require_once __DIR__.'/CustomerCommandFixtures.php';

function serviceFixture(array $scopes = ['catalogue:services:create']): array
{
    $f = commandFixture($scopes);
    $f['url'] = substr($f['url'], 0, -strlen('customers')).'catalogue-services';

    return $f;
}

function servicePayload(array $overrides = []): array
{
    return [...['code' => 'CONSULT-01', 'name' => 'Consultoria', 'unit_price_minor' => '10000', 'currency_code' => 'AOA', 'tax_treatment' => 'IVA_NOR_14'], ...$overrides];
}

function serviceInput(array $overrides = []): ServiceCreateInput
{
    return ServiceCreateInput::external(json_encode(servicePayload($overrides), JSON_THROW_ON_ERROR));
}
