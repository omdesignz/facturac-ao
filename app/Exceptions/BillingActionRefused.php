<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * A billing action the user asked for that the rules do not allow.
 *
 * Distinct from a plain RuntimeException on purpose: PDOException extends that,
 * so a controller catching the general type and flashing the message would put
 * raw SQL in front of the user. Every message here is written to be read.
 */
class BillingActionRefused extends RuntimeException implements ShouldntReport
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
