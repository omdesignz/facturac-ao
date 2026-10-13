<?php

namespace App\Fiscal\Agt;

use App\AgtConnectionStatus;
use App\AgtEnvironment;
use App\FiscalDocumentType;
use App\FiscalSeriesContingency;
use App\FiscalSeriesStatus;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final readonly class CompanySetupProgress
{
    public function __construct(private AgtConnectionReadiness $readiness) {}

    /** @return array{ready_for_draft: bool, environment: string, production_ready: bool, next_step: string, items: list<array{key: string, label: string, detail: string, done: bool}>} */
    public function evaluate(?LegalEntity $entity, ?Establishment $establishment, User $user): array
    {
        $connection = $entity?->agtConnections()->where('environment', AgtEnvironment::Homologation)->first();
        $profileReady = $entity?->onboarding_completed_at !== null && $establishment?->is_active === true;
        $localReady = $entity !== null && $this->readiness->evaluate($connection, $entity, $establishment, $user)['complete'];
        $verified = $connection?->status === AgtConnectionStatus::Verified && $connection->verified_at !== null;
        $hasSeries = $connection !== null && $connection->fiscalSeries()
            ->where('workspace_id', $entity->workspace_id)
            ->where('legal_entity_id', $entity->id)
            ->where('environment', AgtEnvironment::Homologation->value)
            ->where('document_type', FiscalDocumentType::Invoice)
            ->where('series_year', now('Africa/Luanda')->year)
            ->whereIn('status', [FiscalSeriesStatus::Open, FiscalSeriesStatus::InUse])
            ->where('contingency_indicator', FiscalSeriesContingency::Normal)
            ->where('invoicing_method', 'FESF')
            ->whereColumn('next_number', '>=', 'first_authorized_number')
            ->whereColumn('next_number', '<=', 'last_authorized_number')
            ->where(fn ($query) => $query->whereNull('last_document_date')->orWhereDate('last_document_date', '<=', now('Africa/Luanda')->toDateString()))
            ->whereHas('establishment', fn ($query) => $query->where('is_active', true)->where('legal_entity_id', $entity->id)->where('workspace_id', $entity->workspace_id))
            ->exists();
        $items = [
            ['key' => 'company', 'label' => 'Dados da empresa', 'detail' => 'Nome, NIF, regime fiscal, actividade e morada da sede guardados.', 'done' => $profileReady],
            ['key' => 'security', 'label' => 'Proteger a conta', 'detail' => 'Active a autenticação de dois factores nas definições de segurança.', 'done' => $user->hasEnabledTwoFactorAuthentication()],
            ['key' => 'association', 'label' => 'Associar o acesso', 'detail' => 'Confirme a associação preparada para o seu NIF. As chaves ficam no servidor.', 'done' => $localReady],
            ['key' => 'verification', 'label' => 'Verificar a ligação', 'detail' => 'Uma consulta à AGT confirma o acesso no ambiente de testes; não emite facturas.', 'done' => $localReady && $verified],
            ['key' => 'series', 'label' => 'Série para a primeira factura', 'detail' => 'Importe uma série FT autorizada, normal, do ano actual e com números disponíveis.', 'done' => $verified && $hasSeries],
        ];
        if (! Gate::forUser($user)->allows('create', FiscalDocument::class)) {
            $items[] = ['key' => 'permission', 'label' => 'Permissão para preparar facturas', 'detail' => 'Peça ao administrador acesso à preparação de documentos.', 'done' => false];
        }
        $next = collect($items)->first(fn (array $item): bool => ! $item['done']);

        return [
            'ready_for_draft' => $next === null,
            'environment' => 'homologation',
            'production_ready' => false,
            'next_step' => $next['key'] ?? 'draft',
            'items' => $items,
        ];
    }
}
