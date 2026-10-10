<?php

namespace App\Fiscal;

use Illuminate\Support\Facades\Validator;

final readonly class CatalogueReadCommand
{
    private function __construct(public string $publicId) {}

    public static function fromPublicId(string $publicId): self
    {
        Validator::make(['resource' => $publicId], ['resource' => ['required', 'ulid']])->validate();

        return new self(strtolower($publicId));
    }
}
