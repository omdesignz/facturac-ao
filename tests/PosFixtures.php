<?php

use App\AgtConnectionStatus;
use App\Fiscal\Agt\Contracts\JwsSigner;
use App\FiscalDocumentType;
use App\FiscalSeriesContingency;
use App\FiscalSeriesStatus;
use App\Models\AgtConnection;
use App\Models\CatalogueItem;
use App\Models\Establishment;
use App\Models\FiscalSeries;
use App\Models\LegalEntity;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Laravel\Fortify\Fortify;

/**
 * A company that can sell at a till: a configured legal entity with a head
 * office, a verified AGT connection on schema 2.0, an open FR series, a
 * register, an owner with MFA, and a synthetic signer in place of the key store.
 *
 * @return array{
 *     owner: User,
 *     legalEntity: LegalEntity,
 *     establishment: Establishment,
 *     connection: AgtConnection,
 *     series: FiscalSeries,
 *     register: PosRegister
 * }
 */
function posCompany(): array
{
    app()->instance(JwsSigner::class, new class implements JwsSigner
    {
        public function sign(array $payload, string $keyReference): string
        {
            return 'pos.synthetic.signature';
        }

        public function fingerprint(string $keyReference): string
        {
            return hash('sha256', $keyReference);
        }
    });

    $owner = posUserWithMfa(User::factory()->withWorkspace('VAP Loja')->create());
    $workspace = $owner->currentWorkspace()->firstOrFail();
    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
        'legal_name' => 'VAP Loja, Lda.',
        'trade_name' => 'Loja VAP',
        'tax_identification_number' => '5000000077',
    ]);
    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Loja central',
    ]);
    $connection = AgtConnection::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'status' => AgtConnectionStatus::Verified,
        'software_key_reference' => 'software/pos',
        'taxpayer_key_reference' => 'taxpayer/pos',
        'software_key_fingerprint' => hash('sha256', 'software/pos'),
        'taxpayer_key_fingerprint' => hash('sha256', 'taxpayer/pos'),
        'verified_at' => now(),
    ]);
    $series = posSeries($legalEntity, $establishment, $connection);
    $register = PosRegister::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'establishment_id' => $establishment->id,
        'name' => 'Caixa 1',
    ]);

    return compact('owner', 'legalEntity', 'establishment', 'connection', 'series', 'register');
}

function posUserWithMfa(User $user): User
{
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('pos-secret'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
            json_encode(['pos-recovery'], JSON_THROW_ON_ERROR),
        ),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function posSeries(
    LegalEntity $legalEntity,
    Establishment $establishment,
    AgtConnection $connection,
    array $overrides = [],
): FiscalSeries {
    return FiscalSeries::factory()->create([
        'workspace_id' => $legalEntity->workspace_id,
        'legal_entity_id' => $legalEntity->id,
        'establishment_id' => $establishment->id,
        'agt_connection_id' => $connection->id,
        'series_code' => 'FR'.now('Africa/Luanda')->format('y').'POS',
        'series_year' => (int) now('Africa/Luanda')->format('Y'),
        'document_type' => FiscalDocumentType::InvoiceReceipt,
        'status' => FiscalSeriesStatus::Open,
        'contingency_indicator' => FiscalSeriesContingency::Normal,
        'invoicing_method' => 'FESF',
        'first_authorized_number' => 1,
        'last_authorized_number' => 500,
        'next_number' => 1,
        ...$overrides,
    ]);
}

/**
 * Another person in the same company, with MFA and the given role.
 */
function posMember(array $company, WorkspaceRole $role): User
{
    $user = posUserWithMfa(User::factory()->create([
        'current_workspace_id' => $company['legalEntity']->workspace_id,
    ]));

    WorkspaceMembership::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function posItem(array $company, array $overrides = []): CatalogueItem
{
    return CatalogueItem::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'type' => 'product',
        'unit_of_measure' => 'UN',
        'unit_price_minor' => 10_000,
        'tax_type' => 'IVA',
        'tax_code' => 'NOR',
        'tax_percentage' => '14.00',
        'tax_exemption_code' => null,
        ...$overrides,
    ]);
}

function posOpenSession(array $company, ?User $user = null, int $float = 0, ?PosRegister $register = null): PosSession
{
    $register ??= $company['register'];

    return PosSession::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'pos_register_id' => $register->id,
        'establishment_id' => $register->establishment_id,
        'opened_by_user_id' => ($user ?? $company['owner'])->id,
        'opening_float_minor' => $float,
    ]);
}

/** @return array<string, int> */
function posPasswordConfirmed(): array
{
    return ['auth.password_confirmed_at' => time()];
}

/**
 * A sale body as the till sends it.
 *
 * @param  list<array{0: CatalogueItem, 1?: string, 2?: string}>  $lines  item, quantity, discount
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function posSalePayload(array $lines, int $expectedTotalMinor, array $overrides = []): array
{
    return [
        'client_key' => 'sale-'.str_pad((string) random_int(1, 999999999), 12, '0'),
        'customer_public_id' => null,
        'customer' => null,
        'lines' => array_map(fn (array $line): array => [
            'catalogue_item_public_id' => $line[0]->public_id,
            'quantity' => $line[1] ?? '1',
            'discount_percentage' => $line[2] ?? '0',
        ], $lines),
        'payment_method' => 'NU',
        'tendered_minor' => $expectedTotalMinor,
        'expected_total_minor' => $expectedTotalMinor,
        ...$overrides,
    ];
}
