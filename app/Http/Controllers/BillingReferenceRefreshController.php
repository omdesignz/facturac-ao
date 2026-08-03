<?php

namespace App\Http\Controllers;

use App\Actions\RefreshEmisPaymentReference;
use App\Billing\Exceptions\BillingGatewayUnavailable;
use App\Http\Requests\RefreshEmisPaymentReferenceRequest;
use App\Models\EmisPaymentReference;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

class BillingReferenceRefreshController extends Controller
{
    public function __invoke(
        RefreshEmisPaymentReferenceRequest $request,
        EmisPaymentReference $emisPaymentReference,
        RefreshEmisPaymentReference $refreshReference,
    ): RedirectResponse {
        $workspace = $request->attributes->get('currentWorkspace');
        $user = $request->user();
        abort_unless(
            $workspace instanceof Workspace
                && $user instanceof User
                && $emisPaymentReference->workspace_id === $workspace->id,
            404,
        );

        try {
            $refreshReference->execute($emisPaymentReference, $user);
        } catch (BillingGatewayUnavailable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Estado consultado directamente no provedor de pagamento.');
    }
}
