<?php

namespace App\Fiscal;

/** Immutable metadata captured from the reviewed manifest and freshly authorized primary rows. */
final readonly class TenantAiVerificationPolicy
{
    /** @param array<string, mixed> $manifest
     * @param  array<string, mixed>  $account
     * @param  list<\stdClass>  $controls
     * @param  list<\stdClass>  $budgets
     * @param  list<\stdClass>  $credentials
     * @param  list<array{string, string, string}>  $references
     */
    public function __construct(
        public array $manifest,
        public array $account,
        public \stdClass $profile,
        public \stdClass $settings,
        public \stdClass $connection,
        public \stdClass $credential,
        public ?\stdClass $active,
        public \stdClass $approval,
        public \stdClass $acknowledgement,
        public array $controls,
        public array $budgets,
        public array $credentials,
        public array $references,
        public string $configurationDigest,
    ) {}
}
