<?php

namespace App\Http\Controllers;

use App\Actions\CreateHostedPayment;
use App\Billing\Exceptions\PaymentGatewayException;
use App\Billing\WiPay\WiPayClient;
use App\Billing\WiPay\WiPayConfiguration;
use App\Models\Payment;
use App\Models\Workspace;
use App\PaymentEnvironment;
use App\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class HostedPaymentResumeController extends Controller
{
    public function __invoke(Request $request, Payment $payment, WiPayClient $client, CreateHostedPayment $create, WiPayConfiguration $configuration): Response
    {
        $workspace = $request->attributes->get('currentWorkspace');
        abort_unless($workspace instanceof Workspace && $payment->workspace_id === $workspace->id, 404);
        Gate::authorize('update', $workspace);

        if (config('billing.gateway') !== 'wipay' || ! $configuration->isAvailable()
            || $payment->environment !== $configuration->environment
            || ($payment->environment === PaymentEnvironment::Sandbox && app()->isProduction())) {
            return back()->with('error', 'Este ambiente de pagamentos está desactivado. Contacte o apoio.');
        }

        if ($payment->status === PaymentStatus::Created) {
            try {
                $payment = $create->execute($payment, (string) (config('billing.wipay.callback_url') ?: route('webhooks.wipay')), route('billing.show'));
            } catch (PaymentGatewayException $exception) {
                return back()->with('error', $exception->getMessage());
            }
        }

        if ($payment->status !== PaymentStatus::Pending || $payment->checkout_url === null) {
            return back()->with('error', 'Este pedido já não pode ser retomado. Verifique o estado do pagamento.');
        }

        try {
            $client->checkoutId($payment->checkout_url);
        } catch (PaymentGatewayException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return Inertia::location($payment->checkout_url);
    }
}
