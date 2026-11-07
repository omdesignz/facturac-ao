<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Actions\RecordStockCount;
use App\Billing\SubscriptionAccess;
use App\Exceptions\BillingActionRefused;
use App\Exceptions\SubscriptionRequired;
use App\Http\Requests\PreviewMobileStockCountRequest;
use App\Http\Requests\StoreMobileStockCountRequest;
use App\Models\CatalogueItem;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\User;
use App\SubscriptionFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A stocktake from the phone: count shelf by shelf, review the differences,
 * then set every balance to what was found in one go.
 */
class MobileStockCountController
{
    public function __construct(private readonly RecordStockCount $counts) {}

    public function setup(Request $request, SubscriptionAccess $subscription): JsonResponse
    {
        $legalEntity = $this->legalEntity($request);
        $allowed = Gate::allows('create', CatalogueItem::class);
        $included = $subscription->allows($legalEntity->workspace_id, SubscriptionFeature::Inventory);

        return response()->json(['data' => [
            'can_count' => $allowed && $included,
            'reason' => match (true) {
                ! $allowed => 'A sua função nesta empresa não permite acertar o inventário.',
                ! $included => 'O plano actual não inclui inventário. Veja a assinatura em facturac.ao.',
                default => null,
            },
            'establishments' => $this->counts->establishments($legalEntity)
                ->map(fn (Establishment $establishment): array => [
                    'public_id' => $establishment->public_id,
                    'name' => $establishment->name,
                    'is_head_office' => (bool) $establishment->is_head_office,
                ])->values()->all(),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function preview(PreviewMobileStockCountRequest $request): JsonResponse
    {
        Gate::authorize('create', CatalogueItem::class);

        return response()->json(['data' => $this->counts->preview(
            $this->legalEntity($request),
            (string) $request->validated('establishment_public_id'),
            $request->countLines(),
        )])->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreMobileStockCountRequest $request): JsonResponse
    {
        if (Gate::denies('create', CatalogueItem::class)) {
            return $this->refusal('STOCK_COUNT_NOT_ALLOWED', 'A sua função nesta empresa não permite acertar o inventário.', Response::HTTP_FORBIDDEN);
        }

        try {
            $count = $this->counts->execute($this->legalEntity($request), $this->user($request), [
                'client_key' => (string) $request->validated('client_key'),
                'establishment_public_id' => (string) $request->validated('establishment_public_id'),
                'lines' => $request->countLines(),
                'note' => $request->note(),
            ]);
        } catch (SubscriptionRequired $exception) {
            return $this->refusal('SUBSCRIPTION_REQUIRED', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (BillingActionRefused|InvalidArgumentException $exception) {
            return $this->refusal('STOCK_COUNT_BLOCKED', $exception->getMessage(), Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => $count], $count['replayed'] ? Response::HTTP_OK : Response::HTTP_CREATED);
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
