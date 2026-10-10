<?php

use App\Actions\IssueFiscalDocument;
use App\Actions\SaveFiscalDocumentDraft;
use App\AgtConnectionStatus;
use App\Fiscal\Agt\Contracts\JwsSigner;
use App\Models\AgtConnection;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalSeries;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @return array<string,mixed> */
function billingSummaryFixture(array $scopes = ['analytics:billing:read'], string $environment = 'production'): array
{
    $integration = Integration::factory()->create(['environment' => $environment]);
    $selector = (string) Str::ulid();
    $secret = 'fcr1.'.$selector.'.'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $credential = IntegrationCredential::factory()->create(['integration_id' => $integration->id, 'public_id' => $selector, 'created_at' => now()->subDays(2), 'secret_hash' => hash('sha256', $secret)]);
    foreach ($scopes as $scope) {
        DB::table('integration_scopes')->insert(['integration_id' => $integration->id, 'scope' => $scope]);
        DB::table('integration_credential_scopes')->insert(['credential_id' => $credential->id, 'scope' => $scope]);
    }
    $entity = LegalEntity::findOrFail($integration->legal_entity_id);
    $establishment = Establishment::factory()->headOffice()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id]);
    $parameters = ['workspacePublicId' => $entity->workspace->public_id, 'entityPublicId' => $entity->public_id, 'environment' => $environment];
    $url = route('integrations.v2.analytics.billing.show', $parameters);

    return compact('integration', 'credential', 'entity', 'parameters', 'url', 'secret', 'establishment');
}

function billingDocument(array $f, array $attributes = []): FiscalDocument
{
    return FiscalDocument::factory()->issued()->create([...['workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id,
        'establishment_id' => $f['establishment']->id,
        'environment' => $f['integration']->environment, 'document_no' => 'FT BILL/'.random_int(1, 1000000000),
        'document_date' => '2024-02-10', 'gross_total_minor' => 10000, 'net_total_minor' => 8600, 'tax_payable_minor' => 1400], ...$attributes]);
}

/** @return array<string,mixed> */
function billingIssueProfile(array $f, string $type = 'FT', string $date = '2024-02-10'): array
{
    return ['document_type' => $type, 'document_date' => $date, 'due_date' => null, 'currency_code' => 'AOA',
        'establishment_public_id' => $f['establishment']->public_id, 'customer_public_id' => null,
        'customer' => ['name' => 'Billing domain client', 'tax_identification_number' => '5411111111', 'country_code' => 'AO', 'address_line' => null],
        'notes' => null, 'references_document_public_id' => null, 'adjustment_reason' => in_array($type, ['NC', 'ND'], true) ? 'Adjustment' : null,
        'payment_method' => 'TB', 'payment_date' => $date,
        'lines' => [['operation_type' => 'SG', 'operation_date' => $date, 'product_code' => 'SERVICE', 'product_description' => 'Service',
            'quantity' => '1', 'unit_of_measure' => 'UN', 'unit_price' => '100', 'discount_percentage' => '0',
            'tax_type' => 'IVA', 'tax_code' => 'NOR', 'tax_percentage' => '14', 'tax_exemption_code' => null]]];
}

function billingPrepareIssuance(array $f, string $type, string $date = '2024-02-10'): FiscalSeries
{
    config(['agt.environments.production.enabled' => true]);
    app()->instance(JwsSigner::class, new class implements JwsSigner
    {
        public function sign(array $payload, string $keyReference): string
        {
            return 'billing.synthetic.signature';
        }

        public function fingerprint(string $keyReference): string
        {
            return hash('sha256', $keyReference);
        }
    });
    $connection = AgtConnection::firstOrCreate(['workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id, 'environment' => 'production'],
        AgtConnection::factory()->raw(['workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id, 'environment' => 'production', 'status' => AgtConnectionStatus::Verified, 'software_key_reference' => 'software/billing', 'taxpayer_key_reference' => 'taxpayer/billing',
            'software_key_fingerprint' => hash('sha256', 'software/billing'), 'taxpayer_key_fingerprint' => hash('sha256', 'taxpayer/billing')]));

    return FiscalSeries::firstOrCreate(['workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id, 'environment' => 'production', 'series_code' => $type.'BILL'.substr($date, 0, 4)],
        FiscalSeries::factory()->raw(['workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id, 'environment' => 'production', 'series_code' => $type.'BILL'.substr($date, 0, 4), 'establishment_id' => $f['establishment']->id, 'agt_connection_id' => $connection->id,
            'document_type' => $type, 'series_year' => (int) substr($date, 0, 4)]));
}

function billingIssue(array $f, array $profile): FiscalDocument
{
    $user = User::findOrFail($f['integration']->sponsor_user_id);
    $series = billingPrepareIssuance($f, $profile['document_type'], $profile['document_date']);
    $draft = app(SaveFiscalDocumentDraft::class)->execute($f['entity'], $user, $profile);
    app(IssueFiscalDocument::class)->execute($draft, $user, $series->public_id, $draft->revision);

    return $draft->fresh();
}
