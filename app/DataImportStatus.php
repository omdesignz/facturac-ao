<?php

namespace App;

enum DataImportStatus: string
{
    case AwaitingMapping = 'awaiting_mapping';
    case Ready = 'ready';
    case HasErrors = 'has_errors';
    case Importing = 'importing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingMapping => 'A mapear colunas',
            self::Ready => 'Pronto para importar',
            self::HasErrors => 'Requer correcções',
            self::Importing => 'A importar',
            self::Completed => 'Concluído',
            self::Cancelled => 'Cancelado',
            self::Failed => 'Falhou',
        };
    }
}
