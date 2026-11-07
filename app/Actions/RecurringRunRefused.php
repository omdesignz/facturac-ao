<?php

namespace App\Actions;

use RuntimeException;

/** Why an avença's coming run could not be skipped or raised now, in words for the person. */
final class RecurringRunRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
