<?php

namespace App\Fiscal;

use App\AgtEnvironment;
use Illuminate\Database\Eloquent\Model;

interface DocumentReadContext
{
    public function workspaceId(): int;

    public function legalEntityId(): int;

    public function environment(): AgtEnvironment;

    public function correlationId(): string;

    public function authorize(string $permission): void;

    /** @return array<string, mixed> */
    public function audit(): array;

    public function auditCauser(): Model;

    public function recordSuccessfulUse(): void;
}
