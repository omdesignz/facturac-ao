<?php

namespace App\Http\Requests;

use App\Fiscal\Pos\PosMoney;
use App\Models\LegalEntity;
use App\Models\PosSession;
use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePosSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PosSession::class) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $legalEntity = $this->currentLegalEntity();

        return [
            'pos_register_public_id' => [
                'required',
                'string',
                Rule::exists('pos_registers', 'public_id')->where(
                    fn ($query) => $query
                        ->where('workspace_id', $legalEntity->workspace_id ?? 0)
                        ->where('legal_entity_id', $legalEntity->id ?? 0),
                ),
            ],
            // The cash counted into the drawer; zero is a perfectly good float.
            'opening_float' => ['required', 'string', 'max:18', 'regex:'.PosMoney::PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pos_register_public_id.exists' => 'Escolha uma das caixas da empresa.',
            'opening_float.regex' => 'Indique o fundo de caixa em kwanzas, com até duas casas decimais.',
        ];
    }

    public function openingFloatMinor(): int
    {
        return PosMoney::toMinor((string) $this->validated('opening_float'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'opening_float' => PosMoney::normalise($this->input('opening_float')),
        ]);
    }

    private function currentLegalEntity(): ?LegalEntity
    {
        $workspace = $this->attributes->get('currentWorkspace');

        return $workspace instanceof Workspace
            ? $workspace->legalEntities()->oldest('id')->first()
            : null;
    }
}
