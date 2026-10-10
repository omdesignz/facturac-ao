<?php

namespace App\Http\Middleware;

use App\Fiscal\AssistantAudit;
use App\Fiscal\AssistantExecutionGuard;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class AssistantBoundary
{
    public static function matches(Request $request): bool
    {
        return $request->decodedPath() === 'assistant' || str_starts_with($request->decodedPath(), 'assistant/');
    }

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::matches($request)) {
            return $next($request);
        }

        return Context::scope(function () use ($request, $next): Response {
            try {
                abort_unless(config('assistant.enabled'), 404);
                $response = AssistantExecutionGuard::database(function () use ($request, $next): Response {
                    try {
                        $request->attributes->set('assistant_guard', new AssistantExecutionGuard);
                        if (str_ends_with($request->decodedPath(), '/interactions') || str_ends_with($request->decodedPath(), '/acknowledgement')) {
                            abort_unless($request->getRealMethod() === 'POST' && $request->method() === 'POST' && ! $request->headers->has('X-HTTP-Method-Override'), 405, '', ['Allow' => 'POST']);
                            abort_unless(preg_match('/\Aapplication\/json(?:\s*;\s*charset\s*=\s*utf-8)?\z/i', $request->header('Content-Type', '')) === 1
                                && ! $request->headers->has('Content-Encoding') && $request->allFiles() === [], 422);
                            $maximum = str_ends_with($request->decodedPath(), '/acknowledgement') ? 1024 : 12288;
                            $stream = $request->getContent(true);
                            $content = is_resource($stream) ? stream_get_contents($stream, $maximum + 1) : $request->getContent();
                            abort_unless(is_string($content) && strlen($content) <= $maximum, 422);
                            $request->attributes->set('assistant_json', $content);
                        }
                        abort_if($request->getQueryString() !== null || $request->query->count() !== 0, 422);
                        foreach (['X-Impersonate-User', 'X-On-Behalf-Of', 'X-Agent-ID', 'X-Act-As', 'X-Delegation-ID'] as $header) {
                            abort_if($request->headers->has($header), 403);
                        }
                        $response = $next($request);
                        $request->attributes->set('assistant_response_cookies', $response->headers->getCookies());
                        abort_if($response->getStatusCode() >= 400, $response->getStatusCode(), '', array_filter([
                            'Allow' => $response->headers->get('Allow'), 'Retry-After' => $response->headers->get('Retry-After')]));

                        return $response;
                    } catch (\Throwable $error) {
                        return $this->failure($request, $error, true);
                    }
                });
            } catch (\Throwable $error) {
                $response = $this->failure($request, $error, false);
            }
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Request-ID', (string) Context::get('request_id'));
            $response->headers->set('Vary', 'Cookie');

            return $response->prepare($request);
        }, ['request_id' => (string) Str::uuid()], ['assistant_boundary' => true]);
    }

    private function failure(Request $request, \Throwable $error, bool $audit): Response
    {
        $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof ModelNotFoundException ? 404 : 503);
        $status = in_array($status, [401, 403, 404, 405, 409, 419, 422, 429, 503], true) ? $status : 503;
        if ($audit) {
            try {
                AssistantAudit::record($request->attributes->get('assistant_context'), 'assistant.interaction.failed', ['outcome' => $status === 503 && $request->attributes->get('assistant_provider_disabled') === true ? 'provider_disabled' : AssistantAudit::failureOutcome($error)]);
            } catch (\Throwable) {
                $status = 503;
            }
        }
        $code = match ($status) {
            401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed', 409 => 'interaction_conflict',
            419 => 'session_expired', 422 => 'invalid_input', 429 => 'rate_limited', default => 'unavailable',
        };
        $response = response()->json(['error' => ['code' => $code, 'message' => 'Não foi possível concluir o pedido.'], 'request_id' => Context::get('request_id')], $status,
            $error instanceof HttpExceptionInterface ? array_intersect_key($error->getHeaders(), array_flip(['Allow', 'Retry-After'])) : []);
        foreach ($request->attributes->get('assistant_response_cookies', []) as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
