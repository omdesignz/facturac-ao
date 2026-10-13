<?php

namespace App\Fiscal\Agt;

use App\Actions\SaveAgtConnection;
use App\AgtEnvironment;
use App\Fiscal\Agt\Contracts\JwsSigner;
use App\Fiscal\Agt\Exceptions\SigningKeyUnavailable;
use App\Models\AgtConnection;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class PreparedTaxpayerAssociation
{
    public function __construct(private SaveAgtConnection $saveConnection, private JwsSigner $signer) {}

    /**
     * @return array{basic_auth_username: string, basic_auth_password: string, product_id: string, product_version: string, software_validation_number: string, establishment_number: string, software_key_reference: string, taxpayer_key_reference: string, software_key_fingerprint: string, taxpayer_key_fingerprint: string}|null
     */
    private function profile(LegalEntity $entity): ?array
    {
        $entries = config('agt.prepared_associations', []);
        if (! is_array($entries)) {
            return null;
        }

        $matches = array_values(array_filter($entries, fn (mixed $entry): bool => is_array($entry)
            && ($entry['workspace_public_id'] ?? null) === $entity->workspace->public_id
            && ($entry['legal_entity_public_id'] ?? null) === $entity->public_id
            && ($entry['tax_identification_number'] ?? null) === $entity->tax_identification_number
            && ($entry['environment'] ?? null) === AgtEnvironment::Homologation->value));

        if (count($matches) !== 1 || ! AgtEnvironment::Homologation->isEnabled()) {
            return null;
        }

        $entry = $matches[0];
        $rules = [
            'basic_auth_username' => ['required', 'string', 'max:255'],
            'basic_auth_password' => ['required', 'string', 'max:1024'],
            'product_id' => ['required', 'string', 'max:255'],
            'product_version' => ['required', 'string', 'max:64'],
            'software_validation_number' => ['required', 'string', 'max:255'],
            'establishment_number' => ['required', 'string', 'max:255'],
            'software_key_reference' => ['required', 'string', 'max:255'],
            'taxpayer_key_reference' => ['required', 'string', 'max:255'],
            'software_key_fingerprint' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'taxpayer_key_fingerprint' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
        if (Validator::make($entry, $rules)->fails()) {
            return null;
        }

        return [
            'basic_auth_username' => $entry['basic_auth_username'],
            'basic_auth_password' => $entry['basic_auth_password'],
            'product_id' => $entry['product_id'],
            'product_version' => $entry['product_version'],
            'software_validation_number' => $entry['software_validation_number'],
            'establishment_number' => $entry['establishment_number'],
            'software_key_reference' => $entry['software_key_reference'],
            'taxpayer_key_reference' => $entry['taxpayer_key_reference'],
            'software_key_fingerprint' => $entry['software_key_fingerprint'],
            'taxpayer_key_fingerprint' => $entry['taxpayer_key_fingerprint'],
        ];
    }

    public function available(LegalEntity $entity): bool
    {
        return $this->profile($entity) !== null;
    }

    public function associate(LegalEntity $entity, User $user): AgtConnection
    {
        Gate::forUser($user)->authorize('manageAgtConnection', $entity);

        return DB::transaction(function () use ($entity, $user): AgtConnection {
            $entity = LegalEntity::query()->lockForUpdate()->findOrFail($entity->id);
            $profile = $this->profile($entity);
            if ($profile === null) {
                throw ValidationException::withMessages(['association_confirmed' => 'A associação ainda não foi preparada para esta empresa. Peça ao apoio Facturac que confirme o NIF e a custódia da chave. Depois volte a tentar.']);
            }

            try {
                foreach (['software', 'taxpayer'] as $kind) {
                    if (! hash_equals($profile[$kind.'_key_fingerprint'], $this->signer->fingerprint($profile[$kind.'_key_reference']))) {
                        throw new SigningKeyUnavailable('Prepared key changed.');
                    }
                }
            } catch (SigningKeyUnavailable) {
                throw ValidationException::withMessages(['association_confirmed' => 'A chave preparada não está disponível ou foi alterada. O apoio Facturac deve rever a custódia antes de tentar novamente.']);
            }

            $existing = $entity->agtConnections()->where('environment', AgtEnvironment::Homologation)->lockForUpdate()->first();
            if ($existing !== null) {
                foreach ($profile as $field => $value) {
                    if ($existing->{$field} !== $value) {
                        throw ValidationException::withMessages(['association_confirmed' => 'Já existe uma ligação diferente. O apoio Facturac deve revê-la; não substituímos o acesso existente.']);
                    }
                }

                return $existing;
            }

            $connection = $this->saveConnection->execute($entity, $user, $profile);
            activity('agt-connection')->causedBy($user)->performedOn($connection)
                ->event('taxpayer-access-associated')
                ->withProperties(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id, 'environment' => 'homologation'])
                ->log('Associação preparada confirmada pelo responsável da empresa.');

            return $connection;
        });
    }
}
