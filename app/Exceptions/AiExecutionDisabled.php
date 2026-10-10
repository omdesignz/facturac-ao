<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AiExecutionDisabled extends HttpException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct(503, 'AI execution is disabled.');
    }
}
