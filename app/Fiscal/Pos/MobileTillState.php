<?php

namespace App\Fiscal\Pos;

use App\Billing\SubscriptionAccess;
use App\Models\LegalEntity;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\User;
use App\PosSessionStatus;
use App\SubscriptionFeature;
use Illuminate\Support\Facades\Gate;

/**
 * What the phone is told about the till: whether this person can sell, on which
 * tills, and how their shift stands. The figures come from the web's presenters.
 */
final readonly class MobileTillState
{
    public function __construct(
        private PosPresenter $presenter,
        private PosSeriesResolver $series,
        private PosSessionSummary $summary,
        private SubscriptionAccess $subscription,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(LegalEntity $legalEntity, User $user): array
    {
        $registers = $legalEntity->posRegisters()
            ->with(['establishment', 'openSession.openedBy'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $session = PosSession::query()
            ->with(['register', 'establishment'])
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->where('opened_by_user_id', $user->id)
            ->where('status', PosSessionStatus::Open)
            ->first();

        $reason = match (true) {
            ! Gate::allows('create', PosSession::class) => 'A sua função nesta empresa não permite vender.',
            ! $this->subscription->allows($legalEntity->workspace_id, SubscriptionFeature::Pos) => 'O plano actual não inclui o ponto de venda. Veja a assinatura em facturac.ao.',
            ! $user->hasEnabledTwoFactorAuthentication() => 'Para vender, active a autenticação em dois passos em facturac.ao.',
            $registers->isEmpty() => 'Esta empresa ainda não tem caixas. Crie uma em facturac.ao.',
            default => null,
        };

        return [
            'can_sell' => $reason === null,
            'reason' => $reason,
            'payment_methods' => PosPaymentMethods::options(),
            'final_consumer' => FinalConsumer::props(),
            'registers' => array_values($registers->map(fn (PosRegister $register): array => [
                'public_id' => $register->public_id,
                'name' => $register->name,
                'establishment_name' => $register->establishment->name,
                'has_series' => $this->series->exists($legalEntity, $register->establishment),
                'open_session' => $register->openSession instanceof PosSession
                    ? $this->presenter->openSessionOf($register->openSession, $user->id)
                    : null,
            ])->all()),
            'session' => $session instanceof PosSession ? $this->session($legalEntity, $session) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function session(LegalEntity $legalEntity, PosSession $session): array
    {
        return [
            'public_id' => $session->public_id,
            'opened_at' => $session->opened_at->toIso8601String(),
            'opening_float_minor' => $session->opening_float_minor,
            'currency_code' => $session->currency_code,
            'register_name' => $session->register->name,
            'establishment_name' => $session->establishment->name,
            'series_available' => $this->series->exists($legalEntity, $session->establishment),
            'summary' => $this->presenter->tillSummary($this->summary->forSession($session)),
            'cash_movements' => array_values($session->cashMovements()
                ->latest('id')
                ->get()
                ->map(fn ($movement): array => $this->presenter->cashMovement($movement))
                ->all()),
            'recent_sales' => array_map(
                fn (array $sale): array => array_intersect_key($sale, array_flip([
                    'public_id', 'document_public_id', 'document_no', 'customer_name', 'total_minor', 'payment_method_label', 'issued_at',
                ])),
                $this->presenter->sales($session->sales()->with('fiscalDocument')->latest('id')->limit(10)->get()),
            ),
        ];
    }
}
