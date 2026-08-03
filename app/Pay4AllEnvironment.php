<?php

namespace App;

enum Pay4AllEnvironment: string
{
    case Simulation = 'simulation';
    case Homologation = 'homologation';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Simulation => 'Simulação local',
            self::Homologation => 'Homologação',
            self::Production => 'Produção',
        };
    }
}
