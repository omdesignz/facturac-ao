<?php

namespace App\Exceptions;

use App\Fiscal\AiInvocationDenial;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class TenantAiInvocationRefused extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly AiInvocationDenial $reason, public readonly bool $recorded = false)
    {
        parent::__construct('AI invocation unavailable.');
    }
}
