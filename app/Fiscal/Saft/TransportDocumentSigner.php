<?php

namespace App\Fiscal\Saft;

use App\Exceptions\BillingActionRefused;
use App\Models\TransportDocument;
use OpenSSLAsymmetricKey;

/** SAF-T (AO), Decreto Executivo 74/19: RSA-1024, SHA-1, PKCS#1 v1.5. */
final class TransportDocumentSigner
{
    public function sign(TransportDocument $document, ?TransportDocument $previous): void
    {
        if ($previous !== null && ! $this->verify($previous)) {
            throw BillingActionRefused::because('A série contém uma guia sem assinatura SAF-T válida. Configure uma nova série; os documentos emitidos não podem ser reescritos.');
        }

        $path = config('agt.saft_signing.key_path');
        $version = (string) config('agt.saft_signing.key_version');
        if (! is_string($path) || ! str_starts_with($path, DIRECTORY_SEPARATOR)
            || ! is_file($path) || ! is_readable($path)
            || preg_match('/^[1-9][0-9]*$/', $version) !== 1) {
            throw BillingActionRefused::because('Configure a chave privada SAF-T registada na AGT e a respectiva versão antes de emitir guias.');
        }

        $pem = file_get_contents($path);
        $key = is_string($pem) ? openssl_pkey_get_private($pem) : false;
        $details = $key instanceof OpenSSLAsymmetricKey ? openssl_pkey_get_details($key) : false;
        if (! is_array($details) || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] !== 1024) {
            throw BillingActionRefused::because('A assinatura SAF-T exige a chave RSA de 1024 bits registada na AGT; a chave JWS de facturação electrónica não é substituta.');
        }

        $document->previous_signature = $previous?->document_hash;
        $document->hash_control = $version;
        $document->signature_algorithm = 'RSA-SHA1-1024';
        $document->signature_public_key = $details['key'];
        if (! openssl_sign($this->message($document), $signature, $key, OPENSSL_ALGO_SHA1)) {
            throw BillingActionRefused::because('Não foi possível assinar a guia. Nenhum número foi consumido.');
        }
        $document->document_hash = base64_encode($signature);
    }

    public function verify(TransportDocument $document): bool
    {
        if ($document->signature_algorithm !== 'RSA-SHA1-1024' || strlen((string) $document->document_hash) !== 172
            || ! filled($document->signature_public_key)) {
            return false;
        }
        $signature = base64_decode((string) $document->document_hash, true);
        $publicKey = openssl_pkey_get_public((string) $document->signature_public_key);

        return is_string($signature) && $publicKey instanceof OpenSSLAsymmetricKey
            && openssl_verify($this->message($document), $signature, $publicKey, OPENSSL_ALGO_SHA1) === 1;
    }

    public function message(TransportDocument $document): string
    {
        return implode(';', [
            $document->movement_date->toDateString(),
            $document->system_entry_at?->setTimezone('Africa/Luanda')->format('Y-m-d\TH:i:s'),
            $document->document_no,
            intdiv($document->gross_total_minor, 100).'.'.str_pad((string) ($document->gross_total_minor % 100), 2, '0', STR_PAD_LEFT),
            $document->previous_signature ?? '',
        ]);
    }
}
