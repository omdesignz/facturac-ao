<?php

namespace App\Http\Controllers\Api;

use App\Fiscal\CustomerCreateInput;
use App\Fiscal\ExternalCustomerCommand;
use App\Fiscal\IntegrationCommandContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ExternalCustomerCreateController
{
    public function __invoke(Request $request, ExternalCustomerCommand $commands): Response
    {
        $context = $request->attributes->get('command_context');
        $input = $request->attributes->get('command_input');
        abort_unless($context instanceof IntegrationCommandContext && $input instanceof CustomerCreateInput, 403);
        try {
            $result = $commands->execute($context, $input, $request->attributes->get('command_key'));
        } catch (HttpException $exception) {
            return new Response('', $exception->getStatusCode(), [...$exception->getHeaders(), 'X-Command-Error-Code' => $exception->getMessage()]);
        }

        return new Response($result['body'], 201, ['Content-Type' => 'application/json', 'Idempotency-Replayed' => $result['replayed'] ? 'true' : 'false']);
    }
}
