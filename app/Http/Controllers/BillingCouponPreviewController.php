<?php

namespace App\Http\Controllers;

use App\Billing\SubscriptionCouponPricer;
use App\Exceptions\BillingActionRefused;
use App\Http\Requests\PreviewSubscriptionCouponRequest;
use App\Models\SubscriptionPlan;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Shows the discounted price in the checkout dialog before the customer goes
 * to WiPay. Nothing is reserved: the checkout prices the code again.
 */
class BillingCouponPreviewController extends Controller
{
    public function __invoke(PreviewSubscriptionCouponRequest $request, SubscriptionCouponPricer $pricer): JsonResponse
    {
        $workspace = $request->attributes->get('currentWorkspace');
        abort_unless($workspace instanceof Workspace, 404);

        $plan = SubscriptionPlan::query()
            ->where('public_id', $request->validated('plan_public_id'))
            ->where('is_active', true)
            ->firstOrFail();

        try {
            $quote = $pricer->quote($workspace, $plan, (string) $request->validated('coupon_code'));
        } catch (BillingActionRefused $exception) {
            throw ValidationException::withMessages(['coupon_code' => $exception->getMessage()]);
        }

        return response()->json($quote->toArray());
    }
}
