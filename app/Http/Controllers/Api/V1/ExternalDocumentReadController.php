<?php

namespace App\Http\Controllers\Api\V1;

use App\AgtEnvironment;
use App\Fiscal\DocumentCapabilities;
use App\Fiscal\DocumentListCommand;
use App\Fiscal\DocumentReadCommand;
use App\Fiscal\IntegrationReadContext;
use App\Http\Resources\FiscalDocumentReadResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ExternalDocumentReadController
{
    public function __construct(private readonly DocumentCapabilities $capabilities) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = $request->attributes->get('integration_context');
        abort_unless($context instanceof IntegrationReadContext, 401);
        $path = Validator::make(['workspace' => $request->route('workspacePublicId'), 'entity' => $request->route('entityPublicId'), 'environment' => $request->route('environment'), 'document' => $request->route('documentPublicId')],
            ['workspace' => ['required', 'ulid'], 'entity' => ['required', 'ulid'], 'environment' => ['required', Rule::enum(AgtEnvironment::class)], 'document' => ['nullable', 'ulid']])->validate();
        abort_unless(strtolower($path['workspace']) === $context->workspacePublicId && strtolower($path['entity']) === $context->entityPublicId && $path['environment'] === $context->environment()->value, 403);
        $meta = ['request_id' => $context->correlationId(), 'workspace_public_id' => $context->workspacePublicId, 'legal_entity_public_id' => $context->entityPublicId, 'environment' => $context->environment()->value];
        if ($path['document'] !== null) {
            Validator::make(['filters' => $request->query()], ['filters' => ['array', 'max:0']])->validate();
            $data = $this->capabilities->readDocument($context, DocumentReadCommand::fromPublicId(strtolower($path['document'])));

            return response()->json(['data' => (new FiscalDocumentReadResource($data))->resolve($request), 'meta' => $meta]);
        }
        $documents = $this->capabilities->listDocuments($context, DocumentListCommand::fromInput($request->query()));

        return response()->json(['data' => FiscalDocumentReadResource::collection($documents->items())->resolve($request), 'meta' => [...$meta,
            'page' => $documents->currentPage(), 'per_page' => $documents->perPage(), 'total' => $documents->total(), 'last_page' => $documents->lastPage()]]);
    }
}
