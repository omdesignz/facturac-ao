<?php

namespace App\Http\Requests;

use App\Models\LegalEntity;
use App\Models\TransportDocument;
use App\Models\Workspace;
use App\TransportDocumentStatus;
use App\TransportDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class IndexTransportDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', TransportDocument::class) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $legalEntity = $this->currentLegalEntity();

        return [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', ...array_column(TransportDocumentStatus::cases(), 'value')])],
            'type' => ['nullable', Rule::enum(TransportDocumentType::class)],
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

    /** @return array{q: string, status: string, type: string, establishment: string, from: string, to: string} */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'q' => Str::of((string) ($validated['q'] ?? ''))->squish()->toString(),
            'status' => (string) ($validated['status'] ?? 'all'),
            'type' => (string) ($validated['type'] ?? ''),
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
