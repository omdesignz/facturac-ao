<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One or many prices on a tabela.
 *
 * Accepts a batch because that is how the screen is used: someone sets a
 * wholesale list by going down the catalogue, and saving each row on its own
 * would make a half-entered tabela the normal state.
 */
class StorePriceListItemRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'prices' => ['required', 'array', 'min:1', 'max:500'],
            'prices.*.catalogue_item' => ['required', 'string'],
            'prices.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'prices.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The submitted prices, with the figure already in minor units.
     *
     * A blank price is kept rather than dropped: it means "this article is not
     * on the tabela", which the controller turns into a removal.
     *
     * @return list<array{catalogue_item: string, unit_price_minor: int|null, note: string|null}>
     */
    public function prices(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->validated('prices');

        return array_map(fn (array $row): array => [
            'catalogue_item' => (string) $row['catalogue_item'],
            'unit_price_minor' => $this->toMinor($row['unit_price'] ?? null),
            'note' => $row['note'] === null ? null : (string) $row['note'],
        ], $rows);
    }

    private function toMinor(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', trim((string) $value), 2), 2, '');

        return (int) ($whole.substr(str_pad($fraction, 2, '0'), 0, 2));
    }
}
