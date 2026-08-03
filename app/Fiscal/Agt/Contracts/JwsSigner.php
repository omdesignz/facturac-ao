<?php

namespace App\Fiscal\Agt\Contracts;

interface JwsSigner
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function sign(array $payload, string $keyReference): string;

    public function fingerprint(string $keyReference): string;
}
