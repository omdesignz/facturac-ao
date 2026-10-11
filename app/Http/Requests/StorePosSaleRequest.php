<?php

namespace App\Http\Requests;

use App\Actions\CompletePosSale;
use App\Fiscal\Pos\PosMoney;
use App\Fiscal\Pos\PosPaymentMethods;
use App\Models\FiscalDocument;
use App\PaymentMethod;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * @phpstan-import-type Sale from CompletePosSale
 */
class StorePosSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FiscalDocument::class) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * Made by the till for each sale and kept across retries, so a
             * request sent twice is one sale. Long enough to be unguessable
             * by accident.
             */
            'client_key' => ['required', 'string', 'min:16', 'max:64', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'customer_public_id' => ['nullable', 'string', 'max:40'],
            'customer' => ['nullable', 'array:name,tax_identification_number'],
            'customer.name' => ['required_with:customer', 'string', 'min:2', 'max:200'],
            'customer.tax_identification_number' => [
                'required_with:customer',
                'string',
                'regex:/\A[A-Z0-9]{9,32}\z/',
            ],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['required', 'array:catalogue_item_public_id,quantity,discount_percentage'],
            'lines.*.catalogue_item_public_id' => ['required', 'string', 'max:40'],
            'lines.*.quantity' => [
                'required',
                'string',
                'max:20',
                'regex:/\A(?:0|[1-9]\d*)(?:\.\d{1,4})?\z/',
                'not_in:0,0.0,0.00,0.000,0.0000',
            ],
            'lines.*.discount_percentage' => [
                'required',
                'string',
                'max:6',
                'regex:/\A(?:100(?:\.0{1,2})?|(?:0|[1-9]\d?)(?:\.\d{1,2})?)\z/',
            ],
            'payment_method' => ['required', Rule::in(PosPaymentMethods::values())],
            'tendered_minor' => ['nullable', 'integer', 'min:0', 'max:999999999999999'],
            'expected_total_minor' => ['required', 'integer', 'min:1', 'max:999999999999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Adicione pelo menos um artigo à venda.',
            'lines.min' => 'Adicione pelo menos um artigo à venda.',
            'lines.max' => 'Uma venda não pode ter mais de 200 linhas.',
            'lines.*.quantity.regex' => 'A quantidade usa até quatro casas decimais.',
            'lines.*.quantity.not_in' => 'A quantidade tem de ser superior a zero.',
            'lines.*.discount_percentage.regex' => 'O desconto está entre 0 e 100, com até duas casas decimais.',
            'customer.tax_identification_number.regex' => 'O NIF usa 9 a 32 letras ou números.',
            'client_key.regex' => 'A chave da venda só pode ter letras, números, hífen e traço inferior.',
        ];
    }

    /**
     * The validated request in the shape the action takes.
     *
     * @return Sale
     */
    public function sale(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();
        $customer = $validated['customer'] ?? null;
        $lines = [];

        foreach ((array) $validated['lines'] as $line) {
            $lines[] = [
                'catalogue_item_public_id' => (string) $line['catalogue_item_public_id'],
                'quantity' => (string) $line['quantity'],
                'discount_percentage' => (string) $line['discount_percentage'],
            ];
        }

        return [
            'client_key' => (string) $validated['client_key'],
            'customer_public_id' => $this->nullableString($validated['customer_public_id'] ?? null),
            'customer' => is_array($customer)
                ? [
                    'name' => (string) $customer['name'],
                    'tax_identification_number' => (string) $customer['tax_identification_number'],
                ]
                : null,
            'lines' => $lines,
            'payment_method' => PaymentMethod::from((string) $validated['payment_method']),
            'tendered_minor' => isset($validated['tendered_minor']) ? (int) $validated['tendered_minor'] : null,
            'expected_total_minor' => (int) $validated['expected_total_minor'],
        ];
    }

    /**
     * The till reads JSON whatever headers it sent, so a refusal never comes
     * back as a redirect to a page it is not looking at.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors()->messages(),
        ], 422));
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Não tem permissão para vender.',
        ], 403));
    }

    protected function prepareForValidation(): void
    {
        $customer = $this->input('customer');
        $lines = $this->input('lines');

        if (is_array($customer)) {
            $customer['name'] = $this->trimmed($customer['name'] ?? null);
            $nif = $customer['tax_identification_number'] ?? null;
            $customer['tax_identification_number'] = is_string($nif) ? mb_strtoupper(trim($nif)) : $nif;

            // A customer block with nothing in it is "no customer", not a
            // customer with an empty name.
            if (($customer['name'] ?? '') === '' && ($customer['tax_identification_number'] ?? '') === '') {
                $customer = null;
            }
        }

        if (is_array($lines)) {
            $lines = array_map(function (mixed $line): mixed {
                if (! is_array($line)) {
                    return $line;
                }

                $line['quantity'] = PosMoney::normalise($line['quantity'] ?? null);
                $line['discount_percentage'] = PosMoney::normalise($line['discount_percentage'] ?? '0');

                return $line;
            }, $lines);
        }

        $this->merge([
            'customer_public_id' => $this->nullableString($this->input('customer_public_id')),
            'customer' => $customer,
            'lines' => $lines,
            'payment_method' => is_string($this->input('payment_method'))
                ? mb_strtoupper(trim($this->input('payment_method')))
                : $this->input('payment_method'),
        ]);
    }

    private function trimmed(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
