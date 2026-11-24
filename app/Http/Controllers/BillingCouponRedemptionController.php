<?php

namespace App\Http\Controllers;

use App\Actions\RedeemFreeSubscriptionCoupon;
use App\Exceptions\BillingActionRefused;
use App\Http\Requests\PreviewSubscriptionCouponRequest;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Workspace;
use DomainException;
use Illuminate\Http\RedirectResponse;

/**
 * Activates a plan for free with a code that gives free time; nothing goes
 * to WiPay.
 */
class BillingCouponRedemptionController extends Controller
{
    public function __invoke(PreviewSubscriptionCouponRequest $request, RedeemFreeSubscriptionCoupon $redeem): RedirectResponse
    {
        $workspace = $request->attributes->get('currentWorkspace');
        $user = $request->user();
        abort_unless($workspace instanceof Workspace && $user instanceof User, 404);

        $plan = SubscriptionPlan::query()
            ->where('public_id', $request->validated('plan_public_id'))
            ->where('is_active', true)
            ->firstOrFail();

        try {
            $subscription = $redeem->execute($workspace, $plan, $user, (string) $request->validated('coupon_code'));
        } catch (BillingActionRefused $exception) {
            return back()->withErrors(['coupon_code' => $exception->getMessage()]);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $endsOn = $subscription->current_period_ends_at?->setTimezone('Africa/Luanda')->format('d/m/Y');

        return redirect()->route('billing.show')
            ->with('success', "Plano {$plan->name} activo sem pagamento até {$endsOn}.");
    }
}
