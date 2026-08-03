<?php

namespace App;

use RuntimeException;

enum AgtEnvironment: string
{
    case Homologation = 'homologation';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Homologation => 'Homologação',
            self::Production => 'Produção',
        };
    }

    public function baseUrl(): string
    {
        $baseUrl = config("agt.environments.{$this->value}.base_url");

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException("O endereço AGT de {$this->label()} não está configurado.");
        }

        return rtrim($baseUrl, '/');
    }

    public function isEnabled(): bool
    {
        return (bool) config("agt.environments.{$this->value}.enabled", false);
    }
}
