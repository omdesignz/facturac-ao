<?php

namespace App\Http\Middleware;

use App\Fiscal\CommandAudit;
use App\Fiscal\CommandCapability;
use App\Fiscal\CustomerCreateInput;
use App\Fiscal\IntegrationCommandContext;
use App\Fiscal\IntegrationRateLimiter;
use App\Fiscal\ServiceCreateInput;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ExternalCommandBoundary
{
    public function __construct(private readonly IntegrationRateLimiter $limiter) {}

    public static function matches(Request $request): bool
    {
        return str_starts_with($request->decodedPath(), 'api/integrations/commands/');
    }

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::matches($request)) {
            return $next($request);
        }
        $requestId = (string) Str::uuid();
        Context::flush();
        Context::add('request_id', $requestId);
        $context = null;
        $previous = null;
        $json = '';
        $capability = preg_match('~\Aapi/integrations/commands/v1/workspaces/[^/]+/legal-entities/[^/]+/environments/[^/]+/catalogue-services\z~', $request->decodedPath()) === 1
            ? CommandCapability::ServiceCreate : CommandCapability::CustomerCreate;
        try {
            abort_unless(config('integrations.enabled') && config('integrations.commands_enabled'), 404);
            abort_unless($request->isSecure(), 403);
            abort_unless(DB::getDriverName() === 'pgsql' || (DB::getDriverName() === 'sqlite' && app()->environment('testing')), 503);
            if (DB::getDriverName() === 'pgsql') {
                $previous = DB::selectOne('SHOW statement_timeout')->statement_timeout;
                DB::statement("SET statement_timeout='2s'");
            }
            $this->limiter->consume(['command-ip:'.$request->ip() => 60]);
            if ($request->getRealMethod() === 'POST') {
                abort_unless(preg_match('/\Aapplication\/json(?:\s*;\s*charset\s*=\s*utf-8)?\z/i', $request->header('Content-Type', '')) === 1 && ! $request->headers->has('Content-Encoding'), 415);
                $content = $request->getContent(true);
                $json = is_resource($content) ? stream_get_contents($content, 4097) : $request->getContent();
                abort_unless(is_string($json) && strlen($json) <= 4096, 413);
            }
            $authorization = $request->headers->all('authorization');
            abort_unless(count($authorization) === 1 && preg_match('/\ABearer ([^\s,]+)\z/i', $authorization[0], $parts) === 1, 401);
            $context = IntegrationCommandContext::authenticate($parts[1], $requestId);
            $this->limiter->consume(['command-integration:'.$context->integrationId => 10, 'command-workspace:'.$context->workspaceId => 30]);
            $context->authorize(capability: $capability);
            foreach (['X-Impersonate-User', 'X-On-Behalf-Of', 'X-Agent-ID', 'X-Act-As', 'X-Delegation-ID'] as $header) {
                abort_if($request->headers->has($header), 403);
            }
            abort_unless(preg_match('~\Aapi/integrations/commands/v1/workspaces/([^/]+)/legal-entities/([^/]+)/environments/([^/]+)/(?:customers|catalogue-services)\z~', $request->decodedPath(), $route) === 1, 404);
            foreach ([$route[1], $route[2]] as $id) {
                abort_unless(preg_match('/\A[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}\z/', $id) === 1, 422);
            }
            abort_unless(strtolower($route[1]) === $context->workspacePublicId && strtolower($route[2]) === $context->entityPublicId && $route[3] === $context->environment, 403);
            abort_unless($request->getRealMethod() === 'POST' && $request->method() === 'POST' && ! $request->headers->has('X-HTTP-Method-Override'), 405, '', ['Allow' => 'POST']);
            abort_if($request->getQueryString() !== null || $request->query->count() !== 0, 422);
            $context = $context->withClientRequestId($request->header('X-Client-Request-ID'));
            $keys = $request->headers->all('idempotency-key');
            abort_if(count($keys) > 1, 400);
            abort_unless(count($keys) === 1, 422);
            $key = trim($keys[0], " \t");
            abort_unless(preg_match('/\A[\x20-\x7e]{1,128}\z/', $key) === 1 && trim($key) !== '', 422);
            $request->attributes->set('command_context', $context);
            $request->attributes->set('command_key', $key);
            $request->attributes->set('command_input', $capability === CommandCapability::CustomerCreate ? CustomerCreateInput::external($json) : ServiceCreateInput::external($json));
            $response = $next($request);
            abort_if($response->getStatusCode() >= 400, $response->getStatusCode(), $response->headers->get('X-Command-Error-Code', ''), array_filter(['Allow' => $response->headers->get('Allow'), 'Retry-After' => $response->headers->get('Retry-After')]));
        } catch (\Throwable $exception) {
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503;
            $status = in_array($status, [400, 401, 403, 404, 405, 409, 413, 415, 422, 429, 503], true) ? $status : 503;
            $code = match ($status) {
                400 => 'INVALID_REQUEST', 401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN', 404 => 'NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED',
                409 => in_array($exception->getMessage(), ['IDEMPOTENCY_CONFLICT', 'COMMAND_IN_PROGRESS', 'CUSTOMER_CONFLICT', 'CATALOGUE_CONFLICT'], true) ? $exception->getMessage() : 'COMMAND_IN_PROGRESS',
                413 => 'PAYLOAD_TOO_LARGE', 415 => 'UNSUPPORTED_MEDIA_TYPE', 422 => 'VALIDATION_FAILED', 429 => 'RATE_LIMITED', default => 'SERVICE_UNAVAILABLE',
            };
            if ($context !== null && in_array($status, [401, 403], true)) {
                try {
                    CommandAudit::record($context, 'external.command.denied', 'denied', capability: $capability);
                } catch (\Throwable) {
                    $status = 503;
                    $code = 'SERVICE_UNAVAILABLE';
                }
            }
            $response = response()->json(['error' => ['code' => $code, 'message' => 'Não foi possível concluir o pedido.'], 'meta' => ['request_id' => $requestId]], $status,
                $exception instanceof HttpExceptionInterface ? array_intersect_key($exception->getHeaders(), array_flip(['Allow', 'Retry-After'])) : []);
        } finally {
            if ($previous !== null) {
                try {
                    DB::select("SELECT set_config('statement_timeout', ?, false)", [$previous]);
                } catch (\Throwable) {
                    DB::purge();
                    $response = response()->json(['error' => ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'Não foi possível concluir o pedido.'], 'meta' => ['request_id' => $requestId]], 503);
                }
            }
        }
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Vary', 'Authorization');

        return $response->prepare($request);
    }
}
