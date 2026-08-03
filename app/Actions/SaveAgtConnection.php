<?php

namespace App\Actions;

use App\AgtConnectionStatus;
use App\AgtEnvironment;
use App\Fiscal\Agt\Contracts\JwsSigner;
use App\Fiscal\Agt\Exceptions\SigningKeyUnavailable;
use App\Models\AgtConnection;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class SaveAgtConnection
{
    public function __construct(private JwsSigner $jwsSigner) {}

    /**
     * @param array{
     *     basic_auth_username: string|null,
     *     basic_auth_password: string|null,
     *     product_id: string,
     *     product_version: string,
     *     software_validation_number: string,
     *     establishment_number: string,
     *     software_key_reference: string,
     *     taxpayer_key_reference: string
     * } $profile
     */
    public function execute(LegalEntity $legalEntity, User $user, array $profile): AgtConnection
    {
        return DB::transaction(function () use ($legalEntity, $user, $profile): AgtConnection {
            $connection = AgtConnection::query()
                ->where('legal_entity_id', $legalEntity->id)
                ->where('environment', AgtEnvironment::Homologation)
                ->lockForUpdate()
                ->first() ?? new AgtConnection([
                    'workspace_id' => $legalEntity->workspace_id,
                    'legal_entity_id' => $legalEntity->id,
                    'environment' => AgtEnvironment::Homologation,
                    'schema_version' => (string) config('agt.schema_version', '1.2'),
                ]);

            $connection->fill([
                'product_id' => $profile['product_id'],
                'product_version' => $profile['product_version'],
                'software_validation_number' => $profile['software_validation_number'],
                'establishment_number' => $profile['establishment_number'],
                'software_key_reference' => $profile['software_key_reference'],
                'taxpayer_key_reference' => $profile['taxpayer_key_reference'],
                'software_key_fingerprint' => $this->fingerprint($profile['software_key_reference']),
                'taxpayer_key_fingerprint' => $this->fingerprint($profile['taxpayer_key_reference']),
            ]);

            if (filled($profile['basic_auth_username'])) {
                $connection->basic_auth_username = $profile['basic_auth_username'];
            }

            if (filled($profile['basic_auth_password'])) {
                $connection->basic_auth_password = $profile['basic_auth_password'];
            }

            $ready = $this->isReady($connection);
            $connection->status = $ready
                ? AgtConnectionStatus::Ready
                : AgtConnectionStatus::Draft;
            $connection->configured_at = $ready ? now() : null;
            $connection->verified_at = null;
            $connection->last_failed_at = null;
            $changedFields = array_keys($connection->getDirty());
            $connection->save();

            activity('agt-connection')
                ->causedBy($user)
                ->performedOn($connection)
                ->event('agt-connection-saved')
                ->withProperties([
                    'workspace_id' => $connection->workspace_id,
                    'legal_entity_id' => $connection->legal_entity_id,
                    'environment' => $connection->environment->value,
                    'status' => $connection->status->value,
                    'changed_fields' => $this->safeChangedFields($changedFields),
                ])
                ->log('Configuração da ligação AGT actualizada.');

            return $connection->fresh() ?? $connection;
        });
    }

    private function fingerprint(string $keyReference): ?string
    {
        try {
            return $this->jwsSigner->fingerprint($keyReference);
        } catch (SigningKeyUnavailable) {
            return null;
        }
    }

    private function isReady(AgtConnection $connection): bool
    {
        return $connection->hasBasicCredentials()
            && filled($connection->product_id)
            && filled($connection->product_version)
            && filled($connection->software_validation_number)
            && filled($connection->establishment_number)
            && filled($connection->software_key_reference)
            && filled($connection->software_key_fingerprint)
            && filled($connection->taxpayer_key_reference)
            && filled($connection->taxpayer_key_fingerprint);
    }

    /**
     * @param  list<string>  $changedFields
     * @return list<string>
     */
    private function safeChangedFields(array $changedFields): array
    {
        $safeFields = [];

        foreach ($changedFields as $field) {
            $safeField = in_array($field, [
                'basic_auth_username',
                'basic_auth_password',
            ], true) ? 'basic_auth_credentials' : $field;

            if (! in_array($safeField, $safeFields, true)) {
                $safeFields[] = $safeField;
            }
        }

        return $safeFields;
    }
}
