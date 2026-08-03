<?php

namespace App;

enum AgtConnectionCheckStatus: string
{
    case Running = 'running';
    case Passed = 'passed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Em curso',
            self::Passed => 'Concluído',
            self::Failed => 'Falhou',
        };
    }
}
