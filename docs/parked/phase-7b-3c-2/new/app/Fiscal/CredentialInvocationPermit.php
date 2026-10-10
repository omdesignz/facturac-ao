<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;

/** Object identity is meaningful only while registered in its private invocation session. */
final class CredentialInvocationPermit
{
    private function __clone() {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['authority' => 'restricted'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new TenantAiStorageUnavailable;
    }
}
