<?php

use App\Actions\ClosePosSession;
use App\Actions\OpenPosSession;
use App\Actions\RecordPosCashMovement;
use App\Exceptions\BillingActionRefused;
use App\Fiscal\Pos\PosSessionSummary;
use App\Models\PosCashMovement;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\PosCashMovementType;
use App\PosSessionStatus;
use App\WorkspaceRole;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../PosFixtures.php';

// -------------------------------------------------------------------- opening

test('opening a shift needs the password to have been confirmed', function () {
    $company = posCompany();

    $this->actingAs($company['owner'])
        ->post(route('pos.sessions.store'), [
            'pos_register_public_id' => $company['register']->public_id,
            'opening_float' => '5000',
        ])
        ->assertRedirect(route('password.confirm'));

    expect(PosSession::query()->count())->toBe(0);
});

test('opening a shift needs multi-factor authentication', function () {
    $company = posCompany();
    $company['owner']->forceFill([
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($company['owner'])
        ->withSession(posPasswordConfirmed())
        ->post(route('pos.sessions.store'), [
            'pos_register_public_id' => $company['register']->public_id,
            'opening_float' => '5000',
        ])
        ->assertRedirect(route('settings.security'));

    expect(PosSession::query()->count())->toBe(0);
});

test('a confirmed user opens a shift with a counted float in the company currency', function () {
    $company = posCompany();

    $this->actingAs($company['owner'])
        ->withSession(posPasswordConfirmed())
        ->post(route('pos.sessions.store'), [
            'pos_register_public_id' => $company['register']->public_id,
            'opening_float' => '5000,50',
        ])
        ->assertRedirect(route('pos.show'))
        ->assertSessionHas('success');

    $session = PosSession::query()->sole();

    expect($session->status)->toBe(PosSessionStatus::Open)
        ->and($session->opening_float_minor)->toBe(500_050)
        ->and($session->currency_code)->toBe($company['legalEntity']->currency_code)
        ->and($session->opened_by_user_id)->toBe($company['owner']->id)
        ->and($session->establishment_id)->toBe($company['establishment']->id)
        ->and($session->opened_at)->not->toBeNull();
});

test('a float is a decimal with at most two places', function (string $float) {
    $company = posCompany();

    $this->actingAs($company['owner'])
        ->withSession(posPasswordConfirmed())
        ->post(route('pos.sessions.store'), [
            'pos_register_public_id' => $company['register']->public_id,
            'opening_float' => $float,
        ])
        ->assertSessionHasErrors('opening_float');
})->with(['-5', '1.234', 'abc', '']);

test('a register that is not active, or has no active establishment, cannot be opened', function () {
    $company = posCompany();
    $company['register']->forceFill(['is_active' => false])->save();

    $this->actingAs($company['owner'])
        ->withSession(posPasswordConfirmed())
        ->post(route('pos.sessions.store'), [
            'pos_register_public_id' => $company['register']->public_id,
            'opening_float' => '0',
        ])
        ->assertSessionHas('error', 'Esta caixa está desactivada.');

    $company['register']->forceFill(['is_active' => true])->save();
    $company['establishment']->forceFill(['is_active' => false])->save();

    $this->actingAs($company['owner'])
        ->withSession(posPasswordConfirmed())
        ->post(route('pos.sessions.store'), [
            'pos_register_public_id' => $company['register']->public_id,
            'opening_float' => '0',
        ])
        ->assertSessionHas('error');

    expect(PosSession::query()->count())->toBe(0);
});

test('a register that already has an open shift cannot be opened again', function () {
    $company = posCompany();
    $other = posMember($company, WorkspaceRole::Billing);
    posOpenSession($company, $other);

    $this->actingAs($company['owner'])
        ->withSession(posPasswordConfirmed())
        ->post(route('pos.sessions.store'), [
            'pos_register_public_id' => $company['register']->public_id,
            'opening_float' => '0',
        ])
        ->assertSessionHas('error', 'Esta caixa já tem um turno aberto. Feche-o antes de abrir outro.');

    expect(PosSession::query()->count())->toBe(1);
});

test('one person cannot hold two open shifts', function () {
    $company = posCompany();
    posOpenSession($company);
    $second = PosRegister::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'establishment_id' => $company['establishment']->id,
        'name' => 'Caixa 2',
    ]);

    $this->actingAs($company['owner'])
        ->withSession(posPasswordConfirmed())
        ->post(route('pos.sessions.store'), [
            'pos_register_public_id' => $second->public_id,
            'opening_float' => '0',
        ])
        ->assertSessionHas('error', 'Já tem um turno aberto. Feche-o antes de abrir outro.');

    expect(PosSession::query()->count())->toBe(1);
});

test('a closed shift frees both the register and the person', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    app(ClosePosSession::class)->execute($session, $company['owner'], 0, null);

    $this->actingAs($company['owner'])
        ->withSession(posPasswordConfirmed())
        ->post(route('pos.sessions.store'), [
            'pos_register_public_id' => $company['register']->public_id,
            'opening_float' => '0',
        ])
        ->assertSessionHas('success');

    expect(PosSession::query()->count())->toBe(2);
});

test('the database itself refuses a second open shift on a register or for a person', function () {
    $company = posCompany();
    posOpenSession($company);

    $secondRegister = PosRegister::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'establishment_id' => $company['establishment']->id,
    ]);

    $otherCashier = posMember($company, WorkspaceRole::Billing);

    // Each attempt runs in its own savepoint: on PostgreSQL a violation aborts
    // the surrounding transaction, and the second attempt has to start clean.

    // Same register, different person.
    expect(fn () => DB::transaction(fn () => posOpenSession($company, $otherCashier)))
        ->toThrow(UniqueConstraintViolationException::class);

    // Same person, different register.
    expect(fn () => DB::transaction(fn () => posOpenSession($company, null, 0, $secondRegister)))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a viewer cannot open a shift', function () {
    $company = posCompany();
    $viewer = posMember($company, WorkspaceRole::Viewer);

    $this->actingAs($viewer)
        ->withSession(posPasswordConfirmed())
        ->post(route('pos.sessions.store'), [
            'pos_register_public_id' => $company['register']->public_id,
            'opening_float' => '0',
        ])
        ->assertForbidden();
});

test('a register of another company cannot be opened', function () {
    $company = posCompany();
    $other = posCompany();

    $this->actingAs($company['owner'])
        ->withSession(posPasswordConfirmed())
        ->post(route('pos.sessions.store'), [
            'pos_register_public_id' => $other['register']->public_id,
            'opening_float' => '0',
        ])
        ->assertSessionHasErrors('pos_register_public_id');

    expect(fn () => app(OpenPosSession::class)->execute($other['register'], $company['owner'], 0))
        ->toThrow(BillingActionRefused::class, 'Esta caixa pertence a outra empresa.');
});

// ---------------------------------------------------------------- cash movements

test('cash put in and taken out moves the cash expected in the drawer', function () {
    $company = posCompany();
    $session = posOpenSession($company, float: 10_000);

    $this->actingAs($company['owner'])
        ->post(route('pos.cash-movements.store', $session), [
            'type' => 'in', 'amount' => '500', 'reason' => 'Troco do banco',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->actingAs($company['owner'])
        ->post(route('pos.cash-movements.store', $session), [
            'type' => 'out', 'amount' => '200.50', 'reason' => 'Pagamento a fornecedor',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $summary = app(PosSessionSummary::class)->forSession($session);

    expect($summary['cash_in_minor'])->toBe(50_000)
        ->and($summary['cash_out_minor'])->toBe(20_050)
        ->and($summary['expected_cash_minor'])->toBe(10_000 + 50_000 - 20_050)
        ->and(PosCashMovement::query()->count())->toBe(2);
});

test('a cash movement far beyond any drawer is refused as a misread', function () {
    $company = posCompany();
    $session = posOpenSession($company);

    // A barcode read into the amount field.
    $this->actingAs($company['owner'])
        ->post(route('pos.cash-movements.store', $session), [
            'type' => 'in', 'amount' => '5601234500012', 'reason' => 'Reforço da manhã',
        ])
        ->assertSessionHasErrors('amount');

    expect(PosCashMovement::query()->count())->toBe(0);
});

test('a withdrawal larger than the drawer is refused', function () {
    $company = posCompany();
    $session = posOpenSession($company, float: 10_000);

    $this->actingAs($company['owner'])
        ->post(route('pos.cash-movements.store', $session), [
            'type' => 'out', 'amount' => '100.01', 'reason' => 'Demasiado',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(PosCashMovement::query()->count())->toBe(0);

    // Exactly what is there is fine.
    $this->actingAs($company['owner'])
        ->post(route('pos.cash-movements.store', $session), [
            'type' => 'out', 'amount' => '100', 'reason' => 'Tudo',
        ])
        ->assertSessionHas('success');

    expect(app(PosSessionSummary::class)->expectedCash($session))->toBe(0);
});

test('a movement needs a positive amount and a reason', function () {
    $company = posCompany();
    $session = posOpenSession($company);

    $this->actingAs($company['owner'])
        ->post(route('pos.cash-movements.store', $session), ['type' => 'in', 'amount' => '0', 'reason' => 'x'])
        ->assertSessionHasErrors(['amount', 'reason']);

    $this->actingAs($company['owner'])
        ->post(route('pos.cash-movements.store', $session), ['type' => 'sideways', 'amount' => '5', 'reason' => 'Motivo'])
        ->assertSessionHasErrors('type');
});

test('nothing can be recorded on a closed shift', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    app(ClosePosSession::class)->execute($session, $company['owner'], 0, null);

    $this->actingAs($company['owner'])
        ->post(route('pos.cash-movements.store', $session), [
            'type' => 'in', 'amount' => '10', 'reason' => 'Tarde demais',
        ])
        ->assertSessionHas('error', 'Este turno já foi fechado.');

    expect(PosCashMovement::query()->count())->toBe(0)
        ->and(fn () => app(RecordPosCashMovement::class)->execute(
            $session,
            $company['owner'],
            PosCashMovementType::In,
            100,
            'Direto',
        ))->toThrow(BillingActionRefused::class, 'Este turno já foi fechado.');
});

test('only the opener or a manager may move cash on a shift', function () {
    $company = posCompany();
    $cashier = posMember($company, WorkspaceRole::Billing);
    $colleague = posMember($company, WorkspaceRole::Accountant);
    $session = posOpenSession($company, $cashier);

    $this->actingAs($colleague)
        ->post(route('pos.cash-movements.store', $session), [
            'type' => 'in', 'amount' => '10', 'reason' => 'Intruso',
        ])
        ->assertForbidden();

    $this->actingAs($company['owner'])
        ->post(route('pos.cash-movements.store', $session), [
            'type' => 'in', 'amount' => '10', 'reason' => 'Reforço da gerência',
        ])
        ->assertSessionHas('success');
});

test('a shift from another company is a 404 for every write', function () {
    $company = posCompany();
    $other = posCompany();
    $theirs = posOpenSession($other);

    $this->actingAs($company['owner'])
        ->post(route('pos.cash-movements.store', $theirs), [
            'type' => 'in', 'amount' => '10', 'reason' => 'Intruso',
        ])
        ->assertNotFound();

    $this->actingAs($company['owner'])
        ->post(route('pos.sessions.close', $theirs), ['counted_cash' => '0'])
        ->assertNotFound();

    $this->actingAs($company['owner'])
        ->get(route('pos.sessions.show', $theirs))
        ->assertNotFound();
});

// -------------------------------------------------------------------- closing

test('closing compares the cash counted with the cash expected and freezes the summary', function () {
    $company = posCompany();
    $session = posOpenSession($company, float: 10_000);

    app(RecordPosCashMovement::class)->execute($session, $company['owner'], PosCashMovementType::In, 5_000, 'Reforço');

    $this->actingAs($company['owner'])
        ->post(route('pos.sessions.close', $session), [
            'counted_cash' => '145.00',
            'closing_notes' => '  Faltam 5 Kz  ',
        ])
        ->assertRedirect(route('pos.sessions.show', $session))
        ->assertSessionHas('success');

    $closed = $session->fresh();

    expect($closed->status)->toBe(PosSessionStatus::Closed)
        ->and($closed->expected_cash_minor)->toBe(15_000)
        ->and($closed->counted_cash_minor)->toBe(14_500)
        ->and($closed->cash_difference_minor)->toBe(-500)
        ->and($closed->closing_notes)->toBe('Faltam 5 Kz')
        ->and($closed->closed_by_user_id)->toBe($company['owner']->id)
        ->and($closed->closed_at)->not->toBeNull()
        ->and($closed->closing_summary)->toMatchArray([
            'sales_count' => 0,
            'cash_in_minor' => 5_000,
            'expected_cash_minor' => 15_000,
        ]);
});

test('a shift cannot be closed twice', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    app(ClosePosSession::class)->execute($session, $company['owner'], 0, null);

    $this->actingAs($company['owner'])
        ->post(route('pos.sessions.close', $session), ['counted_cash' => '0'])
        ->assertSessionHas('error', 'Este turno já foi fechado.');
});

test('a manager can close a shift someone else left open', function () {
    $company = posCompany();
    $cashier = posMember($company, WorkspaceRole::Billing);
    $session = posOpenSession($company, $cashier);

    $this->actingAs($company['owner'])
        ->post(route('pos.sessions.close', $session), ['counted_cash' => '0'])
        ->assertSessionHas('success');

    expect($session->fresh())
        ->status->toBe(PosSessionStatus::Closed)
        ->closed_by_user_id->toBe($company['owner']->id)
        ->opened_by_user_id->toBe($cashier->id);
});

test('someone who is neither the opener nor a manager cannot close a shift', function () {
    $company = posCompany();
    $cashier = posMember($company, WorkspaceRole::Billing);
    $colleague = posMember($company, WorkspaceRole::Accountant);
    $session = posOpenSession($company, $cashier);

    $this->actingAs($colleague)
        ->post(route('pos.sessions.close', $session), ['counted_cash' => '0'])
        ->assertForbidden();

    expect($session->fresh()->status)->toBe(PosSessionStatus::Open);

    // Their own, they can.
    $this->actingAs($cashier)
        ->post(route('pos.sessions.close', $session), ['counted_cash' => '0'])
        ->assertSessionHas('success');
});

test('closing needs multi-factor authentication', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $company['owner']->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

    $this->actingAs($company['owner'])
        ->post(route('pos.sessions.close', $session), ['counted_cash' => '0'])
        ->assertRedirect(route('settings.security'));

    expect($session->fresh()->status)->toBe(PosSessionStatus::Open);
});

// ------------------------------------------------------------------- reading

test('managers read every shift of the company and everyone else only their own', function () {
    $company = posCompany();
    $cashier = posMember($company, WorkspaceRole::Billing);
    $colleague = posMember($company, WorkspaceRole::Accountant);
    $mine = posOpenSession($company, $cashier);
    app(ClosePosSession::class)->execute($mine, $cashier, 0, null);
    $second = PosRegister::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'establishment_id' => $company['establishment']->id,
    ]);
    posOpenSession($company, $company['owner'], 0, $second);

    $this->actingAs($company['owner'])
        ->get(route('pos.sessions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pos/Sessions/Index', false)
            ->where('sessions.total', 2)
            ->has('sessions.data', 2));

    $this->actingAs($cashier)
        ->get(route('pos.sessions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sessions.total', 1)
            ->where('sessions.data.0.public_id', $mine->public_id)
            ->where('sessions.data.0.status', 'closed')
            ->where('sessions.data.0.status_label', 'Fechado')
            ->where('sessions.data.0.sales_count', 0)
            ->where('sessions.data.0.cash_difference_minor', 0));

    $this->actingAs($colleague)
        ->get(route('pos.sessions.index'))
        ->assertInertia(fn (Assert $page) => $page->where('sessions.total', 0));

    $this->actingAs($colleague)
        ->get(route('pos.sessions.show', $mine))
        ->assertForbidden();
});

test('the report of an open shift is live and of a closed shift is frozen', function () {
    $company = posCompany();
    $session = posOpenSession($company, float: 1_000);

    $this->actingAs($company['owner'])
        ->get(route('pos.sessions.show', $session))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pos/Sessions/Show', false)
            ->where('session.status', 'open')
            ->where('session.can_close', true)
            ->where('session.expected_cash_minor', 1_000)
            ->where('session.counted_cash_minor', null)
            ->where('summary.sales_count', 0)
            ->where('summary.first_document_no', null)
            ->has('cash_movements', 0)
            ->has('sales', 0));

    app(RecordPosCashMovement::class)->execute($session, $company['owner'], PosCashMovementType::In, 500, 'Reforço');
    app(ClosePosSession::class)->execute($session, $company['owner'], 1_500, 'Tudo certo');

    // A movement slipped in afterwards (it cannot be, but if the row were
    // altered) must not change what the closed report says.
    PosCashMovement::factory()->create(['pos_session_id' => $session->id, 'amount_minor' => 99_999]);

    $this->actingAs($company['owner'])
        ->get(route('pos.sessions.show', $session))
        ->assertInertia(fn (Assert $page) => $page
            ->where('session.status', 'closed')
            ->where('session.can_close', false)
            ->where('session.closed_by_name', $company['owner']->name)
            ->where('session.counted_cash_minor', 1_500)
            ->where('session.expected_cash_minor', 1_500)
            ->where('session.cash_difference_minor', 0)
            ->where('session.closing_notes', 'Tudo certo')
            ->where('summary.cash_in_minor', 500)
            ->where('summary.expected_cash_minor', 1_500)
            ->has('cash_movements', 2));
});
