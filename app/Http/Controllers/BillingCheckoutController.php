<?php

namespace App\Http\Controllers;

use App\Actions\StartWiPaySubscriptionCheckout;
use App\Billing\Exceptions\PaymentGatewayException;
use App\Http\Requests\StartSubscriptionCheckoutRequest;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Workspace;
use App\PaymentStatus;
use DomainException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class BillingCheckoutController extends Controller
{
    public function __invoke(
        StartSubscriptionCheckoutRequest $request,
        StartWiPaySubscriptionCheckout $startHostedCheckout,
    ): Response {
        $workspace = $request->attributes->get('currentWorkspace');
        $user = $request->user();
        abort_unless($workspace instanceof Workspace && $user instanceof User, 404);
        $plan = SubscriptionPlan::query()
            ->where('public_id', $request->validated('plan_public_id'))
            ->where('is_active', true)
            ->firstOrFail();

        try {
            $payment = $startHostedCheckout->execute($workspace, $plan, $user, (string) $request->validated('customer_phone'));

            if ($payment->status === PaymentStatus::Pending && $payment->checkout_url !== null) {
                return Inertia::location($payment->checkout_url);
            }

            return redirect()->route('billing.show')->with('status', 'O estado do pagamento está a ser confirmado.');
        } catch (PaymentGatewayException|DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with(
                'error',
                'Não foi possível preparar o pagamento. Verifique o histórico antes de tentar novamente e não efectue transferências manuais.',
            );
        }
    }
}
