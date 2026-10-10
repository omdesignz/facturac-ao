<?php

namespace App\Http\Controllers\Api\V2;

use App\AgtEnvironment;
use App\Fiscal\DocumentCapabilities;
use App\Fiscal\DocumentReadCommand;
use App\Fiscal\IntegrationReadContext;
use App\Http\Resources\QualifiedAgtStatusResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ExternalQualifiedAgtStatusController
{
    public function __construct(private readonly DocumentCapabilities $capabilities) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = $request->attributes->get('integration_context');
        abort_unless($context instanceof IntegrationReadContext, 401);
        $context->authorize('documents.agt-status.read');
        $path = Validator::make(['workspace' => $request->route('workspacePublicId'), 'entity' => $request->route('entityPublicId'),
            'environment' => $request->route('environment'), 'document' => $request->route('documentPublicId'), 'query' => $request->query()],
            ['workspace' => ['required', 'ulid'], 'entity' => ['required', 'ulid'], 'environment' => ['required', Rule::enum(AgtEnvironment::class)],
                'document' => ['required', 'ulid'], 'query' => ['array', 'max:0']])->validate();
        abort_unless(strtolower($path['workspace']) === $context->workspacePublicId && strtolower($path['entity']) === $context->entityPublicId
            && $path['environment'] === $context->environment()->value, 403);
        $data = $this->capabilities->readQualifiedAgtStatus($context, DocumentReadCommand::fromPublicId(strtolower($path['document'])));

        return response()->json(['data' => (new QualifiedAgtStatusResource($data))->resolve($request), 'meta' => [
            'request_id' => $context->correlationId(), 'workspace_public_id' => $context->workspacePublicId,
            'legal_entity_public_id' => $context->entityPublicId, 'environment' => $context->environment()->value]]);
    }
}
