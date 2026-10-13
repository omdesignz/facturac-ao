<?php

namespace App\Fiscal\Agt;

use App\Fiscal\Agt\Contracts\SigningKeyResolver;
use App\Fiscal\Agt\Exceptions\SigningKeyUnavailable;
use App\Models\AgtConnection;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\User;

final readonly class AgtConnectionReadiness
{
    public function __construct(private SigningKeyResolver $keyResolver) {}

    /**
     * @return array{
     *     complete: bool,
     *     items: list<array{key: string, label: string, detail: string, done: bool}>
     * }
     */
    public function evaluate(
        ?AgtConnection $connection,
        LegalEntity $legalEntity,
        ?Establishment $establishment,
        User $user,
    ): array {
        $softwareConfigured = $connection !== null
            && filled($connection->product_id)
            && filled($connection->product_version)
            && filled($connection->software_validation_number);
        $items = [
            [
                'key' => 'schema',
                'label' => 'Configuração compatível com a AGT',
                'detail' => 'Configuração guardada no contrato actualmente aceite pela AGT.',
                'done' => $connection?->schema_version === '2.0',
            ],
            [
                'key' => 'company',
                'label' => 'Identidade fiscal',
                'detail' => 'Perfil legal concluído e NIF disponível.',
                'done' => $legalEntity->onboarding_completed_at !== null
                    && filled($legalEntity->tax_identification_number),
            ],
            [
                'key' => 'establishment',
                'label' => 'Estabelecimento',
                'detail' => 'Sede local e número atribuído pela AGT identificados.',
                'done' => $establishment?->is_active === true
                    && filled($connection?->establishment_number),
            ],
            [
                'key' => 'credentials',
                'label' => 'Acesso API do produtor',
                'detail' => 'Preparado pelo apoio Facturac. Não use a palavra-passe do Portal do Contribuinte.',
                'done' => $connection?->hasBasicCredentials() === true,
            ],
            [
                'key' => 'software',
                'label' => 'Identificação do software',
                'detail' => 'Produto, versão e número de validação preparados pelo produtor. Não precisa de homologar o software.',
                'done' => $softwareConfigured,
            ],
            [
                'key' => 'software_key',
                'label' => 'Assinatura do Facturac',
                'detail' => 'O apoio Facturac prepara e protege a assinatura do software.',
                'done' => $this->keyIsAvailable($connection?->software_key_reference),
            ],
            [
                'key' => 'taxpayer_key',
                'label' => 'Assinatura da empresa',
                'detail' => 'O apoio confirma a chave emitida pela AGT para o seu NIF e guarda-a no cofre.',
                'done' => $this->keyIsAvailable($connection?->taxpayer_key_reference),
            ],
            [
                'key' => 'mfa',
                'label' => 'Conta protegida',
                'detail' => 'Autenticação multifactor activa para operações sensíveis.',
                'done' => $user->hasEnabledTwoFactorAuthentication(),
            ],
            [
                'key' => 'environment',
                'label' => 'Modo de testes',
                'detail' => 'Apenas homologação está autorizada nesta fase.',
                'done' => $connection?->environment->isEnabled() === true
                    && $connection->environment->value === 'homologation',
            ],
        ];

        return [
            'complete' => collect($items)->every(
                fn (array $item): bool => $item['done'],
            ),
            'items' => $items,
        ];
    }

    private function keyIsAvailable(?string $keyReference): bool
    {
        if (blank($keyReference)) {
            return false;
        }

        try {
            $this->keyResolver->resolve($keyReference);

            return true;
        } catch (SigningKeyUnavailable) {
            return false;
        }
    }
}
