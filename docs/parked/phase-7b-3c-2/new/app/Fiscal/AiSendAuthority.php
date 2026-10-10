<?php

namespace App\Fiscal;

/** Request-local authority for one admitted canonical request; possession never replaces fresh authority. */
interface AiSendAuthority
{
    public function input(): ProviderIntentInput;

    /** @return list<string> */
    public function permissions(): array;

    public function body(): string;

    public function matchesBody(#[\SensitiveParameter] string $body): bool;

    public function consume(): void;

    public function recheck(): void;

    public function authorizeSend(): void;

    public function transmissionStarted(): bool;

    /** Fresh non-locking gates after the response and again after finalization. */
    public function gate(): void;

    /** @param array{input: int, output: int}|null $usage */
    public function finalize(?array $usage, bool $received, bool $anomaly, bool $notSent): void;
}
