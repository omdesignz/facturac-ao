<?php

namespace App\Billing\WiPay;

use App\Billing\Exceptions\PaymentGatewayException;
use App\PaymentEnvironment;
use SensitiveParameter;

final readonly class WiPayConfiguration
{
    public function __construct(
        public ?PaymentEnvironment $environment,
        public string $clientId,
        #[SensitiveParameter] private string $clientSecret,
        public bool $productionEnabled = false,
        public int $connectTimeoutSeconds = 3,
        public int $timeoutSeconds = 15,
    ) {}

    public function isAvailable(): bool
    {
        return $this->environment !== null && str_starts_with($this->clientId, 'wp_')
            && str_starts_with($this->clientSecret, 'WPS_')
            && ($this->environment !== PaymentEnvironment::Production || $this->productionEnabled);
    }

    public function assertAvailable(): void
    {
        if (! $this->isAvailable()) {
            throw new PaymentGatewayException('wipay_not_configured');
        }
    }

    public function clientSecret(): string
    {
        return $this->clientSecret;
    }

    public function fingerprint(): string
    {
        return hash('sha256', ($this->environment->value ?? 'invalid').'|'.$this->clientId.'|'.$this->clientSecret);
    }
}
