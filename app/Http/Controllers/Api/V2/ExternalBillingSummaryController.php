<?php

namespace App\Http\Controllers\Api\V2;

use App\AgtEnvironment;
use App\Fiscal\AnalyticsCapabilities;
use App\Fiscal\BillingSummaryCommand;
use App\Fiscal\IntegrationReadContext;
use App\Http\Resources\BillingSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ExternalBillingSummaryController
{
    public function __construct(private AnalyticsCapabilities $capabilities) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = $request->attributes->get('integration_context');
        abort_unless($context instanceof IntegrationReadContext, 401);
        $context->authorize('analytics.billing.read');
        $path = Validator::make(['workspace' => $request->route('workspacePublicId'), 'entity' => $request->route('entityPublicId'), 'environment' => $request->route('environment')],
            ['workspace' => ['required', 'ulid'], 'entity' => ['required', 'ulid'], 'environment' => ['required', Rule::enum(AgtEnvironment::class)]])->validate();
        abort_unless(strtolower($path['workspace']) === $context->workspacePublicId && strtolower($path['entity']) === $context->entityPublicId && $path['environment'] === $context->environment()->value, 403);
        $command = BillingSummaryCommand::fromQueryString((string) $request->server->get('QUERY_STRING', ''));
        $data = $this->capabilities->billingSummary($context, $command);

        return response()->json(['data' => (new BillingSummaryResource($data))->resolve($request), 'meta' => [
            'request_id' => $context->correlationId(), 'workspace_public_id' => $context->workspacePublicId,
            'legal_entity_public_id' => $context->entityPublicId, 'environment' => $context->environment()->value]]);
    }
}
