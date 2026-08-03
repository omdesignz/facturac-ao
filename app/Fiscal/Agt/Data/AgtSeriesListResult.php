<?php

namespace App\Fiscal\Agt\Data;

final readonly class AgtSeriesListResult
{
    /**
     * @param  list<string>  $errorCodes
     * @param  list<AgtSeriesData>  $series
     */
    public function __construct(
        public bool $successful,
        public string $endpoint,
        public ?int $httpStatus,
        public ?string $requestBodySha256,
        public ?string $responseBodySha256,
        public ?string $resultCode,
        public array $errorCodes,
        public array $series,
        public string $safeMessage,
        public int $durationMs,
        public int $attemptCount = 1,
    ) {}
}
