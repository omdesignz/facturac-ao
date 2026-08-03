<?php

namespace App\Fiscal\Agt\Data;

final readonly class AgtInvoiceStatusResult
{
    /**
     * @param  list<string>  $requestErrorCodes
     * @param  list<AgtDocumentStatusResult>  $documents
     */
    public function __construct(
        public bool $successful,
        public bool $retryable,
        public string $endpoint,
        public ?int $httpStatus,
        public string $requestBody,
        public string $requestBodySha256,
        public ?string $responseBody,
        public ?string $responseBodySha256,
        public ?string $resultCode,
        public array $requestErrorCodes,
        public array $documents,
        public string $safeMessage,
        public int $durationMs,
    ) {}

    public function isProcessing(): bool
    {
        return in_array($this->resultCode, ['7', '8'], true);
    }

    public function isCancelled(): bool
    {
        return $this->resultCode === '9';
    }
}
