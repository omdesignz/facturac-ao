<?php

namespace App\Http\Requests;

use App\Fiscal\Pos\PosMoney;
use App\Models\PosSession;
use App\Models\Workspace;
use App\PosCashMovementType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePosCashMovementRequest extends FormRequest
{
    /** The most one drawer movement may carry: 100 000 000,00. */
    public const int MAX_AMOUNT_MINOR = 10_000_000_000;

    public function authorize(): bool
    {
        $session = $this->route('posSession');

        if (! $session instanceof PosSession) {
            return false;
        }

        // Another company's shift does not exist as far as this one knows.
        $workspace = $this->attributes->get('currentWorkspace');
        abort_unless($workspace instanceof Workspace && $session->workspace_id === $workspace->id, 404);

        return $this->user()?->can('recordCashMovement', $session) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(PosCashMovementType::class)],
            'amount' => [
                'required',
                'string',
                'max:18',
                'regex:'.PosMoney::PATTERN,
                'not_in:0,0.0,0.00',
                // A till drawer never moves this much at once; a barcode read
                // into the field does.
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && PosMoney::toMinor($value) > self::MAX_AMOUNT_MINOR) {
                        $fail('Valor demasiado alto para um movimento de caixa. Confirme-o.');
                    }
                },
            ],
            'reason' => ['required', 'string', 'min:3', 'max:160'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'Indique o valor em kwanzas, com até duas casas decimais.',
            'amount.not_in' => 'O valor tem de ser superior a zero.',
            'reason.required' => 'Diga para que serve este movimento.',
            'reason.min' => 'Diga para que serve este movimento.',
        ];
    }

    public function movementType(): PosCashMovementType
    {
        return PosCashMovementType::from((string) $this->validated('type'));
    }

    public function amountMinor(): int
    {
        return PosMoney::toMinor((string) $this->validated('amount'));
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'amount' => PosMoney::normalise($this->input('amount')),
            'reason' => is_string($this->input('reason')) ? trim($this->input('reason')) : $this->input('reason'),
        ]);
    }
}
