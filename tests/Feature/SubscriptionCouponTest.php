<?php

use App\Actions\FulfillHostedPayment;
use App\Models\Payment;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionCoupon;
use App\Models\SubscriptionCouponRedemption;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\PaymentStatus;
use App\SubscriptionChargeStatus;
use App\SubscriptionCouponDiscountType;
use App\SubscriptionInterval;
use App\WorkspaceSubscriptionStatus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;

require_once __DIR__.'/../HostedBillingFixtures.php';

beforeEach(function (): void {
    configureHostedBilling();
    fakeHostedProvider();
});

/**
 * A workspace owner with two-step verification, ready to pay for a plan.
 *
 * @return array{user: User, workspace: Workspace, plan: SubscriptionPlan}
 */
function couponCompany(int $amountMinor = 25_000_00): array
{
    $user = User::factory()->withWorkspace('Kianda Comércio')->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('coupon-secret'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
            json_encode(['coupon-recovery'], JSON_THROW_ON_ERROR),
        ),
        'two_factor_confirmed_at' => now(),
    ]);
    $workspace = $user->currentWorkspace()->firstOrFail();
    $plan = SubscriptionPlan::factory()->create([
        'amount_minor' => $amountMinor,
        'currency_code' => 'AOA',
        'interval' => SubscriptionInterval::Monthly,
    ]);

    return compact('user', 'workspace', 'plan');
}

/**
 * @param  array{user: User, workspace: Workspace, plan: SubscriptionPlan}  $company
 * @param  array<string, mixed>  $extra
 */
function checkoutWithCoupon(array $company, ?string $code, array $extra = []): TestResponse
{
    return test()->actingAs($company['user'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('billing.checkout'), [
            'customer_phone' => '900000000',
            'plan_public_id' => $company['plan']->public_id,
            'coupon_code' => $code,
            ...$extra,
        ]);
}

/** @param array{user: User, workspace: Workspace, plan: SubscriptionPlan} $company */
function previewCoupon(array $company, string $code): TestResponse
{
    return test()->actingAs($company['user'])
        ->postJson(route('billing.coupon.preview'), [
            'plan_public_id' => $company['plan']->public_id,
            'coupon_code' => $code,
        ]);
}

/** @return array<string, int> */
function couponStaffSession(): array
{
    return ['auth.password_confirmed_at' => time()];
}

/* ------------------------------------------------------------- apply */

test('the preview shows the discounted figure before anything is charged', function () {
    $company = couponCompany(25_000_00);
    SubscriptionCoupon::factory()->percentOff(20)->create(['code' => 'LANCAMENTO-20', 'description' => 'Lançamento']);

    previewCoupon($company, ' lancamento-20 ')
        ->assertOk()
        ->assertExactJson([
            'code' => 'LANCAMENTO-20',
            'description' => 'Lançamento',
            'list_amount_minor' => 25_000_00,
            'discount_minor' => 5_000_00,
            'amount_minor' => 20_000_00,
        ]);

    expect(SubscriptionCharge::query()->doesntExist())->toBeTrue()
        ->and(SubscriptionCouponRedemption::query()->doesntExist())->toBeTrue();
});

test('a percentage rounds the discount down to the cêntimo', function () {
    $coupon = SubscriptionCoupon::factory()->percentOff(15)->make();

    expect($coupon->discountFor(9_999))->toBe(1_499)
        ->and(SubscriptionCoupon::factory()->amountOff(3_000_00)->make()->discountFor(2_000_00))->toBe(2_000_00);
});

test('checkout charges WiPay the discounted amount and records the use', function () {
    $company = couponCompany(25_000_00);
    $coupon = SubscriptionCoupon::factory()->amountOff(7_500_50)->create(['code' => 'AMIGO']);

    checkoutWithCoupon($company, 'amigo')
        ->assertSessionHasNoErrors()
        ->assertRedirectContains('https://hosted.wipay.ao/');

    $charge = SubscriptionCharge::query()->sole();
    $payment = Payment::query()->sole();
    $redemption = SubscriptionCouponRedemption::query()->sole();

    expect($charge->amount_minor)->toBe(17_499_50)
        ->and($payment->amount_minor)->toBe(17_499_50)
        ->and($redemption->subscription_coupon_id)->toBe($coupon->id)
        ->and($redemption->workspace_id)->toBe($company['workspace']->id)
        ->and($redemption->subscription_charge_id)->toBe($charge->id)
        ->and($redemption->list_amount_minor)->toBe(25_000_00)
        ->and($redemption->discount_minor)->toBe(7_500_50);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'hosts/payments')
        && $request['amount'] === '17499.50');
});

test('a discounted payment activates the subscription for the whole period', function () {
    $company = couponCompany(25_000_00);
    SubscriptionCoupon::factory()->percentOff(50)->create(['code' => 'METADE']);

    checkoutWithCoupon($company, 'METADE')->assertSessionHasNoErrors();
    $payment = Payment::query()->sole();

    sendHostedCallback(hostedCallback($payment))->assertAccepted();
    app(FulfillHostedPayment::class)->execute($payment);

    expect(WorkspaceSubscription::query()->sole()->status)->toBe(WorkspaceSubscriptionStatus::Active)
        ->and(SubscriptionCharge::query()->sole()->status)->toBe(SubscriptionChargeStatus::Paid);
});

test('checkout without a code charges the full price', function () {
    $company = couponCompany(25_000_00);

    checkoutWithCoupon($company, null)->assertSessionHasNoErrors();

    expect(SubscriptionCharge::query()->sole()->amount_minor)->toBe(25_000_00)
        ->and(SubscriptionCouponRedemption::query()->doesntExist())->toBeTrue();
});

test('the billing history shows the code and discount on a charge', function () {
    $company = couponCompany(25_000_00);
    SubscriptionCoupon::factory()->percentOff(10)->create(['code' => 'DEZ']);
    checkoutWithCoupon($company, 'DEZ');

    $this->actingAs($company['user'])
        ->get(route('billing.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('charges.0.amount_minor', 22_500_00)
            ->where('charges.0.coupon.code', 'DEZ')
            ->where('charges.0.coupon.discount_minor', 2_500_00));
});

/* ------------------------------------------------------------- reject */

test('an unknown or switched-off code is refused without charging', function (?string $state) {
    $company = couponCompany();
    $factory = SubscriptionCoupon::factory();
    $factory = $state === 'inactive' ? $factory->inactive() : $factory;
    $factory->create(['code' => 'EXISTE']);
    $code = $state === null ? 'NAOEXISTE' : 'EXISTE';

    previewCoupon($company, $code)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['coupon_code' => 'Este código não existe ou já não está disponível.']);

    checkoutWithCoupon($company, $code)
        ->assertSessionHasErrors(['coupon_code' => 'Este código não existe ou já não está disponível.']);

    expect(SubscriptionCharge::query()->doesntExist())->toBeTrue()
        ->and(Payment::query()->doesntExist())->toBeTrue();
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'hosts/payments'));
})->with([
    'unknown' => [null],
    'inactive' => ['inactive'],
]);

test('a fixed discount that would leave nothing to pay is refused', function () {
    $company = couponCompany(5_000_00);
    SubscriptionCoupon::factory()->amountOff(5_000_00)->create(['code' => 'TUDO']);

    previewCoupon($company, 'TUDO')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['coupon_code' => 'Este código não se aplica a este plano.']);
});

test('a malformed code is refused before it reaches the database', function () {
    $company = couponCompany();

    previewCoupon($company, "x' or 1=1")->assertUnprocessable()->assertJsonValidationErrors('coupon_code');
    checkoutWithCoupon($company, str_repeat('A', 40))->assertSessionHasErrors('coupon_code');

    expect(SubscriptionCharge::query()->doesntExist())->toBeTrue();
});

test('a pending payment cannot pick up a code afterwards', function () {
    $company = couponCompany(25_000_00);
    SubscriptionCoupon::factory()->percentOff(20)->create(['code' => 'DEPOIS']);

    checkoutWithCoupon($company, null)->assertSessionHasNoErrors();
    checkoutWithCoupon($company, 'DEPOIS')
        ->assertSessionHas('error', 'Conclua ou verifique o pagamento pendente antes de usar um código de desconto.');

    expect(SubscriptionCharge::query()->sole()->amount_minor)->toBe(25_000_00)
        ->and(SubscriptionCouponRedemption::query()->doesntExist())->toBeTrue();
});

test('repeating a checkout with the same code reuses the pending payment', function () {
    $company = couponCompany(25_000_00);
    SubscriptionCoupon::factory()->percentOff(20)->limitedTo(1)->create(['code' => 'UMAVEZ']);

    checkoutWithCoupon($company, 'UMAVEZ')->assertSessionHasNoErrors();
    checkoutWithCoupon($company, 'umavez')->assertSessionHasNoErrors()->assertRedirectContains('https://hosted.wipay.ao/');

    expect(SubscriptionCharge::query()->count())->toBe(1)
        ->and(SubscriptionCouponRedemption::query()->count())->toBe(1);
});

/* ------------------------------------------------------------- expire */

test('an expired code is refused at preview and at checkout', function () {
    $company = couponCompany();
    SubscriptionCoupon::factory()->create(['code' => 'NATAL', 'expires_at' => now()->addMinute()]);

    previewCoupon($company, 'NATAL')->assertOk();

    $this->travel(2)->minutes();

    previewCoupon($company, 'NATAL')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['coupon_code' => 'Este código já expirou.']);
    checkoutWithCoupon($company, 'NATAL')->assertSessionHasErrors(['coupon_code' => 'Este código já expirou.']);

    expect(SubscriptionCharge::query()->doesntExist())->toBeTrue();
});

/* ------------------------------------------------------------- limit */

test('a code stops applying once its uses run out', function () {
    $coupon = SubscriptionCoupon::factory()->percentOff(20)->limitedTo(1)->create(['code' => 'PRIMEIRO']);
    $first = couponCompany();
    $second = couponCompany();

    checkoutWithCoupon($first, 'PRIMEIRO')->assertSessionHasNoErrors();

    previewCoupon($second, 'PRIMEIRO')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['coupon_code' => 'Este código já atingiu o limite de utilizações.']);
    checkoutWithCoupon($second, 'PRIMEIRO')
        ->assertSessionHasErrors(['coupon_code' => 'Este código já atingiu o limite de utilizações.']);

    expect($coupon->redemptions()->count())->toBe(1)
        ->and(SubscriptionCharge::query()->count())->toBe(1);
});

test('a failed payment gives the use back', function () {
    $coupon = SubscriptionCoupon::factory()->percentOff(20)->limitedTo(1)->create(['code' => 'SEGUNDA']);
    $first = couponCompany();
    $second = couponCompany();

    checkoutWithCoupon($first, 'SEGUNDA')->assertSessionHasNoErrors();
    SubscriptionCharge::query()->sole()->forceFill([
        'status' => SubscriptionChargeStatus::Failed,
        'active_checkout_key' => null,
    ])->save();

    previewCoupon($second, 'SEGUNDA')->assertOk();
    checkoutWithCoupon($second, 'SEGUNDA')->assertSessionHasNoErrors();

    expect($coupon->redemptions()->count())->toBe(2)
        ->and($coupon->heldRedemptions()->count())->toBe(1);
});

test('each company can use a code only once', function () {
    $company = couponCompany();
    SubscriptionCoupon::factory()->percentOff(20)->create(['code' => 'BEMVINDO']);

    checkoutWithCoupon($company, 'BEMVINDO')->assertSessionHasNoErrors();
    $payment = Payment::query()->sole();
    sendHostedCallback(hostedCallback($payment))->assertAccepted();
    app(FulfillHostedPayment::class)->execute($payment);

    previewCoupon($company, 'BEMVINDO')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['coupon_code' => 'Esta empresa já usou este código.']);
    checkoutWithCoupon($company, 'BEMVINDO')
        ->assertSessionHasErrors(['coupon_code' => 'Esta empresa já usou este código.']);

    expect(SubscriptionCharge::query()->count())->toBe(1)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

/* ------------------------------------------------------------- staff */

test('staff create a percentage code, stored upper-case with its validity at the end of the day', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();

    $this->actingAs($staff)
        ->withSession(couponStaffSession())
        ->post(route('support.coupons.store'), [
            'code' => 'campanha-dez',
            'description' => 'Campanha de Dezembro',
            'discount_type' => 'percentage',
            'percent_off' => 10,
            'currency_code' => 'AOA',
            'max_redemptions' => 50,
            'expires_on' => now('Africa/Luanda')->addDays(10)->toDateString(),
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Código de desconto criado.');

    $coupon = SubscriptionCoupon::query()->sole();

    expect($coupon->code)->toBe('CAMPANHA-DEZ')
        ->and($coupon->discount_type)->toBe(SubscriptionCouponDiscountType::Percentage)
        ->and($coupon->percent_off)->toBe(10)
        ->and($coupon->amount_off_minor)->toBeNull()
        ->and($coupon->max_redemptions)->toBe(50)
        ->and($coupon->created_by_user_id)->toBe($staff->id)
        ->and($coupon->expires_at->setTimezone('Africa/Luanda')->format('H:i:s'))->toBe('23:59:59');
});

test('staff create a fixed code in minor units', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();

    $this->actingAs($staff)
        ->withSession(couponStaffSession())
        ->post(route('support.coupons.store'), [
            'code' => 'MENOS-5000',
            'discount_type' => 'fixed',
            'amount_off' => '5000.5',
            'currency_code' => 'AOA',
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    expect(SubscriptionCoupon::query()->sole()->amount_off_minor)->toBe(5_000_50);
});

test('staff cannot create an invalid code', function (array $payload, string $field) {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    SubscriptionCoupon::factory()->create(['code' => 'REPETIDO']);

    $this->actingAs($staff)
        ->withSession(couponStaffSession())
        ->post(route('support.coupons.store'), [
            'code' => 'NOVO',
            'discount_type' => 'percentage',
            'percent_off' => 10,
            'currency_code' => 'AOA',
            'is_active' => true,
            ...$payload,
        ])
        ->assertSessionHasErrors($field);

    expect(SubscriptionCoupon::query()->count())->toBe(1);
})->with([
    'duplicate in another case' => [['code' => 'repetido'], 'code'],
    'too short' => [['code' => 'AB'], 'code'],
    'spaces inside' => [['code' => 'DOIS CODIGOS'], 'code'],
    'percentage of 100' => [['percent_off' => 100], 'percent_off'],
    'percentage missing' => [['percent_off' => null], 'percent_off'],
    'fixed without amount' => [['discount_type' => 'fixed'], 'amount_off'],
    'fixed of zero' => [['discount_type' => 'fixed', 'amount_off' => '0'], 'amount_off'],
    'three decimals' => [['discount_type' => 'fixed', 'amount_off' => '10.005'], 'amount_off'],
    'expiry in the past' => [['expires_on' => '2020-01-01'], 'expires_on'],
    'limit of zero' => [['max_redemptions' => 0], 'max_redemptions'],
]);

test('editing keeps the code and discount and refuses a limit below the uses made', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $coupon = SubscriptionCoupon::factory()->percentOff(20)->limitedTo(5)->create(['code' => 'FIXO']);
    SubscriptionCouponRedemption::factory()->count(2)->for($coupon, 'coupon')->create();

    $this->actingAs($staff)->withSession(couponStaffSession())
        ->put(route('support.coupons.update', $coupon), ['max_redemptions' => 1, 'is_active' => true])
        ->assertSessionHasErrors(['max_redemptions' => 'O limite não pode ficar abaixo das 2 utilizações já feitas.']);

    $this->actingAs($staff)->withSession(couponStaffSession())
        ->put(route('support.coupons.update', $coupon), [
            'code' => 'OUTRO',
            'percent_off' => 90,
            'description' => 'Nova descrição',
            'max_redemptions' => 3,
            'is_active' => false,
        ])
        ->assertSessionHasNoErrors();

    $coupon->refresh();

    expect($coupon->code)->toBe('FIXO')
        ->and($coupon->percent_off)->toBe(20)
        ->and($coupon->description)->toBe('Nova descrição')
        ->and($coupon->max_redemptions)->toBe(3)
        ->and($coupon->is_active)->toBeFalse();
});

test('a used code is only switched off, an unused one can be deleted', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $used = SubscriptionCoupon::factory()->create();
    SubscriptionCouponRedemption::factory()->for($used, 'coupon')->create();
    $unused = SubscriptionCoupon::factory()->create();

    $this->actingAs($staff)->withSession(couponStaffSession())
        ->delete(route('support.coupons.destroy', $used))
        ->assertSessionHas('error');
    $this->actingAs($staff)->withSession(couponStaffSession())
        ->delete(route('support.coupons.destroy', $unused))
        ->assertSessionHas('success');

    expect($used->fresh())->not->toBeNull()
        ->and($unused->fresh())->toBeNull();
});

test('staff see each code with its uses; customers cannot reach the page', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $coupon = SubscriptionCoupon::factory()->percentOff(25)->limitedTo(10)->create(['code' => 'VISTO']);
    SubscriptionCouponRedemption::factory()->for($coupon, 'coupon')->create();
    $released = SubscriptionCharge::factory()->create(['status' => SubscriptionChargeStatus::Expired]);
    SubscriptionCouponRedemption::factory()->for($coupon, 'coupon')->create(['subscription_charge_id' => $released->id]);

    $this->actingAs($staff)->withSession(couponStaffSession())
        ->get(route('support.coupons.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Support/Coupons')
            ->has('coupons', 1)
            ->where('coupons.0.code', 'VISTO')
            ->where('coupons.0.used_count', 1)
            ->where('coupons.0.max_redemptions', 10)
            ->where('coupons.0.can_delete', false));

    $customer = couponCompany()['user'];
    $this->actingAs($customer)->withSession(couponStaffSession())
        ->get(route('support.coupons.index'))
        ->assertNotFound();
    $this->actingAs($customer)->withSession(couponStaffSession())
        ->post(route('support.coupons.store'), ['code' => 'INTRUSO'])
        ->assertNotFound();

    expect(SubscriptionCoupon::query()->count())->toBe(1);
});
