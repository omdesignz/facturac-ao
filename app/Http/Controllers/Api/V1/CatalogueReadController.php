<?php

namespace App\Http\Controllers\Api\V1;

use App\Fiscal\CatalogueCapabilities;
use App\Fiscal\CatalogueListCommand;
use App\Fiscal\CatalogueReadCommand;
use App\Http\Requests\ReadCatalogueApiRequest;
use App\Http\Resources\CatalogueItemReadResource;
use Illuminate\Http\JsonResponse;

class CatalogueReadController
{
    public function __construct(private readonly CatalogueCapabilities $capabilities) {}

    public function index(ReadCatalogueApiRequest $request): JsonResponse
    {
        $context = $request->executionContext();
        $documents = $this->capabilities->listItems($context, CatalogueListCommand::fromInput($request->validated('filters')));

        return response()->json(['data' => CatalogueItemReadResource::collection($documents->items())->resolve($request),
            'meta' => ['request_id' => $context->correlationId, 'workspace_public_id' => $request->validated('workspace'),
                'legal_entity_public_id' => $request->validated('entity'), 'environment' => $context->environment->value, 'data_scope' => 'legal_entity_master',
                'page' => $documents->currentPage(), 'per_page' => $documents->perPage(), 'total' => $documents->total(), 'last_page' => $documents->lastPage()]]);
    }

    public function show(ReadCatalogueApiRequest $request): JsonResponse
    {
        $context = $request->executionContext();

        return (new CatalogueItemReadResource($this->capabilities->readItem($context,
            CatalogueReadCommand::fromPublicId($request->validated('resource')))))
            ->additional(['meta' => ['request_id' => $context->correlationId, 'workspace_public_id' => $request->validated('workspace'),
                'legal_entity_public_id' => $request->validated('entity'), 'environment' => $context->environment->value, 'data_scope' => 'legal_entity_master']])->response();
    }
}
