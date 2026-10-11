<?php

namespace App\Actions;

use App\Exceptions\BillingActionRefused;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\PosSessionStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Starts a shift: one person, one till, a counted float.
 *
 * The register row is locked while the checks run so two tabs cannot both see a
 * free till; the partial unique indexes on the table back that up for the case
 * the lock cannot cover.
 */
final readonly class OpenPosSession
{
    private const REGISTER_BUSY = 'Esta caixa já tem um turno aberto. Feche-o antes de abrir outro.';

    private const USER_BUSY = 'Já tem um turno aberto. Feche-o antes de abrir outro.';

    public function execute(PosRegister $register, User $user, int $openingFloatMinor): PosSession
    {
        if ($openingFloatMinor < 0) {
            throw BillingActionRefused::because('O fundo de caixa não pode ser negativo.');
        }

        try {
            return DB::transaction(fn (): PosSession => $this->open($register, $user, $openingFloatMinor));
        } catch (UniqueConstraintViolationException) {
            // Raised outside the transaction on purpose: on PostgreSQL the
            // violation aborts it, so it can only be answered once it is gone.
            throw BillingActionRefused::because($this->whyBusy($register, $user) ?? self::REGISTER_BUSY);
        }
    }

    private function open(PosRegister $register, User $user, int $openingFloatMinor): PosSession
    {
        $locked = PosRegister::query()
            ->with(['establishment', 'legalEntity'])
            ->whereKey($register->id)
            ->lockForUpdate()
            ->firstOrFail();

        $isMember = WorkspaceMembership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $locked->workspace_id)
            ->where('is_active', true)
            ->exists();

        if (! $isMember) {
            throw BillingActionRefused::because('Esta caixa pertence a outra empresa.');
        }

        if (! $locked->is_active) {
            throw BillingActionRefused::because('Esta caixa está desactivada.');
        }

        if (! $locked->establishment->is_active) {
            throw BillingActionRefused::because('O estabelecimento desta caixa está desactivado.');
        }

        $busy = $this->whyBusy($locked, $user);

        if ($busy !== null) {
            throw BillingActionRefused::because($busy);
        }

        $session = PosSession::query()->create([
            'workspace_id' => $locked->workspace_id,
            'legal_entity_id' => $locked->legal_entity_id,
            'pos_register_id' => $locked->id,
            'establishment_id' => $locked->establishment_id,
            'opened_by_user_id' => $user->id,
            'status' => PosSessionStatus::Open,
            'currency_code' => $locked->legalEntity->currency_code,
            'opening_float_minor' => $openingFloatMinor,
            'opened_at' => now(),
        ]);

        activity('pos-session')
            ->causedBy($user)
            ->performedOn($session)
            ->event('pos-session-opened')
            ->withProperties(['opening_float_minor' => $openingFloatMinor])
            ->log('Turno aberto.');

        return $session;
    }

    private function whyBusy(PosRegister $register, User $user): ?string
    {
        $registerBusy = PosSession::query()
            ->where('pos_register_id', $register->id)
            ->where('status', PosSessionStatus::Open)
            ->exists();

        if ($registerBusy) {
            return self::REGISTER_BUSY;
        }

        $userBusy = PosSession::query()
            ->where('legal_entity_id', $register->legal_entity_id)
            ->where('opened_by_user_id', $user->id)
            ->where('status', PosSessionStatus::Open)
            ->exists();

        return $userBusy ? self::USER_BUSY : null;
    }
}
