<?php

namespace App\Billing\Data;

use Carbon\CarbonImmutable;
use SensitiveParameter;

final readonly class GatewayAccessTokenData
{
    public function __construct(
        #[SensitiveParameter] public string $value,
        public string $scope,
        public CarbonImmutable $expiresAt,
    ) {}
}
