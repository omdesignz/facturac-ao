<?php

namespace App;

enum AgtOperationalStatus: string
{
    case Unknown = 'unknown';

    public function label(): string
    {
        return 'Estado AGT não comprovado';
    }
}
