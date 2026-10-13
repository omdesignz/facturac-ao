<?php

use App\Billing\SubscriptionAccess;
use App\Models\SubscriptionPackage;
use App\Models\SubscriptionPlan;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\SubscriptionFeature;
use App\WorkspaceSubscriptionStatus;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('the researched catalogue contains three tiers and exact period prices', function () {
    $this->seed(SubscriptionCatalogueSeeder::class);

    expect(SubscriptionPackage::count())->toBe(3)->and(SubscriptionPlan::count())->toBe(12);
    foreach (['essencial' => 450_000, 'negocio' => 950_000, 'avancado' => 1_750_000] as $code => $amount) {
        $package = SubscriptionPackage::where('code', $code)->firstOrFail();
        expect($package->trial_days)->toBe(14)->and($package->limits['feature_invoicing'])->toBeTrue();
        foreach (['monthly' => 100, 'quarterly' => 285, 'semiannual' => 540, 'annual' => 1000] as $period => $factor) {
            $plan = $package->plans()->where('interval', $period)->firstOrFail();
            expect($plan->amount_minor)->toBe(intdiv($amount * $factor, 100))
                ->and($plan->limits)->toBe($package->limits)->and($plan->currency_code)->toBe('AOA');
        }
    }
});

test('catalogue installation preserves staff prices and existing subscription contracts', function () {
    $this->seed(SubscriptionCatalogueSeeder::class);
    $plan = SubscriptionPlan::where('code', 'negocio-monthly')->firstOrFail();
    $plan->update(['amount_minor' => 1_234_500]);
    $plan->package->update(['name' => 'Nome personalizado']);
    $subscription = WorkspaceSubscription::factory()->create(['subscription_plan_id' => $plan->id]);
    $this->seed(SubscriptionCatalogueSeeder::class);

    expect(SubscriptionPackage::count())->toBe(3)->and(SubscriptionPlan::count())->toBe(12)
        ->and($plan->fresh()->amount_minor)->toBe(1_234_500)
        ->and($plan->package->fresh()->name)->toBe('Nome personalizado')
        ->and($subscription->fresh()->subscription_plan_id)->toBe($plan->id);
});

test('new workspace trial grants features for exactly fourteen days without requiring payment', function () {
    $this->freezeTime();
    $workspace = Workspace::factory()->create();
    $access = app(SubscriptionAccess::class);
    expect($access->status($workspace->id)['status'])->toBe('trial');
    foreach (SubscriptionFeature::cases() as $feature) {
        expect($access->allows($workspace->id, $feature))->toBeTrue();
    }
    $this->travel(14)->days();
    expect($access->status($workspace->id)['status'])->toBe('expired')
        ->and($access->allows($workspace->id, SubscriptionFeature::Invoicing))->toBeFalse();
    expect(fn () => $access->require($workspace->id, SubscriptionFeature::Pos))->toThrow(HttpException::class);
});

test('paid plans enforce the matrix independently for each workspace', function (string $code, array $allowed) {
    $this->seed(SubscriptionCatalogueSeeder::class);
    $plan = SubscriptionPlan::where('code', $code.'-monthly')->firstOrFail();
    $workspace = Workspace::factory()->create(['created_at' => now()->subMonth()]);
    WorkspaceSubscription::factory()->create(['workspace_id' => $workspace->id, 'subscription_plan_id' => $plan->id]);
    $other = Workspace::factory()->create(['created_at' => now()->subMonth()]);
    $access = app(SubscriptionAccess::class);
    foreach (SubscriptionFeature::cases() as $feature) {
        expect($access->allows($workspace->id, $feature))->toBe(in_array($feature->value, $allowed, true))
            ->and($access->allows($other->id, $feature))->toBeFalse();
    }
})->with([
    ['essencial', ['invoicing']],
    ['negocio', ['invoicing', 'pos', 'inventory', 'transport']],
    ['avancado', array_column(SubscriptionFeature::cases(), 'value')],
]);

test('inactive subscription states never restart a trial or grant a paid feature', function (WorkspaceSubscriptionStatus $status) {
    $workspace = Workspace::factory()->create();
    WorkspaceSubscription::factory()->create(['workspace_id' => $workspace->id, 'status' => $status]);
    expect(app(SubscriptionAccess::class)->allows($workspace->id, SubscriptionFeature::Pos))->toBeFalse();
})->with([WorkspaceSubscriptionStatus::Suspended, WorkspaceSubscriptionStatus::PastDue, WorkspaceSubscriptionStatus::Cancelled]);

test('pending checkout does not renew an expired workspace trial', function () {
    $workspace = Workspace::factory()->create(['created_at' => now()->subDays(15)]);
    WorkspaceSubscription::factory()->pending()->create(['workspace_id' => $workspace->id]);
    expect(app(SubscriptionAccess::class)->allows($workspace->id, SubscriptionFeature::Pos))->toBeFalse();
});

test('paid access is limited to its exact time interval and survives a scheduled cancellation', function () {
    $this->freezeTime();
    $subscription = WorkspaceSubscription::factory()->create(['cancel_at_period_end' => true, 'current_period_started_at' => now()->addHour(), 'current_period_ends_at' => now()->addHours(2)]);
    $access = app(SubscriptionAccess::class);
    expect($access->allows($subscription->workspace_id, SubscriptionFeature::Invoicing))->toBeFalse();
    $this->travel(1)->hours();
    expect($access->allows($subscription->workspace_id, SubscriptionFeature::Invoicing))->toBeTrue();
    $this->travel(1)->hours();
    expect($access->allows($subscription->workspace_id, SubscriptionFeature::Invoicing))->toBeFalse();
});

test('unknown entitlement versions and non boolean feature values fail closed', function (array $limits) {
    $plan = SubscriptionPlan::factory()->create(['limits' => $limits]);
    $subscription = WorkspaceSubscription::factory()->create(['subscription_plan_id' => $plan->id]);
    expect(app(SubscriptionAccess::class)->allows($subscription->workspace_id, SubscriptionFeature::Pos))->toBeFalse();
})->with([[['entitlement_version' => 99, 'feature_pos' => true]], [['entitlement_version' => 1, 'feature_pos' => 'true']], [['entitlement_version' => 1]]]);

test('existing paid legacy plans retain access only through the current paid period', function () {
    $plan = SubscriptionPlan::factory()->create(['limits' => null]);
    $subscription = WorkspaceSubscription::factory()->create(['subscription_plan_id' => $plan->id]);
    $access = app(SubscriptionAccess::class);
    expect($access->allows($subscription->workspace_id, SubscriptionFeature::Integrations))->toBeTrue();
    $subscription->update(['current_period_ends_at' => now()]);
    expect($access->allows($subscription->workspace_id, SubscriptionFeature::Integrations))->toBeFalse();
});
