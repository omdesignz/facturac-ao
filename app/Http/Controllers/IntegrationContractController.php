<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class IntegrationContractController extends Controller
{
    public function __invoke(string $contract): BinaryFileResponse
    {
        abort_unless(in_array($contract, ['read-v1', 'billing-v2', 'qualified-agt-v2', 'customer-create-v1', 'catalogue-service-create-v1', 'document-issue-v1'], true), 404);

        return response()->file(base_path('docs/openapi-external-'.$contract.'.json'), [
            'Content-Type' => 'application/json',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
