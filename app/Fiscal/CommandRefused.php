<?php

namespace App\Fiscal;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A command the caller can correct: the code says what kind of refusal, and
 * the detail and fields say what to change. Both are written by the
 * application, never echoed from the request.
 */
final class CommandRefused extends HttpException
{
    /** @param array<string, string> $fields */
    public function __construct(int $status, string $errorCode, public readonly ?string $detail = null, public readonly array $fields = [])
    {
        parent::__construct($status, $errorCode);
    }

    /** @param array<string, string> $fields */
    public static function invalid(array $fields): self
    {
        return new self(422, 'VALIDATION_FAILED', null, $fields);
    }
}
