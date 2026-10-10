<?php

namespace App\Http\Controllers\Api\V1;

use App\Fiscal\DocumentCapabilities;
use App\Fiscal\DocumentListCommand;
use App\Fiscal\DocumentReadCommand;
use App\Http\Requests\ReadDocumentApiRequest;
use App\Http\Resources\FiscalDocumentReadResource;
use Illuminate\Http\JsonResponse;

class FiscalDocumentReadController
{
    public function __construct(private readonly DocumentCapabilities $capabilities) {}

    public function index(ReadDocumentApiRequest $request): JsonResponse
    {
        $context = $request->executionContext();
        $documents = $this->capabilities->listDocuments($context, DocumentListCommand::fromInput($request->validated('filters')));

        return response()->json(['data' => FiscalDocumentReadResource::collection($documents->items())->resolve($request),
            'meta' => ['request_id' => $context->correlationId, 'workspace_public_id' => $request->validated('workspace'),
                'legal_entity_public_id' => $request->validated('entity'), 'environment' => $context->environment->value,
                'page' => $documents->currentPage(), 'per_page' => $documents->perPage(), 'total' => $documents->total(), 'last_page' => $documents->lastPage()]]);
    }

    public function show(ReadDocumentApiRequest $request): JsonResponse
    {
        $context = $request->executionContext();

        return (new FiscalDocumentReadResource($this->capabilities->readDocument($context,
            DocumentReadCommand::fromPublicId($request->validated('document')))))
            ->additional(['meta' => ['request_id' => $context->correlationId, 'workspace_public_id' => $request->validated('workspace'),
                'legal_entity_public_id' => $request->validated('entity'), 'environment' => $context->environment->value]])->response();
    }
}
