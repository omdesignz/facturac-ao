<?php

namespace App\Fiscal\Agt\Data;

final readonly class AgtDocumentStatusResult
{
    /**
     * @param  list<string>  $errorCodes
     */
    public function __construct(
        public string $documentNumber,
        public string $status,
        public array $errorCodes,
    ) {}
}
