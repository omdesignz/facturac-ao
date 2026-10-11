<?php

namespace App\Http\Requests;

use App\Models\SubscriptionPackage;
use App\SubscriptionInterval;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates or updates a subscription package. Support staff only; the route
 * group already answers 404 to anyone else.
 */
class StoreSubscriptionPackageRequest extends FormRequest
{
    /** The largest price accepted for one period, in minor units: 9 999 999 999,99. */
    public const MAXIMUM_AMOUNT_MINOR = 999_999_999_999;

    public function authorize(): bool
    {
        return $this->user()?->isSupportStaff() === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'summary' => ['nullable', 'string', 'max:500'],
            'features' => ['nullable', 'array', 'max:20'],
            'features.*' => ['required', 'string', 'min:2', 'max:160', 'distinct:ignore_case'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:90'],
            'currency_code' => ['required', 'string', Rule::in((array) config('fiscal.currencies', ['AOA']))],
            'is_active' => ['required', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'periods' => ['required', 'array', 'min:1', 'max:4'],
            'periods.*.interval' => ['required', Rule::enum(SubscriptionInterval::class), 'distinct'],
            'periods.*.amount' => [
                'bail',
                'required',
                'string',
                'max:16',
                'regex:/\A(?:0|[1-9]\d*)(?:\.\d{1,2})?\z/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $minor = self::minorUnits((string) $value);

                    if ($minor <= 0 || $minor > self::MAXIMUM_AMOUNT_MINOR) {
                        $fail('O preço tem de ser superior a zero e não exceder 9 999 999 999,99.');
                    }
                },
            ],
            'periods.*.is_active' => ['required', 'boolean'],
        ];

        if (! $this->route('package') instanceof SubscriptionPackage) {
            $rules['code'] = [
                'required',
                'string',
                'max:48',
                'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                Rule::unique('subscription_packages', 'code'),
            ];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => 'Indique o código do pacote.',
            'code.regex' => 'Use apenas letras minúsculas, algarismos e hífenes, sem hífen no início nem no fim.',
            'code.max' => 'O código não pode ter mais de 48 caracteres.',
            'code.unique' => 'Já existe um pacote com este código.',
            'name.required' => 'Indique o nome do pacote.',
            'name.min' => 'O nome precisa de pelo menos 2 caracteres.',
            'name.max' => 'O nome não pode ter mais de 80 caracteres.',
            'summary.max' => 'O resumo não pode ter mais de 500 caracteres.',
            'features.max' => 'Um pacote pode ter no máximo 20 funcionalidades.',
            'features.*.min' => 'Cada funcionalidade precisa de pelo menos 2 caracteres.',
            'features.*.max' => 'Cada funcionalidade não pode ter mais de 160 caracteres.',
            'features.*.distinct' => 'Esta funcionalidade está repetida.',
            'trial_days.required' => 'Indique os dias de teste, ou 0 para nenhum.',
            'trial_days.integer' => 'Os dias de teste têm de ser um número inteiro.',
            'trial_days.min' => 'Os dias de teste não podem ser negativos.',
            'trial_days.max' => 'Os dias de teste não podem passar de 90.',
            'currency_code.in' => 'Escolha uma moeda da lista.',
            'sort_order.integer' => 'A ordem tem de ser um número inteiro.',
            'sort_order.min' => 'A ordem não pode ser negativa.',
            'sort_order.max' => 'A ordem não pode passar de 999.',
            'periods.required' => 'Escolha pelo menos um período de pagamento.',
            'periods.min' => 'Escolha pelo menos um período de pagamento.',
            'periods.max' => 'Um pacote tem no máximo quatro períodos de pagamento.',
            'periods.*.interval.required' => 'Indique o período de pagamento.',
            'periods.*.interval.enum' => 'Escolha um período de pagamento da lista.',
            'periods.*.interval.distinct' => 'Cada período de pagamento só pode aparecer uma vez.',
            'periods.*.amount.required' => 'Indique o preço do período.',
            'periods.*.amount.regex' => 'Use um valor como 12500 ou 12500.50, com no máximo duas casas decimais.',
            'periods.*.amount.max' => 'O preço não pode exceder 9 999 999 999,99.',
        ];
    }

    /** Trims and drops blank features before the length rules see them. */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_array($this->input('features'))) {
            $merge['features'] = array_values(array_filter(
                array_map(
                    fn (mixed $feature): mixed => is_string($feature) ? trim($feature) : $feature,
                    $this->input('features'),
                ),
                fn (mixed $feature): bool => $feature !== '' && $feature !== null,
            ));
        }

        if (is_array($this->input('periods'))) {
            $merge['periods'] = array_map(
                function (mixed $period): mixed {
                    if (is_array($period) && (is_int($period['amount'] ?? null) || is_float($period['amount'] ?? null))) {
                        $period['amount'] = (string) $period['amount'];
                    }

                    return $period;
                },
                $this->input('periods'),
            );
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * The validated input in the shape SaveSubscriptionPackage expects: prices
     * in minor units, intervals as enum cases, `code` only when creating.
     *
     * @return array{
     *     code?: string,
     *     name: string,
     *     summary: string|null,
     *     features: list<string>,
     *     trial_days: int,
     *     currency_code: string,
     *     is_active: bool,
     *     is_featured: bool,
     *     sort_order: int,
     *     periods: list<array{interval: SubscriptionInterval, amount_minor: int, is_active: bool}>
     * }
     */
    public function packageData(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        $data = [
            'name' => (string) $validated['name'],
            'summary' => isset($validated['summary']) ? (string) $validated['summary'] : null,
            'features' => array_values(array_map('strval', (array) ($validated['features'] ?? []))),
            'trial_days' => (int) $validated['trial_days'],
            'currency_code' => (string) $validated['currency_code'],
            'is_active' => (bool) $validated['is_active'],
            'is_featured' => (bool) ($validated['is_featured'] ?? false),
            'sort_order' => (int) $validated['sort_order'],
            'periods' => array_values(array_map(
                fn (array $period): array => [
                    'interval' => SubscriptionInterval::from((string) $period['interval']),
                    'amount_minor' => self::minorUnits((string) $period['amount']),
                    'is_active' => (bool) $period['is_active'],
                ],
                (array) $validated['periods'],
            )),
        ];

        if (isset($validated['code'])) {
            $data['code'] = (string) $validated['code'];
        }

        return $data;
    }

    /** "12500.5" becomes 1250050, without passing through a float. */
    public static function minorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        if (strlen($whole) > 12) {
            return PHP_INT_MAX;
        }

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
