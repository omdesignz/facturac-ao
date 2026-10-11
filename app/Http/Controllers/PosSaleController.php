<?php

namespace App\Http\Controllers;

use App\Actions\CompletePosSale;
use App\Exceptions\BillingActionRefused;
use App\Exceptions\FiscalFinalizationBlocked;
use App\Fiscal\Pos\PosCatalogue;
use App\Fiscal\Pos\PosPresenter;
use App\Fiscal\Pos\PosSessionSummary;
use App\Http\Controllers\Concerns\ResolvesPosCompany;
use App\Http\Requests\StorePosSaleRequest;
use App\Models\PosSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Rings up one sale and answers with the numbers the till needs next.
 *
 * Answers JSON only: the till is a page that stays open between customers and
 * reads the result itself.
 *
 * Authority. Opening a shift is the moment of password confirmation, and an
 * open shift is the standing authority to sell on it. That is why this route
 * asks for multi-factor authentication but not for the password again: a
 * cashier cannot retype it for every customer in the queue. What stops anyone
 * else using the shift is the check below that only the person who opened it
 * sells on it.
 */
class PosSaleController extends Controller
{
    use ResolvesPosCompany;

    public function __construct(
        private PosSessionSummary $summary,
        private PosPresenter $presenter,
        private PosCatalogue $catalogue,
    ) {}

    public function store(
        StorePosSaleRequest $request,
        PosSession $posSession,
        CompletePosSale $completeSale,
    ): JsonResponse {
        $legalEntity = $this->legalEntityOrFail($request);
        abort_unless($posSession->legal_entity_id === $legalEntity->id, 404);
        Gate::authorize('sell', $posSession);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        try {
            $sale = $completeSale->execute($posSession, $user, $request->sale());
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], 422);
        } catch (BillingActionRefused|FiscalFinalizationBlocked $exception) {
            // Written to be read by the cashier; nothing else is ever sent.
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        $payload = [
            'sale' => [
                ...$this->presenter->sale($sale),
                'tendered_minor' => $sale->tendered_minor,
                'change_minor' => $sale->change_minor,
                'replayed' => $sale->replayed,
            ],
            'summary' => $this->presenter->tillSummary($this->summary->forSession($posSession)),
            'stock' => $this->catalogue->afterSale($posSession, $sale->fiscalDocument),
        ];

        return response()->json($payload, $sale->replayed ? 200 : 201);
    }
}
