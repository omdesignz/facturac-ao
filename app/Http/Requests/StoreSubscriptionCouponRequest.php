<?php

namespace App\Http\Requests;

use App\Models\SubscriptionCoupon;
use App\SubscriptionCouponDiscountType;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates or updates a discount code for the subscription plans. Support staff
 * only. Once created, a code's text and discount never change, so every charge
 * it was applied to keeps meaning what it meant.
 */
class StoreSubscriptionCouponRequest extends FormRequest
{
    /** The most uses a single code may allow. */
    public const MAXIMUM_REDEMPTIONS = 100_000;

    public function authorize(): bool
    {
        return $this->user()?->isSupportStaff() === true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => SubscriptionCoupon::normaliseCode((string) $this->input('code'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $coupon = $this->route('coupon');
        $usedCount = $coupon instanceof SubscriptionCoupon ? $coupon->heldRedemptions()->count() : 0;
        $today = CarbonImmutable::now('Africa/Luanda')->toDateString();

        $rules = [
            'description' => ['nullable', 'string', 'max:160'],
            'max_redemptions' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.self::MAXIMUM_REDEMPTIONS,
                function (string $attribute, mixed $value, Closure $fail) use ($usedCount): void {
                    if ($value !== null && (int) $value < $usedCount) {
                        $fail("O limite não pode ficar abaixo das {$usedCount} utilizações já feitas.");
                    }
                },
            ],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'is_active' => ['required', 'boolean'],
        ];

        if ($coupon instanceof SubscriptionCoupon) {
            return $rules;
        }

        return [
            ...$rules,
            'code' => [
                'required',
                'string',
                'regex:/\A[A-Z0-9][A-Z0-9-]{2,31}\z/',
                Rule::unique('subscription_coupons', 'code'),
            ],
            'discount_type' => ['required', Rule::enum(SubscriptionCouponDiscountType::class)],
            'percent_off' => [
                'exclude_unless:discount_type,'.SubscriptionCouponDiscountType::Percentage->value,
                'required',
                'integer',
                'min:1',
                'max:99',
            ],
            'amount_off' => [
                'exclude_unless:discount_type,'.SubscriptionCouponDiscountType::Fixed->value,
                'bail',
                'required',
                'string',
                'max:16',
                'regex:/\A(?:0|[1-9]\d*)(?:\.\d{1,2})?\z/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $minor = StoreSubscriptionPackageRequest::minorUnits((string) $value);

                    if ($minor <= 0 || $minor > StoreSubscriptionPackageRequest::MAXIMUM_AMOUNT_MINOR) {
                        $fail('O desconto tem de ser superior a zero e não exceder 9 999 999 999,99.');
                    }
                },
            ],
            'free_days' => [
                'exclude_unless:discount_type,'.SubscriptionCouponDiscountType::FreeDays->value,
                'required',
                'integer',
                'min:1',
                'max:'.SubscriptionCoupon::maximumFreeDays(),
            ],
            'currency_code' => ['required', 'string', Rule::in((array) config('fiscal.currencies', ['AOA']))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => 'Escreva o código.',
            'code.regex' => 'Use 3 a 32 letras, algarismos ou hífenes, a começar por letra ou algarismo.',
            'code.unique' => 'Já existe um código igual.',
            'percent_off.required' => 'Indique a percentagem de desconto.',
            'percent_off.min' => 'A percentagem vai de 1 a 99.',
            'percent_off.max' => 'A percentagem vai de 1 a 99.',
            'amount_off.required' => 'Indique o valor do desconto.',
            'amount_off.regex' => 'Escreva o valor com até duas casas decimais.',
            'free_days.required' => 'Indique quantos dias grátis o código dá.',
            'free_days.min' => 'O código tem de dar pelo menos 1 dia grátis.',
            'free_days.max' => 'O código pode dar até '.SubscriptionCoupon::maximumFreeDays().' dias grátis.',
            'max_redemptions.min' => 'O limite tem de ser pelo menos 1.',
            'expires_on.after_or_equal' => 'A validade não pode terminar antes de hoje.',
        ];
    }

    /**
     * The validated values as the model stores them: amounts in minor units,
     * the validity as the end of the chosen day in Luanda.
     *
     * @return array<string, mixed>
     */
    public function couponData(): array
    {
        $validated = $this->validated();
        $expiresOn = $validated['expires_on'] ?? null;

        $data = [
            'description' => filled($validated['description'] ?? null) ? trim((string) $validated['description']) : null,
            'max_redemptions' => isset($validated['max_redemptions']) ? (int) $validated['max_redemptions'] : null,
            'expires_at' => $expiresOn === null
                ? null
                : CarbonImmutable::createFromFormat('Y-m-d', (string) $expiresOn, 'Africa/Luanda')->endOfDay(),
            'is_active' => (bool) $validated['is_active'],
        ];

        if (! isset($validated['code'])) {
            return $data;
        }

        $type = SubscriptionCouponDiscountType::from((string) $validated['discount_type']);

        return [
            ...$data,
            'code' => (string) $validated['code'],
            'discount_type' => $type,
            'percent_off' => $type === SubscriptionCouponDiscountType::Percentage ? (int) $validated['percent_off'] : null,
            'amount_off_minor' => $type === SubscriptionCouponDiscountType::Fixed
                ? StoreSubscriptionPackageRequest::minorUnits((string) $validated['amount_off'])
                : null,
            'free_days' => $type === SubscriptionCouponDiscountType::FreeDays ? (int) $validated['free_days'] : null,
            'currency_code' => (string) $validated['currency_code'],
        ];
    }
}
