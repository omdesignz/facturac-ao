<?php

namespace App;

enum AgtOperation: string
{
    case ListSeriesProbe = 'list_series_probe';
    case SyncSeries = 'sync_series';

    public function label(): string
    {
        return match ($this) {
            self::ListSeriesProbe => 'Verificação de ligação (listar séries)',
            self::SyncSeries => 'Sincronização de séries fiscais',
        };
    }

    public function endpointPath(): string
    {
        return match ($this) {
            self::ListSeriesProbe, self::SyncSeries => (string) config('agt.operations.list_series.path', '/listarSeries'),
        };
    }
}
