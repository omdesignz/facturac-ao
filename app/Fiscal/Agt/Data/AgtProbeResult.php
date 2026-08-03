<?php

namespace App\Fiscal\Agt\Data;

final readonly class AgtProbeResult
{
    /**
     * @param  list<string>  $errorCodes
     */
    public function __construct(
        public bool $successful,
        public string $endpoint,
        public ?int $httpStatus,
        public ?string $requestBodySha256,
        public ?string $responseBodySha256,
        public ?string $resultCode,
        public array $errorCodes,
        public string $safeMessage,
        public int $durationMs,
        public int $attemptCount = 1,
    ) {}
}
