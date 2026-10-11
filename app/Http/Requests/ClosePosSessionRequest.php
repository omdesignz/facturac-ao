<?php

namespace App\Http\Requests;

use App\Fiscal\Pos\PosMoney;
use App\Models\PosSession;
use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;

class ClosePosSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $session = $this->route('posSession');

        if (! $session instanceof PosSession) {
            return false;
        }

        // Another company's shift does not exist as far as this one knows.
        $workspace = $this->attributes->get('currentWorkspace');
        abort_unless($workspace instanceof Workspace && $session->workspace_id === $workspace->id, 404);

        return $this->user()?->can('close', $session) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'counted_cash' => ['required', 'string', 'max:18', 'regex:'.PosMoney::PATTERN],
            'closing_notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'counted_cash.regex' => 'Indique o dinheiro contado em kwanzas, com até duas casas decimais.',
        ];
    }

    public function countedCashMinor(): int
    {
        return PosMoney::toMinor((string) $this->validated('counted_cash'));
    }

    public function notes(): ?string
    {
        $notes = $this->validated('closing_notes');

        return is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'counted_cash' => PosMoney::normalise($this->input('counted_cash')),
        ]);
    }
}
