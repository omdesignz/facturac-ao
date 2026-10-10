<?php

namespace App\Http\Controllers\Api\V1;

use App\Fiscal\CustomerCapabilities;
use App\Fiscal\CustomerListCommand;
use App\Fiscal\CustomerReadCommand;
use App\Http\Requests\ReadCustomerApiRequest;
use App\Http\Resources\CustomerReadResource;
use Illuminate\Http\JsonResponse;

class CustomerReadController
{
    public function __construct(private readonly CustomerCapabilities $capabilities) {}

    public function index(ReadCustomerApiRequest $request): JsonResponse
    {
        $context = $request->executionContext();
        $documents = $this->capabilities->listCustomers($context, CustomerListCommand::fromInput($request->validated('filters')));

        return response()->json(['data' => CustomerReadResource::collection($documents->items())->resolve($request),
            'meta' => ['request_id' => $context->correlationId, 'workspace_public_id' => $request->validated('workspace'),
                'legal_entity_public_id' => $request->validated('entity'), 'environment' => $context->environment->value, 'data_scope' => 'legal_entity_master',
                'page' => $documents->currentPage(), 'per_page' => $documents->perPage(), 'total' => $documents->total(), 'last_page' => $documents->lastPage()]]);
    }

    public function show(ReadCustomerApiRequest $request): JsonResponse
    {
        $context = $request->executionContext();

        return (new CustomerReadResource($this->capabilities->readCustomer($context,
            CustomerReadCommand::fromPublicId($request->validated('resource')))))
            ->additional(['meta' => ['request_id' => $context->correlationId, 'workspace_public_id' => $request->validated('workspace'),
                'legal_entity_public_id' => $request->validated('entity'), 'environment' => $context->environment->value, 'data_scope' => 'legal_entity_master']])->response();
    }
}
