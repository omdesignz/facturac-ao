<?php

namespace App\Billing\Data;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use JsonException;

final readonly class PaymentCallbackData
{
    public function __construct(
        public string $providerId,
        public string $merchantReference,
        public int $amountMinor,
        public string $currencyCode,
        public string $status,
        public string $reason,
        public CarbonImmutable $occurredAt,
        public string $processor,
        public string $payloadSha256,
    ) {}

    public static function fromRawBody(string $body): self
    {
        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Callback inválido.');
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('Callback inválido.');
        }

        foreach (['id', 'reference_id', 'amount', 'currency', 'status', 'status_reason', 'status_datetime', 'processor'] as $field) {
            if (! is_string($data[$field] ?? null) || strlen($data[$field]) > 128) {
                throw new InvalidArgumentException('Callback inválido.');
            }
        }

        if (preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/', $data['id']) !== 1
            || preg_match('/\A[A-Za-z0-9._-]{1,128}\z/', $data['reference_id']) !== 1
            || preg_match('/\A(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?\z/', $data['amount']) !== 1
            || strtolower($data['currency']) !== 'aoa'
            || ! in_array($data['status'], ['accepted', 'rejected'], true)
            || preg_match('/\A\d{4}\z/', $data['status_reason']) !== 1
            || ($data['status'] === 'accepted') !== ($data['status_reason'] === '2000')
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $data['status_datetime']) !== 1
            || preg_match('/\A[A-Za-z0-9_-]{1,32}\z/', $data['processor']) !== 1) {
            throw new InvalidArgumentException('Callback inválido.');
        }

        [$major, $fraction] = array_pad(explode('.', $data['amount'], 2), 2, '');
        $amount = ((int) $major * 100) + (int) str_pad($fraction, 2, '0');
        try {
            $date = new DateTimeImmutable($data['status_datetime']);
            $dateErrors = DateTimeImmutable::getLastErrors();
        } catch (Exception) {
            throw new InvalidArgumentException('Callback inválido.');
        }

        if ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) {
            throw new InvalidArgumentException('Callback inválido.');
        }

        $occurredAt = CarbonImmutable::instance($date);

        if ($amount < 1 || $amount > 3_000_000_000 || $occurredAt->isAfter(CarbonImmutable::now()->addMinutes(5))) {
            throw new InvalidArgumentException('Callback inválido.');
        }

        return new self(strtolower($data['id']), $data['reference_id'], $amount, 'AOA', $data['status'], $data['status_reason'], $occurredAt, $data['processor'], hash('sha256', $body));
    }

    public function eventKey(): string
    {
        return hash('sha256', $this->providerId.'|'.$this->status.'|'.$this->reason);
    }
}
