<?php

namespace App\Billing\Data;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class CreateHostedPaymentData
{
    public function __construct(
        public string $merchantReference,
        public int $amountMinor,
        public string $currencyCode,
        #[SensitiveParameter] public string $customer,
        public string $callbackUrl,
        public ?string $successUrl = null,
        public ?string $failureUrl = null,
    ) {
        if ($amountMinor < 1 || $amountMinor > 3_000_000_000 || $currencyCode !== 'AOA'
            || preg_match('/\A[A-Za-z0-9._-]{1,128}\z/', $merchantReference) !== 1
            || preg_match('/\A9\d{8}\z/', $customer) !== 1) {
            throw new InvalidArgumentException('Os dados do pedido de pagamento são inválidos.');
        }

        foreach ([$callbackUrl, $successUrl, $failureUrl] as $url) {
            if ($url !== null && (filter_var($url, FILTER_VALIDATE_URL) === false
                || parse_url($url, PHP_URL_SCHEME) !== 'https'
                || parse_url($url, PHP_URL_USER) !== null
                || parse_url($url, PHP_URL_PASS) !== null)) {
                throw new InvalidArgumentException('Os endereços do pagamento têm de utilizar HTTPS.');
            }
        }
    }

    /** @return array<string, string> */
    public function payload(): array
    {
        $payload = [
            'amount' => intdiv($this->amountMinor, 100).'.'.str_pad((string) ($this->amountMinor % 100), 2, '0', STR_PAD_LEFT),
            'currency' => strtolower($this->currencyCode),
            'reference_id' => $this->merchantReference,
            'customer' => $this->customer,
            'callback_url' => $this->callbackUrl,
        ];

        if ($this->successUrl !== null) {
            $payload['success_url'] = $this->successUrl;
        }

        if ($this->failureUrl !== null) {
            $payload['failure_url'] = $this->failureUrl;
        }

        return $payload;
    }
}
