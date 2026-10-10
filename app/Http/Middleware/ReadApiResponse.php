<?php

namespace App\Http\Middleware;

use App\Fiscal\ExecutionContext;
use App\Fiscal\ReadOperationAudit;
use App\Fiscal\RequiredAudit;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ReadApiResponse
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1', 'api/v1/*')) {
            return $next($request);
        }
        $request->headers->set('Accept', 'application/json');
        Context::add('request_id', (string) Str::uuid());
        $response = $next($request);
        $requestId = (string) (Context::get('request_id') ?? Str::uuid());
        $status = $response->getStatusCode();
        if ($status >= 300) {
            $status = $status < 400 ? 401 : $status;
            [$code, $message] = match ($status) {
                401 => ['AUTHENTICATION_REQUIRED', 'Entre na sua conta para consultar estes documentos.'],
                403 => ['FORBIDDEN', 'Não tem autorização para esta consulta. Verifique a conta e a autenticação multifactor.'],
                404 => ['NOT_FOUND', 'O recurso não foi encontrado neste contexto.'],
                405 => ['METHOD_NOT_ALLOWED', 'Este recurso permite apenas consultas.'],
                422 => ['VALIDATION_FAILED', 'Verifique os parâmetros da consulta.'],
                429 => ['RATE_LIMITED', 'Foram feitas demasiadas consultas. Tente novamente mais tarde.'],
                default => ['INTERNAL_ERROR', 'Não foi possível concluir a consulta.'],
            };
            $cookies = $response->headers->getCookies();
            $payload = json_decode((string) $response->getContent(), true);
            $response = response()->json(['error' => ['code' => $code, 'message' => $message,
                ...($status === 422 && ! ReadOperationAudit::isMasterPath($request) ? ['details' => $payload['errors'] ?? []] : [])], 'meta' => ['request_id' => $requestId]], $status,
                array_filter(['Retry-After' => $response->headers->get('Retry-After'), 'Allow' => $response->headers->get('Allow')]));
            foreach ($cookies as $cookie) {
                $response->headers->setCookie($cookie);
            }
            $actor = $request->user();
            if ($actor instanceof User && in_array($status, ReadOperationAudit::isMasterPath($request) ? [401, 403, 404, 405, 422, 429] : [403, 404], true)) {
                $context = $request->attributes->get('execution_context');
                try {
                    $denial = ReadOperationAudit::denial($request, external: false);
                    $write = fn () => activity('capability')->causedBy($actor)->event($denial['event'])
                        ->withProperties([...($context instanceof ExecutionContext ? $context->audit() : [
                            'actor_kind' => 'human', 'principal_kind' => 'user', 'effective_actor_id' => $actor->id,
                            'real_actor_id' => Context::get('impersonator_id') ?? $actor->id,
                            'impersonation_session' => Context::get('impersonation_session'),
                        ]),
                            'request_id' => $requestId, 'capability_version' => 1, 'outcome' => 'denied', 'error_code' => $code, ...$denial['properties'],
                            ...(ReadOperationAudit::isMasterPath($request) ? ['user_agent' => null, 'operation_id' => (string) Str::uuid()] : [])])
                        ->log('Read API refused');
                    if (ReadOperationAudit::isMasterPath($request)) {
                        RequiredAudit::record($write);
                    } else {
                        $write();
                    }
                } catch (\Throwable $exception) {
                    report($exception);
                    $response->setStatusCode(500);
                    $response->setData(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Não foi possível concluir a consulta.'], 'meta' => ['request_id' => $requestId]]);
                }
            }
        }
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response->prepare($request);
    }
}
