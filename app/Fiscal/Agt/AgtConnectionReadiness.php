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
                'done' => $establishment !== null
                    && filled($connection?->establishment_number),
            ],
            [
                'key' => 'credentials',
                'label' => 'Credenciais de serviço',
                'detail' => 'Utilizador e palavra-passe guardados de forma cifrada.',
                'done' => $connection?->hasBasicCredentials() === true,
            ],
            [
                'key' => 'software',
                'label' => 'Software certificado',
                'detail' => 'Produto, versão e número de validação configurados.',
                'done' => $softwareConfigured,
            ],
            [
                'key' => 'software_key',
                'label' => 'Chave do software',
                'detail' => 'Referência resolve para uma chave RSA segura de, no mínimo, 2048 bits.',
                'done' => $this->keyIsAvailable($connection?->software_key_reference),
            ],
            [
                'key' => 'taxpayer_key',
                'label' => 'Chave do contribuinte',
                'detail' => 'A chave fornecida pela AGT permanece no cofre local.',
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
                'label' => 'Ambiente isolado',
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
