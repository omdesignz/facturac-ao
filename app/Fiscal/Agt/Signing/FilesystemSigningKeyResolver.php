<?php

namespace App\Fiscal\Agt\Signing;

use App\Fiscal\Agt\Contracts\SigningKeyResolver;
use App\Fiscal\Agt\Data\ResolvedSigningKey;
use App\Fiscal\Agt\Exceptions\SigningKeyUnavailable;
use OpenSSLAsymmetricKey;

final class FilesystemSigningKeyResolver implements SigningKeyResolver
{
    public function resolve(string $keyReference): ResolvedSigningKey
    {
        $path = $this->resolvePath($keyReference);
        $privateKeyPem = file_get_contents($path);

        if (! is_string($privateKeyPem) || $privateKeyPem === '') {
            throw new SigningKeyUnavailable('A chave privada indicada não pôde ser lida.');
        }

        $privateKey = openssl_pkey_get_private($privateKeyPem);

        if (! $privateKey instanceof OpenSSLAsymmetricKey) {
            throw new SigningKeyUnavailable('A chave privada indicada não contém uma chave RSA válida.');
        }

        $details = openssl_pkey_get_details($privateKey);

        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new SigningKeyUnavailable('A chave privada indicada tem de ser uma chave RSA.');
        }

        $bits = $details['bits'] ?? 0;
        $publicKeyPem = $details['key'] ?? null;
        $minimumBits = (int) config('agt.signing.minimum_rsa_bits', 2048);

        if (! is_int($bits) || $bits < $minimumBits) {
            throw new SigningKeyUnavailable("A chave RSA tem de possuir pelo menos {$minimumBits} bits.");
        }

        if (! is_string($publicKeyPem) || $publicKeyPem === '') {
            throw new SigningKeyUnavailable('Não foi possível obter a chave pública correspondente.');
        }

        return new ResolvedSigningKey(
            privateKeyPem: $privateKeyPem,
            fingerprint: hash('sha256', $publicKeyPem),
            bits: $bits,
        );
    }

    private function resolvePath(string $keyReference): string
    {
        $keyDirectory = config('agt.signing.key_directory');

        if (! is_string($keyDirectory) || $keyDirectory === '') {
            throw new SigningKeyUnavailable('O directório seguro de chaves AGT não está configurado.');
        }

        $rootPath = realpath($keyDirectory);

        if ($rootPath === false || ! is_dir($rootPath)) {
            throw new SigningKeyUnavailable('O directório seguro de chaves AGT ainda não está disponível.');
        }

        if (
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/-]{0,254}\z/', $keyReference) !== 1
            || str_contains($keyReference, '..')
            || str_starts_with($keyReference, '/')
        ) {
            throw new SigningKeyUnavailable('A referência da chave privada é inválida.');
        }

        $fileName = str_ends_with($keyReference, '.pem') ? $keyReference : "{$keyReference}.pem";
        $resolvedPath = realpath($rootPath.DIRECTORY_SEPARATOR.$fileName);

        if (
            $resolvedPath === false
            || ! is_file($resolvedPath)
            || ! str_starts_with($resolvedPath, $rootPath.DIRECTORY_SEPARATOR)
        ) {
            throw new SigningKeyUnavailable('A chave privada indicada não foi encontrada no cofre local.');
        }

        return $resolvedPath;
    }
}
