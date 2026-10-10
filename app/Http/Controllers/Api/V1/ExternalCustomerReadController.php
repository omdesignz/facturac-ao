<?php

namespace App\Http\Controllers\Api\V1;

use App\AgtEnvironment;
use App\Fiscal\CustomerCapabilities;
use App\Fiscal\CustomerListCommand;
use App\Fiscal\CustomerReadCommand;
use App\Fiscal\IntegrationReadContext;
use App\Http\Resources\CustomerReadResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ExternalCustomerReadController
{
    public function __construct(private readonly CustomerCapabilities $capabilities) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = $request->attributes->get('integration_context');
        abort_unless($context instanceof IntegrationReadContext, 401);
        $path = Validator::make(['workspace' => $request->route('workspacePublicId'), 'entity' => $request->route('entityPublicId'), 'environment' => $request->route('environment'), 'resource' => $request->route('customerPublicId')],
            ['workspace' => ['required', 'ulid'], 'entity' => ['required', 'ulid'], 'environment' => ['required', Rule::enum(AgtEnvironment::class)], 'resource' => ['nullable', 'ulid']])->validate();
        abort_unless(strtolower($path['workspace']) === $context->workspacePublicId && strtolower($path['entity']) === $context->entityPublicId && $path['environment'] === $context->environment()->value, 403);
        $meta = ['request_id' => $context->correlationId(), 'workspace_public_id' => $context->workspacePublicId, 'legal_entity_public_id' => $context->entityPublicId, 'environment' => $context->environment()->value, 'data_scope' => 'legal_entity_master'];
        if ($path['resource'] !== null) {
            Validator::make(['filters' => $request->query()], ['filters' => ['array', 'max:0']])->validate();
            $data = $this->capabilities->readCustomer($context, CustomerReadCommand::fromPublicId(strtolower($path['resource'])));

            return response()->json(['data' => (new CustomerReadResource($data))->resolve($request), 'meta' => $meta]);
        }
        $documents = $this->capabilities->listCustomers($context, CustomerListCommand::fromInput($request->query()));

        return response()->json(['data' => CustomerReadResource::collection($documents->items())->resolve($request), 'meta' => [...$meta,
            'page' => $documents->currentPage(), 'per_page' => $documents->perPage(), 'total' => $documents->total(), 'last_page' => $documents->lastPage()]]);
    }
}
