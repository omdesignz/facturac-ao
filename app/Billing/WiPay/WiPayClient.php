<?php

namespace App\Billing\WiPay;

use App\Billing\Contracts\HostedPaymentGateway;
use App\Billing\Contracts\WiPayTokenStore;
use App\Billing\Data\CreateHostedPaymentData;
use App\Billing\Data\GatewayAccessTokenData;
use App\Billing\Data\HostedPaymentData;
use App\Billing\Exceptions\PaymentGatewayException;
use Carbon\CarbonImmutable;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;

final readonly class WiPayClient implements HostedPaymentGateway
{
    public function __construct(
        private WiPayConfiguration $configuration,
        private Factory $http,
        private WiPayTokenStore $tokens,
        private Repository $cache,
    ) {}

    public function signatureToken(): string
    {
        return $this->token('signature');
    }

    public function checkConnection(): void
    {
        $this->token('payment');
        $this->token('signature');
    }

    public function createPayment(CreateHostedPaymentData $request): HostedPaymentData
    {
        $token = $this->token('payment');

        try {
            $response = $this->request()->withToken($token)->post('/v1/hosts/payments', $request->payload());
        } catch (ConnectionException) {
            throw new PaymentGatewayException('wipay_connection_lost', outcomeUnknown: true);
        }

        if ($response->status() !== 303) {
            if ($response->status() === 401) {
                $this->tokens->invalidate($this->configuration->fingerprint(), 'payment', $token);
            }

            $definitelyRejected = in_array($response->status(), [400, 401, 403, 404, 422, 429], true);
            $retryAfter = ctype_digit($response->header('Retry-After'))
                ? max(1, min(60, (int) $response->header('Retry-After'))) : null;

            throw new PaymentGatewayException(
                'wipay_http_'.$response->status(),
                outcomeUnknown: ! $definitelyRejected,
                retryAfterSeconds: $retryAfter,
            );
        }

        $location = $response->header('Location');
        $providerId = $this->checkoutId($location);

        return new HostedPaymentData($providerId, $location, hash('sha256', $response->body().'|'.$location));
    }

    public function checkoutId(string $url): string
    {
        $parts = parse_url($url);
        $query = [];

        if (is_array($parts)) {
            parse_str($parts['query'] ?? '', $query);
        }

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ! in_array($parts['host'] ?? '', ['hosted.wipay.ao', 'pay.wiza.ao'], true)
            || ($parts['path'] ?? '/') !== '/'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! is_string($query['id'] ?? null) || ! is_string($query['nonce'] ?? null)
            || preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/', $query['id']) !== 1
            || preg_match('/\A[a-fA-F0-9]{32,128}\z/', $query['nonce']) !== 1) {
            throw new PaymentGatewayException('wipay_invalid_checkout_location', outcomeUnknown: true);
        }

        return strtolower($query['id']);
    }

    private function token(string $scope): string
    {
        $this->configuration->assertAvailable();
        $fingerprint = $this->configuration->fingerprint();
        $existing = $this->tokens->validToken($fingerprint, $scope);

        if ($existing !== null) {
            return $existing->value;
        }

        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new PaymentGatewayException('wipay_token_lock_unavailable');
        }

        return $store->lock('wipay-token:'.$fingerprint.':'.$scope, 30)->block(5, function () use ($scope, $fingerprint): string {
            $existing = $this->tokens->validToken($fingerprint, $scope);

            if ($existing !== null) {
                return $existing->value;
            }

            try {
                $response = $this->request()->post('/v1/credentials/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->configuration->clientId,
                    'client_secret' => $this->configuration->clientSecret(),
                    'scope' => $scope,
                ]);
            } catch (ConnectionException) {
                throw new PaymentGatewayException('wipay_token_unavailable');
            }

            $data = $response->json();

            if ($response->status() !== 200 || ! is_array($data)
                || ! is_string($data['access_token'] ?? null) || $data['access_token'] === ''
                || strlen($data['access_token']) > 8192 || ($data['token_type'] ?? null) !== 'Bearer'
                || ($data['scope'] ?? null) !== $scope || ! is_int($data['expires_in'] ?? null)
                || $data['expires_in'] < 60 || $data['expires_in'] > 86400) {
                throw new PaymentGatewayException('wipay_token_response_invalid');
            }

            $token = new GatewayAccessTokenData($data['access_token'], $scope, CarbonImmutable::now()->addSeconds($data['expires_in']));
            $this->tokens->remember($fingerprint, $token);

            return $token->value;
        });
    }

    private function request(): PendingRequest
    {
        return $this->http->baseUrl('https://api.wipay.ao')->acceptJson()->asJson()
            ->connectTimeout(max(1, min(10, $this->configuration->connectTimeoutSeconds)))
            ->timeout(max(1, min(20, $this->configuration->timeoutSeconds)))
            ->withoutRedirecting();
    }
}
