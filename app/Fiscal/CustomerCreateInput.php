<?php

namespace App\Fiscal;

use Illuminate\Support\Facades\Validator;
use Normalizer;

final readonly class CustomerCreateInput implements \JsonSerializable
{
    /** @param array<string, mixed> $attributes */
    private function __construct(public array $attributes, public bool $isExternal) {}

    public static function external(#[\SensitiveParameter] string $json): self
    {
        $values = StrictCommandJson::object($json);
        abort_if(array_diff(array_keys($values), ['name', 'tax_identification_number', 'country_code']) !== [], 422);
        foreach ($values as $value) {
            abort_unless(is_string($value), 422);
        }
        abort_unless(isset($values['name'], $values['tax_identification_number']), 422);
        $name = Normalizer::normalize($values['name'], Normalizer::FORM_C);
        abort_unless(is_string($name), 422);
        $values['name'] = trim($name, " \t\r\n");
        $values['tax_identification_number'] = strtoupper(trim($values['tax_identification_number'], " \t\r\n"));
        $values['country_code'] = strtoupper(trim($values['country_code'] ?? 'AO', " \t\r\n"));
        abort_if(preg_match('/[\x{0000}-\x{001f}\x{007f}-\x{009f}]/u', $values['name']) !== 0, 422);
        abort_unless(! self::coreValidator($values)->fails(), 422);

        return new self([...$values, 'is_active' => true, 'payment_terms_days' => 0, 'credit_limit_minor' => null,
            'auto_send_documents' => false, 'address_line' => null, 'email' => null, 'phone' => null,
            'withholding_type' => null, 'withholding_rate_basis_points' => null, 'price_list_id' => null], true);
    }

    /** @param array<string, mixed> $attributes */
    public static function human(array $attributes): self
    {
        self::coreValidator($attributes)->validate();
        $allowed = ['name', 'tax_identification_number', 'country_code', 'is_active', 'payment_terms_days', 'credit_limit_minor',
            'auto_send_documents', 'address_line', 'email', 'phone', 'withholding_type', 'withholding_rate_basis_points', 'price_list_id'];
        abort_if(array_diff(array_keys($attributes), $allowed) !== [], 422);

        return new self($attributes, false);
    }

    /** @param array<string, mixed> $attributes */
    public static function coreValidator(array $attributes): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($attributes, self::coreRules());
    }

    /** @return array<string, list<string>> */
    public static function coreRules(): array
    {
        return ['name' => ['required', 'string', 'max:255'],
            'tax_identification_number' => ['required', 'string', 'regex:/\A[A-Z0-9]{9,32}\z/'],
            'country_code' => ['required', 'string', 'regex:/\A[A-Z]{2}\z/']];
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
