<?php

namespace App\Fiscal;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class CustomerListCommand
{
    private function __construct(public int $page, public int $perPage, public string $status, public ?string $search) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        if (isset($input['q']) && is_string($input['q'])) {
            $input['q'] = trim($input['q'], ' ');
        }
        $v = Validator::make(['filters' => $input], self::rules())->validate()['filters'];

        return new self((int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 25), $v['status'] ?? 'active', $v['q'] ?? null);
    }

    /** @return array<string, array<mixed>> */
    public static function rules(): array
    {
        return [
            'filters' => ['array:page,per_page,status,q'],
            'filters.page' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
            'filters.per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:50'],
            'filters.status' => ['sometimes', 'required', Rule::in(['active', 'inactive', 'all'])],
            'filters.q' => ['sometimes', 'required', 'string', 'min:2', 'max:80',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}]/u', $value) !== 0) {
                        $fail('Verifique os parâmetros da consulta.');
                    }
                }],
        ];
    }
}
