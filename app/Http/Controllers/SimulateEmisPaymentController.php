<?php

namespace App\Http\Controllers;

use App\Actions\ConfirmEmisPayment;
use App\Http\Requests\SimulateEmisPaymentRequest;
use App\Models\EmisPaymentReference;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

class SimulateEmisPaymentController extends Controller
{
    public function __invoke(
        SimulateEmisPaymentRequest $request,
        EmisPaymentReference $emisPaymentReference,
        ConfirmEmisPayment $confirmPayment,
    ): RedirectResponse {
        $workspace = $request->attributes->get('currentWorkspace');
        $user = $request->user();
        abort_unless(
            $workspace instanceof Workspace
                && $user instanceof User
                && $emisPaymentReference->workspace_id === $workspace->id,
            404,
        );
        $occurredAt = CarbonImmutable::now('Africa/Luanda');
        $payloadSha256 = hash(
            'sha256',
            $emisPaymentReference->provider_reference_id.'|simulated-paid|'.$emisPaymentReference->amount_minor,
        );

        $confirmPayment->execute(
            paymentReference: $emisPaymentReference,
            providerEventId: 'simulation-paid-'.$emisPaymentReference->public_id,
            amountMinor: $emisPaymentReference->amount_minor,
            currencyCode: $emisPaymentReference->currency_code,
            occurredAt: $occurredAt,
            payloadSha256: $payloadSha256,
            actor: $user,
        );

        return redirect()
            ->route('billing.show')
            ->with('success', 'Pagamento simulado confirmado. A assinatura está activa neste ambiente local.');
    }
}
