<?php

namespace App\Http\Controllers\Api;

use App\Fiscal\CommandRefused;
use App\Fiscal\DocumentIssueInput;
use App\Fiscal\ExternalDocumentCommand;
use App\Fiscal\IntegrationCommandContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ExternalDocumentIssueController
{
    public function __invoke(Request $request, ExternalDocumentCommand $commands): Response
    {
        $context = $request->attributes->get('command_context');
        $input = $request->attributes->get('command_input');
        abort_unless($context instanceof IntegrationCommandContext && $input instanceof DocumentIssueInput, 403);
        try {
            $result = $commands->execute($context, $input, $request->attributes->get('command_key'));
        } catch (HttpException $exception) {
            if ($exception instanceof CommandRefused) {
                $request->attributes->set('command_refusal', $exception);
            }

            return new Response('', $exception->getStatusCode(), [...$exception->getHeaders(), 'X-Command-Error-Code' => $exception->getMessage()]);
        }

        return new Response($result['body'], 201, ['Content-Type' => 'application/json', 'Idempotency-Replayed' => $result['replayed'] ? 'true' : 'false']);
    }
}
