<?php

namespace App\Fiscal;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Normalizer;

final readonly class ServiceCreateInput implements \JsonSerializable
{
    /** @param array<string, mixed> $attributes */
    private function __construct(public array $attributes, public bool $isExternal) {}

    public static function external(#[\SensitiveParameter] string $json): self
    {
        $values = StrictCommandJson::object($json);
        $keys = ['code', 'name', 'description', 'unit_of_measure', 'unit_price_minor', 'currency_code', 'tax_treatment'];
        abort_if(array_diff(array_keys($values), $keys) !== [], 422);
        foreach ($values as $value) {
            abort_unless(is_string($value), 422);
        }
        foreach (['code', 'name', 'unit_price_minor', 'currency_code', 'tax_treatment'] as $required) {
            abort_unless(isset($values[$required]), 422);
        }
        foreach (['name', 'description'] as $field) {
            if (isset($values[$field])) {
                $normalized = Normalizer::normalize($values[$field], Normalizer::FORM_C);
                abort_unless(is_string($normalized), 422);
                $values[$field] = trim($normalized, " \t\r\n");
                abort_unless(mb_strlen($values[$field]) >= 1 && mb_strlen($values[$field]) <= 255
                    && preg_match('/[\x{0000}-\x{001f}\x{007f}-\x{009f}]/u', $values[$field]) === 0, 422);
            }
        }
        $values['description'] ??= null;
        $values['code'] = strtoupper(trim($values['code'], " \t\r\n"));
        $values['unit_of_measure'] = strtoupper(trim($values['unit_of_measure'] ?? 'UN', " \t\r\n"));
        abort_unless(preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,59}\z/', $values['code']) === 1, 422);
        abort_unless(preg_match('/\A[A-Z][A-Z0-9._-]{0,9}\z/', $values['unit_of_measure']) === 1, 422);
        abort_unless(self::validMinor($values['unit_price_minor']) && $values['currency_code'] === 'AOA', 422);
        abort_unless(in_array($values['tax_treatment'], ['IVA_NOR_14', 'IVA_ISE_M00', 'NS_M02', 'IVA_ISE_M04'], true), 422);

        return new self($values, true);
    }

    public static function validMinor(string $value): bool
    {
        return PHP_INT_SIZE === 8 && preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', $value) === 1
            && (strlen($value) < 19 || strcmp($value, '9223372036854775807') <= 0);
    }

    public static function decimalMinor(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $minor = ltrim($whole.str_pad($fraction, 2, '0'), '0');
        $minor = $minor === '' ? '0' : $minor;
        if (strlen($value) > 18 || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?\z/', $value) !== 1 || ! self::validMinor($minor)) {
            throw ValidationException::withMessages(['unit_price' => 'Indique um preço válido.']);
        }

        return (int) $minor;
    }

    /**
     * Existing FormRequest-validated service attributes, without product state.
     *
     * @param  array<string, mixed>  $values
     */
    public static function human(array $values): self
    {
        $keys = ['code', 'name', 'description', 'unit_of_measure', 'unit_price', 'tax_type', 'tax_code', 'tax_percentage', 'tax_exemption_code', 'is_active'];
        abort_if(array_diff(array_keys($values), $keys) !== [], 422);
        if (isset($values['description']) && mb_strlen($values['description']) > 255) {
            throw ValidationException::withMessages(['description' => 'A descrição não pode exceder 255 caracteres.']);
        }
        Validator::make($values, ['code' => ['required', 'string', 'max:60', 'regex:/\A[A-Z0-9][A-Z0-9._-]*\z/'],
            'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:255'],
            'unit_of_measure' => ['required', 'string', 'max:10'], 'unit_price' => ['required', 'string', 'max:18'], 'is_active' => ['required', 'boolean']])->validate();
        $treatment = SupportedTaxTreatment::fromComponents($values['tax_type'], $values['tax_code'] ?? null, $values['tax_percentage'], $values['tax_exemption_code'] ?? null);
        abort_unless($treatment !== null, 422);

        return new self(['code' => $values['code'], 'name' => $values['name'], 'description' => $values['description'] ?? null,
            'unit_of_measure' => $values['unit_of_measure'], 'unit_price_minor' => (string) self::decimalMinor($values['unit_price']),
            'tax_treatment' => $treatment->value, 'is_active' => $values['is_active']], false);
    }

    public function jsonSerialize(): never
    {
        throw new \LogicException('Command data cannot be serialized.');
    }

    public function __serialize(): array
    {
        throw new \LogicException('Command input cannot be serialized.');
    }
}
