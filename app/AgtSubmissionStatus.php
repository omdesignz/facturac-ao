<?php

namespace App;

enum AgtSubmissionStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Retrying = 'retrying';
    case Received = 'received';
    case Processing = 'processing';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Na fila',
            self::Sending => 'A transmitir',
            self::Retrying => 'Nova tentativa agendada',
            self::Received => 'Recebida pela AGT',
            self::Processing => 'Em validação',
            self::Valid => 'Validada',
            self::Invalid => 'Inválida',
            self::Rejected => 'Pedido rejeitado',
            self::Cancelled => 'Processamento cancelado',
            self::Failed => 'Requer intervenção',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Valid,
            self::Invalid,
            self::Rejected,
            self::Cancelled,
            self::Failed,
        ], true);
    }

    public function canSubmit(): bool
    {
        return in_array($this, [self::Pending, self::Retrying], true);
    }

    public function canPoll(): bool
    {
        return in_array($this, [self::Received, self::Processing], true);
    }
}
