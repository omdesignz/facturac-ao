<?php

namespace App\Fiscal;

use Illuminate\Support\Facades\Validator;

final readonly class DocumentReadCommand
{
    private function __construct(public string $publicId) {}

    public static function fromPublicId(string $publicId): self
    {
        Validator::make(['public_id' => $publicId], ['public_id' => ['required', 'ulid']])->validate();

        return new self($publicId);
    }
}
