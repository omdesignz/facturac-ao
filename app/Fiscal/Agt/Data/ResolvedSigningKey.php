<?php

namespace App\Fiscal\Agt\Data;

final readonly class ResolvedSigningKey
{
    public function __construct(
        public string $privateKeyPem,
        public string $fingerprint,
        public int $bits,
    ) {}
}
