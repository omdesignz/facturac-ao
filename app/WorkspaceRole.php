<?php

namespace App;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Accountant = 'accountant';
    case Billing = 'billing';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Proprietário',
            self::Administrator => 'Administrador',
            self::Accountant => 'Contabilista',
            self::Billing => 'Facturação',
            self::Viewer => 'Consulta',
        };
    }

    public function canManageWorkspace(): bool
    {
        return in_array($this, [self::Owner, self::Administrator], true);
    }

    public function requiresMultiFactorAuthentication(): bool
    {
        return in_array($this, [self::Owner, self::Administrator, self::Accountant], true);
    }
}
