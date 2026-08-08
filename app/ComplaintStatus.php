<?php

namespace App;

enum ComplaintStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Recebida',
            self::InProgress => 'Em análise',
            self::Resolved => 'Resolvida',
            self::Rejected => 'Indeferida',
        };
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Resolved, self::Rejected], true);
    }

    /** @return list<self> */
    public static function openStates(): array
    {
        return [self::Open, self::InProgress];
    }
}
