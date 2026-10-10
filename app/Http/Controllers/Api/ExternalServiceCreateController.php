<?php

namespace App\Http\Controllers\Api;

use App\Fiscal\ExternalServiceCommand;
use App\Fiscal\IntegrationCommandContext;
use App\Fiscal\ServiceCreateInput;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ExternalServiceCreateController
{
    public function __invoke(Request $request, ExternalServiceCommand $commands): Response
    {
        $context = $request->attributes->get('command_context');
        $input = $request->attributes->get('command_input');
        abort_unless($context instanceof IntegrationCommandContext && $input instanceof ServiceCreateInput, 403);
        try {
            $result = $commands->execute($context, $input, $request->attributes->get('command_key'));
        } catch (HttpException $exception) {
            return new Response('', $exception->getStatusCode(), [...$exception->getHeaders(), 'X-Command-Error-Code' => $exception->getMessage()]);
        }

        return new Response($result['body'], 201, ['Content-Type' => 'application/json', 'Idempotency-Replayed' => $result['replayed'] ? 'true' : 'false']);
    }
}
