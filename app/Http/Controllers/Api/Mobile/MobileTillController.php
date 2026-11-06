<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Actions\ClosePosSession;
use App\Actions\CompletePosSale;
use App\Actions\OpenPosSession;
use App\Actions\RecordPosCashMovement;
use App\Exceptions\BillingActionRefused;
use App\Exceptions\FiscalFinalizationBlocked;
use App\Exceptions\SubscriptionRequired;
use App\Fiscal\Calculation\CalculatedFiscalLine;
use App\Fiscal\Calculation\FiscalCalculator;
use App\Fiscal\Documents\CatalogueDocumentLines;
use App\Fiscal\Documents\MobileDocumentReceipt;
use App\Fiscal\Pos\FinalConsumer;
use App\Fiscal\Pos\MobileTillState;
use App\Fiscal\Pos\PosPresenter;
use App\FiscalDocumentType;
use App\Http\Requests\ClosePosSessionRequest;
use App\Http\Requests\PreviewMobilePosSaleRequest;
use App\Http\Requests\StoreMobilePosCashMovementRequest;
use App\Http\Requests\StoreMobilePosSessionRequest;
use App\Http\Requests\StorePosSaleRequest;
use App\Models\Customer;
use App\Models\LegalEntity;
use App\Models\PosCashMovement;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The till from the phone, on the web's actions and rules.
 *
 * The authority is the web's too. Opening a shift is the moment of password
 * confirmation and the standing authority to sell on it, so a sale asks for
 * two-factor authentication but not the password again, and only the person who
 * opened a shift sells on it.
 */
class MobileTillController
{
    public function __construct(private readonly MobileTillState $till) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->till->for($this->legalEntity($request), $this->user($request))]);
    }

    public function open(StoreMobilePosSessionRequest $request, OpenPosSession $open): JsonResponse
    {
        $legalEntity = $this->legalEntity($request);
        $user = $this->user($request);

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return $this->needsTwoFactor();
        }

        if (! Hash::check((string) $request->validated('password'), $user->password)) {
            return $this->refusal('PASSWORD_INCORRECT', 'A palavra-passe não está certa.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $register = PosRegister::query()
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->where('public_id', (string) $request->validated('pos_register_public_id'))
            ->firstOrFail();

        return $this->answer(function () use ($open, $register, $user, $request, $legalEntity): JsonResponse {
            $open->execute($register, $user, $request->openingFloatMinor());

            return response()->json(['data' => $this->till->for($legalEntity, $user)], Response::HTTP_CREATED);
        });
    }

    /**
     * What the sale would come to, with the document type it will be issued as.
     */
    public function preview(
        PreviewMobilePosSaleRequest $request,
        PosSession $posSession,
        CatalogueDocumentLines $catalogueLines,
        FiscalCalculator $calculator,
    ): JsonResponse {
        $legalEntity = $this->legalEntity($request);
        abort_unless($posSession->legal_entity_id === $legalEntity->id, 404);
        Gate::authorize('sell', $posSession);

        $customer = $this->customer($legalEntity, $request->validated('customer_public_id'));
        $requested = $catalogueLines->for($legalEntity, $posSession->currency_code, $customer, $request->saleLines());
        $calculation = $calculator->calculate($requested, FiscalDocumentType::InvoiceReceipt);

        return response()->json(['data' => [
            'customer_name' => $customer->name ?? FinalConsumer::NAME,
            'customer_tax_identification_number' => $customer->tax_identification_number ?? FinalConsumer::TAX_IDENTIFICATION_NUMBER,
            'due_date' => null,
            'lines' => array_map(fn (CalculatedFiscalLine $line, array $source): array => [
                'description' => $line->productDescription,
                'quantity' => $source['quantity'],
                'unit_of_measure' => $line->unitOfMeasure,
                'tax_percentage' => $source['tax_percentage'],
                'gross_minor' => $line->grossAmountMinor,
            ], $calculation->lines, $requested),
            'net_total_minor' => $calculation->netTotalMinor,
            'tax_total_minor' => $calculation->taxPayableMinor,
            'gross_total_minor' => $calculation->grossTotalMinor,
            'currency_code' => $posSession->currency_code,
        ]]);
    }

    public function sell(
        StorePosSaleRequest $request,
        PosSession $posSession,
        CompletePosSale $complete,
        MobileDocumentReceipt $receipt,
        PosPresenter $presenter,
    ): JsonResponse {
        $legalEntity = $this->legalEntity($request);
        $user = $this->user($request);
        abort_unless($posSession->legal_entity_id === $legalEntity->id, 404);

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return $this->needsTwoFactor();
        }

        if (Gate::denies('sell', $posSession)) {
            return $this->refusal('SALE_NOT_ALLOWED', 'Só quem abriu o turno pode vender nele.', Response::HTTP_FORBIDDEN);
        }

        return $this->answer(function () use ($complete, $posSession, $user, $request, $receipt, $legalEntity, $presenter): JsonResponse {
            $sale = $complete->execute($posSession, $user, $request->sale());

            return response()->json(['data' => [
                'receipt' => $receipt->forDocument($sale->fiscalDocument, $sale->replayed, $legalEntity),
                'tendered_minor' => $sale->tendered_minor,
                'change_minor' => $sale->change_minor,
                'payment_method_label' => $presenter->sale($sale)['payment_method_label'],
                'session' => $this->till->session($legalEntity, $posSession->fresh(['register', 'establishment']) ?? $posSession),
            ]], $sale->replayed ? Response::HTTP_OK : Response::HTTP_CREATED);
        });
    }

    public function movement(StoreMobilePosCashMovementRequest $request, PosSession $posSession, RecordPosCashMovement $record): JsonResponse
    {
        $legalEntity = $this->legalEntity($request);
        $user = $this->user($request);
        abort_unless($posSession->legal_entity_id === $legalEntity->id, 404);

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return $this->needsTwoFactor();
        }

        $clientKey = (string) $request->validated('client_key');
        $replayed = PosCashMovement::query()->where('legal_entity_id', $legalEntity->id)->where('client_key', $clientKey)->exists();

        return $this->answer(function () use ($record, $posSession, $user, $request, $clientKey, $replayed, $legalEntity): JsonResponse {
            if (! $replayed) {
                $record->execute($posSession, $user, $request->movementType(), $request->amountMinor(), $request->reason(), $clientKey);
            }

            return response()->json(
                ['data' => $this->till->session($legalEntity, $posSession->fresh(['register', 'establishment']) ?? $posSession)],
                $replayed ? Response::HTTP_OK : Response::HTTP_CREATED,
            );
        });
    }

    public function close(ClosePosSessionRequest $request, PosSession $posSession, ClosePosSession $close): JsonResponse
    {
        $legalEntity = $this->legalEntity($request);
        $user = $this->user($request);
        abort_unless($posSession->legal_entity_id === $legalEntity->id, 404);

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return $this->needsTwoFactor();
        }

        return $this->answer(function () use ($close, $posSession, $user, $request): JsonResponse {
            $closed = $close->execute($posSession, $user, $request->countedCashMinor(), $request->notes());

            return response()->json(['data' => [
                'public_id' => $closed->public_id,
                'expected_cash_minor' => $closed->expected_cash_minor,
                'counted_cash_minor' => $closed->counted_cash_minor,
                'cash_difference_minor' => $closed->cash_difference_minor,
                'currency_code' => $closed->currency_code,
            ]]);
        });
    }

    /**
     * @param  callable(): JsonResponse  $action
     */
    private function answer(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (BillingActionRefused|FiscalFinalizationBlocked $exception) {
            return $this->refusal('TILL_REFUSED', $exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (SubscriptionRequired $exception) {
            return $this->refusal('SUBSCRIPTION_REQUIRED', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (AuthorizationException $exception) {
            return $this->refusal('SALE_NOT_ALLOWED', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        }
    }

    private function customer(LegalEntity $legalEntity, ?string $publicId): ?Customer
    {
        if ($publicId === null) {
            return null;
        }

        return Customer::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('public_id', $publicId)
            ->where('is_active', true)
            ->first() ?? throw ValidationException::withMessages([
                'customer_public_id' => 'Este cliente já não está disponível.',
            ]);
    }

    private function needsTwoFactor(): JsonResponse
    {
        return $this->refusal('TWO_FACTOR_REQUIRED_TO_SELL', 'Active a autenticação em dois passos em facturac.ao para vender.', Response::HTTP_FORBIDDEN);
    }

    private function legalEntity(Request $request): LegalEntity
    {
        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');

        return $legalEntity;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function refusal(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
