<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ConfirmsIssuer;
use App\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A payment as the phone describes it: what came in, how, and which invoices it
 * pays. Every balance is checked again by the server.
 */
class StoreMobilePaymentRequest extends FormRequest
{
    use ConfirmsIssuer;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'client_key' => ['required', 'uuid'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Luanda')->toDateString()],
            'settlements' => ['required', 'array', 'min:1', 'max:100'],
            'settlements.*' => ['required', 'array:document_public_id,amount_minor'],
            'settlements.*.document_public_id' => ['required', 'string', 'max:40', 'distinct'],
            'settlements.*.amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999999'],
            ...$this->issuerRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'Confirme a palavra-passe para emitir o recibo.',
            'payment_date.before_or_equal' => 'A data do pagamento não pode ser no futuro.',
            'settlements.required' => 'Escolha pelo menos uma factura.',
            'settlements.min' => 'Escolha pelo menos uma factura.',
        ];
    }

    /**
     * @return list<array{document_public_id: string, amount_minor: int}>
     */
    public function settlements(): array
    {
        return array_map(fn (array $settlement): array => [
            'document_public_id' => (string) $settlement['document_public_id'],
            'amount_minor' => (int) $settlement['amount_minor'],
        ], array_values((array) $this->validated('settlements')));
    }
}
