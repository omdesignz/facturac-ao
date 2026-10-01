<?php

use App\LegalEntityStatus;
use App\Models\AgtConnection;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Database\Seeders\AgtHomologationSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('agt.homologation_fixture', [
        'alias' => 'HML-T01',
        'tax_identification_number' => '5000413178',
        'user_email' => 'agt.hml-t01@facturac.test',
        'user_password' => 'Local-HML-T01!2026',
    ]);
});

test('it creates an isolated and usable AGT homologation workspace', function () {
    $this->seed(AgtHomologationSeeder::class);

    $user = User::query()->where('email', 'agt.hml-t01@facturac.test')->firstOrFail();
    $workspace = Workspace::query()->where('slug', 'agt-homologation-hml-t01')->firstOrFail();
    $legalEntity = $workspace->legalEntities()->firstOrFail();
    $establishment = $legalEntity->establishments()->firstOrFail();
    $membership = WorkspaceMembership::query()
        ->whereBelongsTo($workspace)
        ->whereBelongsTo($user)
        ->firstOrFail();

    expect($user->current_workspace_id)->toBe($workspace->id)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('Local-HML-T01!2026', $user->password))->toBeTrue()
        ->and($workspace->name)->toBe('AGT Homologação HML-T01')
        ->and($legalEntity->tax_identification_number)->toBe('5000413178')
        ->and($legalEntity->legal_name)->toBe('NIF TESTE PROJECTO SIGT')
        ->and($legalEntity->status)->toBe(LegalEntityStatus::Configured)
        ->and($establishment->code)->toBe('SEDE')
        ->and($establishment->is_head_office)->toBeTrue()
        ->and($membership->role)->toBe(WorkspaceRole::Owner)
        ->and($membership->is_active)->toBeTrue()
        ->and(AgtConnection::query()->whereBelongsTo($workspace)->doesntExist())->toBeTrue();
});

test('it is idempotent', function () {
    $this->seed(AgtHomologationSeeder::class);
    $this->seed(AgtHomologationSeeder::class);

    expect(User::query()->where('email', 'agt.hml-t01@facturac.test')->count())->toBe(1)
        ->and(Workspace::query()->where('slug', 'agt-homologation-hml-t01')->count())->toBe(1)
        ->and(LegalEntity::query()->where('tax_identification_number', '5000413178')->count())->toBe(1)
        ->and(WorkspaceMembership::query()->count())->toBe(1);
});

test('the main database seed includes the configured homologation fixture', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Workspace::query()->where('slug', 'agt-homologation-hml-t01')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'agt.hml-t01@facturac.test')->exists())->toBeTrue();
});

test('it refuses to run without an official locally configured NIF', function () {
    config()->set('agt.homologation_fixture.tax_identification_number');

    $this->seed(AgtHomologationSeeder::class);
})->throws(InvalidArgumentException::class, 'Set AGT_HOMOLOGATION_FIXTURE_NIF');

test('it only accepts controlled AGT test aliases', function () {
    config()->set('agt.homologation_fixture.alias', 'HML-T06');

    $this->seed(AgtHomologationSeeder::class);
})->throws(InvalidArgumentException::class, 'between HML-T01 and HML-T05');

test('it accepts each official AGT workbook identity', function (string $alias, string $taxIdentificationNumber, string $legalName) {
    config()->set('agt.homologation_fixture.alias', $alias);
    config()->set('agt.homologation_fixture.tax_identification_number', $taxIdentificationNumber);
    config()->set('agt.homologation_fixture.user_email', Str::lower($alias).'@facturac.test');

    $this->seed(AgtHomologationSeeder::class);

    $legalEntity = LegalEntity::query()
        ->where('tax_identification_number', $taxIdentificationNumber)
        ->firstOrFail();

    expect($legalEntity->legal_name)->toBe($legalName)
        ->and($legalEntity->trade_name)->toBe($alias);
})->with([
    'HML-T01' => ['HML-T01', '5000413178', 'NIF TESTE PROJECTO SIGT'],
    'HML-T02' => ['HML-T02', '5001441337', 'NIF DE TESTE IIRS - NAO RESIDENTE'],
    'HML-T03' => ['HML-T03', '5000471283', 'PROJECTO SIGT - NIF TESTE - DRT'],
    'HML-T04' => ['HML-T04', '5000537039', 'SONHE TESTE'],
    'HML-T05' => ['HML-T05', '5000930091', 'ESTE PETROLIFERO'],
]);

test('it rejects an official NIF paired with the wrong alias', function () {
    config()->set('agt.homologation_fixture.alias', 'HML-T02');

    $this->seed(AgtHomologationSeeder::class);
})->throws(InvalidArgumentException::class, 'must match the official AGT test identity configured for HML-T02');

test('it does not accept a representative NIF as the sandbox company', function () {
    config()->set('agt.homologation_fixture.tax_identification_number', '003000000LA999');

    $this->seed(AgtHomologationSeeder::class);
})->throws(InvalidArgumentException::class, 'must match the official AGT test identity configured for HML-T01');
