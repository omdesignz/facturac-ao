<?php

namespace App\Http\Middleware;

use App\Fiscal\IntegrationRateLimiter;
use App\Fiscal\IntegrationReadContext;
use App\Fiscal\ReadOperationAudit;
use App\Fiscal\RequiredAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExternalIntegrationBoundary
{
    public function __construct(private readonly IntegrationRateLimiter $limiter) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! ReadOperationAudit::isExternalPath($request)) {
            return $next($request);
        }
        $requestId = (string) Str::uuid();
        Context::flush();
        Context::add(['request_id' => $requestId]);
        $request->headers->set('Accept', 'application/json');
        $context = null;
        try {
            abort_unless(config('integrations.enabled'), 404);
            abort_unless($request->isSecure(), 403);
            $this->limiter->consume(['ip:'.$request->ip() => 120]);
            $authorization = $request->headers->all('authorization');
            abort_unless(count($authorization) === 1 && strlen($authorization[0]) <= 256 && preg_match('/\ABearer ([^\s,]+)\z/i', $authorization[0], $parts) === 1, 401);
            $clientId = $request->header('X-Client-Request-ID');
            $context = IntegrationReadContext::authenticate($parts[1], $requestId);
            $this->limiter->consume(['integration:'.$context->integrationId => 60, 'workspace:'.$context->workspaceId() => 300]);
            $context = $context->withClientRequestId($clientId);
            $request->attributes->set('integration_context', $context);
            foreach (['X-Impersonate-User', 'X-On-Behalf-Of', 'X-Agent-ID', 'X-Act-As', 'X-Delegation-ID'] as $header) {
                abort_if($request->headers->has($header), 422);
            }
            if (in_array($request->method(), ['GET', 'HEAD'], true)) {
                abort_if(! in_array(trim($request->getContent()), ['', '{}', '[]'], true) || $request->request->count() !== 0 || $request->files->count() !== 0, 422);
            }
            abort_if((ReadOperationAudit::isQualifiedPath($request) || ReadOperationAudit::isBillingPath($request)) && ! in_array($request->method(), ['GET', 'HEAD'], true), 405, '', ['Allow' => 'GET, HEAD']);
            $response = $next($request);
        } catch (\Throwable $exception) {
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503;
            $response = response()->json([], $status, $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []);
            Log::warning('External read boundary failed', ['request_id' => $requestId, 'status' => $status]);
        }
        $status = $response->getStatusCode();
        if ($status === 500 && $request->is('api/integrations/v2', 'api/integrations/v2/*')) {
            $status = 503;
        }
        if ($status >= 400) {
            $code = match ($status) {
                401 => 'AUTHENTICATION_REQUIRED', 403 => 'FORBIDDEN', 404 => 'NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED', 422 => 'VALIDATION_FAILED', 429 => 'RATE_LIMITED', 503 => 'SERVICE_UNAVAILABLE', default => 'INTERNAL_ERROR'
            };
            if ($context !== null && in_array($status, [401, 403, 404, 405, 422, 429], true)) {
                try {
                    $denial = ReadOperationAudit::denial($request, external: true);
                    RequiredAudit::record(fn () => activity('capability')->causedBy($context->auditCauser())->event($denial['event'])
                        ->withProperties([...$context->audit(), 'outcome' => 'denied', 'error_code' => $code, 'capability_version' => $request->is('api/integrations/v2', 'api/integrations/v2/*') ? 2 : 1,
                            ...($request->is('api/integrations/v2', 'api/integrations/v2/*') ? ['method' => $request->method()] : []), ...$denial['properties']])->log('External read refused'));
                } catch (\Throwable) {
                    $status = 500;
                    $code = 'INTERNAL_ERROR';
                }
            } elseif ($context === null) {
                Log::notice('External read refused', ['request_id' => $requestId, 'operation_id' => (string) Str::uuid(), 'error_code' => $code]);
            }
            $response = response()->json(['error' => ['code' => $code, 'message' => 'Não foi possível concluir a consulta.'], 'meta' => ['request_id' => $requestId]], $status,
                array_filter(['Allow' => $response->headers->get('Allow'), 'Retry-After' => $response->headers->get('Retry-After')]));
            if ($status === 401) {
                $response->headers->set('WWW-Authenticate', 'Bearer');
            }
        }
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response->prepare($request);
    }
}
