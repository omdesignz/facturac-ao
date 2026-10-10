<?php

namespace App\Http\Controllers\Api\V1;

use App\AgtEnvironment;
use App\Fiscal\CatalogueCapabilities;
use App\Fiscal\CatalogueListCommand;
use App\Fiscal\CatalogueReadCommand;
use App\Fiscal\IntegrationReadContext;
use App\Http\Resources\CatalogueItemReadResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ExternalCatalogueReadController
{
    public function __construct(private readonly CatalogueCapabilities $capabilities) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = $request->attributes->get('integration_context');
        abort_unless($context instanceof IntegrationReadContext, 401);
        $path = Validator::make(['workspace' => $request->route('workspacePublicId'), 'entity' => $request->route('entityPublicId'), 'environment' => $request->route('environment'), 'resource' => $request->route('itemPublicId')],
            ['workspace' => ['required', 'ulid'], 'entity' => ['required', 'ulid'], 'environment' => ['required', Rule::enum(AgtEnvironment::class)], 'resource' => ['nullable', 'ulid']])->validate();
        abort_unless(strtolower($path['workspace']) === $context->workspacePublicId && strtolower($path['entity']) === $context->entityPublicId && $path['environment'] === $context->environment()->value, 403);
        $meta = ['request_id' => $context->correlationId(), 'workspace_public_id' => $context->workspacePublicId, 'legal_entity_public_id' => $context->entityPublicId, 'environment' => $context->environment()->value, 'data_scope' => 'legal_entity_master'];
        if ($path['resource'] !== null) {
            Validator::make(['filters' => $request->query()], ['filters' => ['array', 'max:0']])->validate();
            $data = $this->capabilities->readItem($context, CatalogueReadCommand::fromPublicId(strtolower($path['resource'])));

            return response()->json(['data' => (new CatalogueItemReadResource($data))->resolve($request), 'meta' => $meta]);
        }
        $documents = $this->capabilities->listItems($context, CatalogueListCommand::fromInput($request->query()));

        return response()->json(['data' => CatalogueItemReadResource::collection($documents->items())->resolve($request), 'meta' => [...$meta,
            'page' => $documents->currentPage(), 'per_page' => $documents->perPage(), 'total' => $documents->total(), 'last_page' => $documents->lastPage()]]);
    }
}
