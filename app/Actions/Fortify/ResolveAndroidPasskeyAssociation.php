<?php

namespace App\Actions\Fortify;

class ResolveAndroidPasskeyAssociation
{
    /**
     * @return array{namespace: string, package_name: string, sha256_cert_fingerprints: list<string>}|null
     */
    public function handle(): ?array
    {
        $package = config('services.android.passkey_package');
        $fingerprints = config('services.android.passkey_sha256_cert_fingerprints');

        if (! is_string($package) || strlen($package) > 255
            || preg_match('/\A[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+\z/', $package) !== 1
            || ! is_array($fingerprints) || $fingerprints === []) {
            return null;
        }

        $normalized = [];

        foreach ($fingerprints as $fingerprint) {
            if (! is_string($fingerprint)
                || preg_match('/\A[0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){31}\z/', trim($fingerprint)) !== 1) {
                return null;
            }

            $normalized[] = strtoupper(trim($fingerprint));
        }

        return [
            'namespace' => 'android_app',
            'package_name' => $package,
            'sha256_cert_fingerprints' => array_values(array_unique($normalized)),
        ];
    }

    /** @return list<string> */
    public function origins(): array
    {
        $association = $this->handle();

        if ($association === null) {
            return [];
        }

        return array_map(
            fn (string $fingerprint): string => 'android:apk-key-hash:'.rtrim(strtr(
                base64_encode(hex2bin(str_replace(':', '', $fingerprint))), '+/', '-_',
            ), '='),
            $association['sha256_cert_fingerprints'],
        );
    }
}
