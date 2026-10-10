<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class TenantAiStorageUnavailable extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('AI credential storage unavailable.');
    }
}
