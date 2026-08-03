<?php

namespace App\Http\Controllers;

use App\Actions\StartSubscriptionCheckout;
use App\Billing\Exceptions\BillingGatewayUnavailable;
use App\Http\Requests\StartSubscriptionCheckoutRequest;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Workspace;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Throwable;

class BillingCheckoutController extends Controller
{
    public function __invoke(
        StartSubscriptionCheckoutRequest $request,
        StartSubscriptionCheckout $startCheckout,
    ): RedirectResponse {
        $workspace = $request->attributes->get('currentWorkspace');
        $user = $request->user();
        abort_unless($workspace instanceof Workspace && $user instanceof User, 404);
        $plan = SubscriptionPlan::query()
            ->where('public_id', $request->validated('plan_public_id'))
            ->where('is_active', true)
            ->firstOrFail();

        try {
            $startCheckout->execute($workspace, $plan, $user);
        } catch (BillingGatewayUnavailable|DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with(
                'error',
                'Não foi possível criar a Referência EMIS. Tente novamente sem efectuar transferências manuais.',
            );
        }

        return redirect()
            ->route('billing.show')
            ->with('success', 'Referência EMIS preparada. O plano só será activado após confirmação do provedor.');
    }
}
