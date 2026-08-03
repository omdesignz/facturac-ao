<?php

namespace App\Fiscal\Agt\Contracts;

use App\Fiscal\Agt\Data\ResolvedSigningKey;

interface SigningKeyResolver
{
    public function resolve(string $keyReference): ResolvedSigningKey;
}
