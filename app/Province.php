<?php

namespace App;

/**
 * The provinces of Angola, as they stand after the 2024 reform.
 *
 * Lei n.º 14/24 took the country from 18 provinces to 21: Cuando Cubango was
 * split into Cuando and Cubango, Moxico Leste was carved out of Moxico, and
 * Icolo e Bengo out of Luanda.
 *
 * Kept as a closed list because the code is what reports group by: a typed
 * "LU" and a typed "LUA" are the same province to a person and two different
 * ones to every total.
 *
 * The eighteen older codes are the ISO 3166-2:AO subdivision codes. ISO has
 * not yet published codes for the four provinces the reform created, so those
 * follow the same three-letter convention and are provisional — if ISO or the
 * AGT publish different ones, only this file and a data migration change,
 * because the code never leaves the application.
 */
enum Province: string
{
    case Bengo = 'BGO';
    case Benguela = 'BGU';
    case Bie = 'BIE';
    case Cabinda = 'CAB';
    case Cuando = 'CUD';
    case Cubango = 'CUB';
    case CuanzaNorte = 'CNO';
    case CuanzaSul = 'CUS';
    case Cunene = 'CNN';
    case Huambo = 'HUA';
    case Huila = 'HUI';
    case IcoloEBengo = 'ICB';
    case Luanda = 'LUA';
    case LundaNorte = 'LNO';
    case LundaSul = 'LSU';
    case Malanje = 'MAL';
    case Moxico = 'MOX';
    case MoxicoLeste = 'MXL';
    case Namibe = 'NAM';
    case Uige = 'UIG';
    case Zaire = 'ZAI';

    /**
     * Abolished in 2024, kept so records written before the reform still read
     * and still validate. Never offered for a new choice.
     */
    case CuandoCubango = 'CCU';

    public function label(): string
    {
        return match ($this) {
            self::Bengo => 'Bengo',
            self::Benguela => 'Benguela',
            self::Bie => 'Bié',
            self::Cabinda => 'Cabinda',
            self::Cuando => 'Cuando',
            self::Cubango => 'Cubango',
            self::CuanzaNorte => 'Cuanza Norte',
            self::CuanzaSul => 'Cuanza Sul',
            self::Cunene => 'Cunene',
            self::Huambo => 'Huambo',
            self::Huila => 'Huíla',
            self::IcoloEBengo => 'Icolo e Bengo',
            self::Luanda => 'Luanda',
            self::LundaNorte => 'Lunda Norte',
            self::LundaSul => 'Lunda Sul',
            self::Malanje => 'Malanje',
            self::Moxico => 'Moxico',
            self::MoxicoLeste => 'Moxico Leste',
            self::Namibe => 'Namibe',
            self::Uige => 'Uíge',
            self::Zaire => 'Zaire',
            self::CuandoCubango => 'Cuando Cubango (extinta em 2024)',
        };
    }

    /** Whether the province still exists to be chosen. */
    public function isCurrent(): bool
    {
        return $this !== self::CuandoCubango;
    }

    /**
     * The provinces a new address can be in, in reading order.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $province) {
            if (! $province->isCurrent()) {
                continue;
            }

            $options[] = [
                'value' => $province->value,
                'label' => $province->label(),
            ];
        }

        // Sorted by name, with the collator so Bié and Huíla land where a
        // reader expects rather than after Z.
        usort($options, fn (array $a, array $b): int => strcoll($a['label'], $b['label']));

        return $options;
    }
}
