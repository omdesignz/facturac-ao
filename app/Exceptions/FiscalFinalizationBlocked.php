<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class FiscalFinalizationBlocked extends Exception implements ShouldntReport
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
