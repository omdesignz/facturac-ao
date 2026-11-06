<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Actions\ConfirmMobileIssuer;
use App\Actions\CreateMobileCreditNote;
use App\Billing\SubscriptionAccess;
use App\Exceptions\BillingActionRefused;
use App\Exceptions\FiscalFinalizationBlocked;
use App\Exceptions\SubscriptionRequired;
use App\Fiscal\Documents\MobileDocumentReceipt;
use App\Http\Requests\PreviewMobileCreditNoteRequest;
use App\Http\Requests\StoreMobileCreditNoteRequest;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use App\SubscriptionFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correcting an invoice from the phone with a credit note.
 *
 * Issuing keeps the conditions of an invoice: two-factor authentication on the
 * account and the password, or a passkey, proved at that moment. There is no draft here: a
 * note is prepared, shown with its total, and issued, or nothing is written.
 */
class MobileCreditNoteController
{
    public function __construct(
        private readonly CreateMobileCreditNote $notes,
        private readonly ConfirmMobileIssuer $issuer,
    ) {}

    /**
     * The invoice's lines with what is left to credit, and whether this person
     * can issue a note against it now.
     */
    public function show(Request $request, string $documentPublicId, SubscriptionAccess $subscription): JsonResponse
    {
        $legalEntity = $this->legalEntity($request);
        $source = $this->source($legalEntity, $documentPublicId);
        $user = $this->user($request);

        $reason = match (true) {
            Gate::denies('create', FiscalDocument::class) => 'A sua função nesta empresa não permite emitir notas de crédito.',
            ! $subscription->allows($legalEntity->workspace_id, SubscriptionFeature::Invoicing) => 'O plano actual não inclui facturação. Veja a assinatura em facturac.ao.',
            ! $user->hasEnabledTwoFactorAuthentication() => 'Para emitir, active a autenticação em dois passos em facturac.ao.',
            default => $this->notes->refusal($source)
                ?? ($this->notes->canBeNumbered($source) ? null : 'Não há série de Nota de Crédito disponível. Peça uma série à AGT em facturac.ao.'),
        };

        return response()->json(['data' => [
            'document_public_id' => $source->public_id,
            'document_no' => $source->document_no,
            'customer_name' => $source->customer_name,
            'gross_total_minor' => $source->gross_total_minor,
            'currency_code' => $source->currency_code,
            'can_issue' => $reason === null,
            'reason' => $reason,
            'lines' => $this->notes->lines($source),
        ]]);
    }

    public function preview(PreviewMobileCreditNoteRequest $request, string $documentPublicId): JsonResponse
    {
        Gate::authorize('create', FiscalDocument::class);

        $source = $this->source($this->legalEntity($request), $documentPublicId);

        return response()->json(['data' => $this->notes->preview($source, $request->creditLines())]);
    }

    public function store(StoreMobileCreditNoteRequest $request, string $documentPublicId, MobileDocumentReceipt $receipt): JsonResponse
    {
        $user = $this->user($request);
        $legalEntity = $this->legalEntity($request);
        $source = $this->source($legalEntity, $documentPublicId);

        if (Gate::denies('create', FiscalDocument::class)) {
            return $this->refusal('CREDIT_NOTE_NOT_ALLOWED', 'A sua função nesta empresa não permite emitir notas de crédito.', Response::HTTP_FORBIDDEN);
        }

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return $this->refusal('TWO_FACTOR_REQUIRED_TO_ISSUE', 'Active a autenticação em dois passos em facturac.ao para emitir.', Response::HTTP_FORBIDDEN);
        }

        $unconfirmed = $this->issuer->refusal($user, $request->validated());

        if ($unconfirmed !== null) {
            return $this->refusal($unconfirmed['code'], $unconfirmed['message'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $note = $this->notes->execute($legalEntity, $user, $source, [
                'client_key' => (string) $request->validated('client_key'),
                'reason' => trim((string) $request->validated('reason')),
                'lines' => $request->creditLines(),
                'expected_total_minor' => (int) $request->validated('expected_total_minor'),
            ]);
        } catch (FiscalFinalizationBlocked|BillingActionRefused $exception) {
            return $this->refusal('CREDIT_NOTE_BLOCKED', $exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (SubscriptionRequired $exception) {
            return $this->refusal('SUBSCRIPTION_REQUIRED', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return response()->json(
            ['data' => $receipt->for($note, $legalEntity)],
            $note->replayed ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }

    /**
     * An issued document of the company on screen; anything else is not found.
     */
    private function source(LegalEntity $legalEntity, string $publicId): FiscalDocument
    {
        return FiscalDocument::query()
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->where('public_id', $publicId)
            ->whereNotNull('document_no')
            ->firstOrFail();
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
