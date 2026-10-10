<?php

namespace App\Fiscal;

use App\CatalogueItemType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class CatalogueListCommand
{
    private function __construct(public int $page, public int $perPage, public string $status, public ?string $search, public ?CatalogueItemType $type, public ?string $code) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        if (isset($input['q']) && is_string($input['q'])) {
            $input['q'] = trim($input['q'], ' ');
        }
        $v = Validator::make(['filters' => $input], self::rules())->validate()['filters'];

        return new self((int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 25), $v['status'] ?? 'active', $v['q'] ?? null, isset($v['type']) ? CatalogueItemType::from($v['type']) : null, $v['code'] ?? null);
    }

    /** @return array<string, array<mixed>> */
    public static function rules(): array
    {
        return [
            'filters' => ['array:page,per_page,status,q,type,code'],
            'filters.page' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
            'filters.per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:50'],
            'filters.status' => ['sometimes', 'required', Rule::in(['active', 'inactive', 'all'])],
            'filters.q' => ['sometimes', 'required', 'string', 'min:2', 'max:80',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}]/u', $value) !== 0) {
                        $fail('Verifique os parâmetros da consulta.');
                    }
                }],
            'filters.type' => ['sometimes', 'required', Rule::enum(CatalogueItemType::class)],
            'filters.code' => ['sometimes', 'required', 'string', 'max:60'],
        ];
    }
}
