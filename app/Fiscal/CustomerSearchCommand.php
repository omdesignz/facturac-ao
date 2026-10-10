<?php

namespace App\Fiscal;

final readonly class CustomerSearchCommand
{
    private function __construct(public string $search, public string $status) {}

    public static function fromInput(mixed $search, mixed $status): self
    {
        abort_unless(is_string($search) && is_string($status), 422);
        $search = trim($search, ' ');
        abort_unless(mb_check_encoding($search, 'UTF-8') && mb_strlen($search) >= 2 && mb_strlen($search) <= 80
            && preg_match('/[\p{Cc}]/u', $search) === 0 && in_array($status, ['active', 'inactive', 'all'], true), 422);

        return new self($search, $status);
    }
}
