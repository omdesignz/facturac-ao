<?php

namespace App\Fiscal\Saft;

use DOMDocument;
use RuntimeException;

final class SaftValidator
{
    public function assertValid(string $xml): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);

            if (! $loaded) {
                throw new RuntimeException($this->message('O SAF-T produzido não é XML válido.'));
            }

            if (! $document->schemaValidateSource($this->schema(), LIBXML_NONET)) {
                throw new RuntimeException($this->message('O SAF-T produzido não cumpre o esquema AGT.'));
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function schema(): string
    {
        $path = (string) config('fiscal.saft.schema_path');
        $encoded = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($encoded)) {
            throw new RuntimeException('O esquema SAF-T (AO) local não está disponível.');
        }

        $compressed = base64_decode((string) preg_replace('/\\s+/', '', $encoded), true);
        $schema = is_string($compressed) ? gzdecode($compressed) : false;

        if (! is_string($schema)) {
            throw new RuntimeException('O esquema SAF-T (AO) local não pôde ser lido.');
        }

        $expectedChecksum = (string) config('fiscal.saft.schema_sha256');

        if (! hash_equals($expectedChecksum, hash('sha256', $schema))) {
            throw new RuntimeException('A integridade do esquema SAF-T (AO) local não pôde ser confirmada.');
        }

        return $schema;
    }

    private function message(string $heading): string
    {
        $errors = collect(libxml_get_errors())
            ->map(fn (\LibXMLError $error): string => trim($error->message).' (linha '.$error->line.')')
            ->filter()
            ->take(5)
            ->implode(' ');

        return $errors === '' ? $heading : $heading.' '.$errors;
    }
}
