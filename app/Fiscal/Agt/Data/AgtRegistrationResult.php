<?php

namespace App\Fiscal\Agt\Data;

final readonly class AgtRegistrationResult
{
    /**
     * @param  list<string>  $errorCodes
     */
    public function __construct(
        public bool $accepted,
        public bool $retryable,
        public string $endpoint,
        public ?int $httpStatus,
        public string $requestBodySha256,
        public ?string $responseBody,
        public ?string $responseBodySha256,
        public ?string $requestId,
        public array $errorCodes,
        public string $safeMessage,
        public int $durationMs,
    ) {}
}
