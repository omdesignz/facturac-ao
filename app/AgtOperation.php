<?php

namespace App;

enum AgtOperation: string
{
    case ListSeriesProbe = 'list_series_probe';
    case RequestSeries = 'request_series';
    case SyncSeries = 'sync_series';

    public function label(): string
    {
        return match ($this) {
            self::ListSeriesProbe => 'Verificação de ligação (listar séries)',
            self::RequestSeries => 'Pedido de série fiscal',
            self::SyncSeries => 'Sincronização de séries fiscais',
        };
    }

    public function endpointPath(): string
    {
        return match ($this) {
            self::ListSeriesProbe, self::SyncSeries => (string) config('agt.operations.list_series.path', '/listarSeries'),
            self::RequestSeries => (string) config('agt.operations.request_series.path', '/solicitarSerie'),
        };
    }
}
