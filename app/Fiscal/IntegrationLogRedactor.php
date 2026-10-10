<?php

namespace App\Fiscal;

use App\Models\IntegrationCredential;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

final class IntegrationLogRedactor
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if ($monolog instanceof \Monolog\Logger) {
            $monolog->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
                message: self::redactString($record->message),
                context: self::redact($record->context), extra: self::redact($record->extra),
            ));
        }
    }

    /**
     * @param  array<string|int, mixed>  $values
     * @return array<string|int, mixed>
     */
    public static function redact(array $values, int $depth = 0): array
    {
        if ($depth >= 8) {
            return ['[DEPTH REDACTED]'];
        }
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/authorization|cookie|secret|token|password|idempotency|command_key|fingerprint|key_hash|request_body|query_string|user.agent|(^|_)(url|uri|query|headers|body)(_|$)|^(request|request_snapshot)$/i', $key)) {
                $values[$key] = '[REDACTED]';
            } elseif (is_string($value)) {
                $values[$key] = self::redactString($value);
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value, $depth + 1);
            } elseif ($value instanceof \Throwable && self::sensitiveException($value)) {
                $values[$key] = '[SENSITIVE EXCEPTION REDACTED]';
            } elseif ($value instanceof \stdClass) {
                $values[$key] = self::redact(get_object_vars($value), $depth + 1);
            } elseif ($value instanceof IssuedIntegrationCredential || $value instanceof IntegrationCredential || $value instanceof IntegrationReadContext || $value instanceof IntegrationCommandContext || $value instanceof HumanCustomerCommandContext || $value instanceof CustomerCreateInput || $value instanceof ServiceCreateInput || $value instanceof HumanServiceCommandContext) {
                $values[$key] = '[OBJECT REDACTED]';
            }
        }

        return $values;
    }

    private static function sensitiveException(\Throwable $exception): bool
    {
        do {
            if (str_contains($exception->getMessage(), 'fcr1.') || str_contains($exception->getMessage(), 'secret_hash')) {
                return true;
            }
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return false;
    }

    private static function redactString(string $value): string
    {
        return preg_replace('/fcr1\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)?/', '[REDACTED]', $value) ?? '[REDACTED]';
    }
}
