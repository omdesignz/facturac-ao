<?php

namespace App\Http\Requests;

use App\FiscalDocumentType;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class IndexFiscalDocumentsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', FiscalDocument::class) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $legalEntity = $this->currentLegalEntity();

        return [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', 'draft', 'active', 'valid', 'attention'])],
            'family' => ['nullable', Rule::in(['all', 'invoice', 'receipt', 'adjustment'])],
            'type' => [
                'nullable',
                Rule::in(array_map(
                    fn (FiscalDocumentType $type): string => $type->value,
                    FiscalDocumentType::issuable(),
                )),
            ],
            'establishment' => [
                'nullable',
                'string',
                Rule::exists('establishments', 'public_id')->where(
                    fn ($query) => $query
                        ->where('workspace_id', $legalEntity->workspace_id ?? 0)
                        ->where('legal_entity_id', $legalEntity->id ?? 0),
                ),
            ],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array{
     *     q: string,
     *     status: string,
     *     family: string,
     *     type: string,
     *     establishment: string,
     *     from: string,
     *     to: string
     * }
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $type = (string) ($validated['type'] ?? '');
        $family = (string) ($validated['family'] ?? 'all');

        if ($type !== '') {
            $documentType = FiscalDocumentType::from($type);
            $family = match (true) {
                $documentType->isReceipt() => 'receipt',
                $documentType->isAdjustment() => 'adjustment',
                default => 'invoice',
            };
        }

        return [
            'q' => Str::of((string) ($validated['q'] ?? ''))->squish()->toString(),
            'status' => (string) ($validated['status'] ?? 'all'),
            'family' => $family,
            'type' => $type,
            'establishment' => (string) ($validated['establishment'] ?? ''),
            'from' => (string) ($validated['from'] ?? ''),
            'to' => (string) ($validated['to'] ?? ''),
        ];
    }

    public function currentLegalEntity(): ?LegalEntity
    {
        $workspace = $this->attributes->get('currentWorkspace');

        return $workspace instanceof Workspace
            ? $workspace->legalEntities()->oldest('id')->first()
            : null;
    }
}
