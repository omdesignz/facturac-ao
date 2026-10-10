<?php

namespace App\Http\Requests;

use App\Models\LegalEntity;
use App\Models\TransportDocument;
use App\Models\Workspace;
use App\TransportDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransportDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('transportDocument');

        return $document instanceof TransportDocument
            ? $this->user()?->can('update', $document) === true
            : $this->user()?->can('create', TransportDocument::class) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $legalEntity = $this->currentLegalEntity();
        $workspaceId = $legalEntity->workspace_id ?? 0;
        $legalEntityId = $legalEntity->id ?? 0;

        return [
            'document_type' => ['required', Rule::enum(TransportDocumentType::class)],
            'establishment_public_id' => [
                'required',
                'string',
                Rule::exists('establishments', 'public_id')->where(
                    fn ($query) => $query
                        ->where('workspace_id', $workspaceId)
                        ->where('legal_entity_id', $legalEntityId)
                        ->where('is_active', true),
                ),
            ],
            'customer_public_id' => [
                'nullable',
                'string',
                'prohibited_if:document_type,'.TransportDocumentType::ReturnNote->value,
                Rule::exists('customers', 'public_id')->where(
                    fn ($query) => $query
                        ->where('workspace_id', $workspaceId)
                        ->where('legal_entity_id', $legalEntityId),
                ),
            ],
            'movement_date' => ['required', 'date_format:Y-m-d'],
            'movement_start_at' => ['required', 'date'],
            'movement_end_at' => ['nullable', 'date', 'after:movement_start_at'],
            'recipient.name' => ['required', 'string', 'max:200'],
            'recipient.tax_identification_number' => ['required', 'string', 'max:32'],
            'recipient.country_code' => ['required', 'string', 'size:2'],
            'recipient.address' => ['required', 'string', 'max:250'],
            'recipient.city' => ['required', 'string', 'max:50'],
            'recipient.province' => ['nullable', 'string', 'max:50'],
            'origin.address' => ['required', 'string', 'max:250'],
            'origin.city' => ['required', 'string', 'max:50'],
            'origin.province' => ['nullable', 'string', 'max:50'],
            'origin.country_code' => ['required', 'string', 'size:2'],
            'destination.address' => ['required', 'string', 'max:250'],
            'destination.city' => ['required', 'string', 'max:50'],
            'destination.province' => ['nullable', 'string', 'max:50'],
            'destination.country_code' => ['required', 'string', 'size:2'],
            'transporter.name' => ['nullable', 'string', 'max:255'],
            'transporter.tax_identification_number' => ['nullable', 'string', 'max:32'],
            'transporter.vehicle_registration' => ['nullable', 'string', 'max:32'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'package_count' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.catalogue_item_public_id' => [
                'nullable',
                'string',
                Rule::exists('catalogue_items', 'public_id')->where(
                    fn ($query) => $query
                        ->where('workspace_id', $workspaceId)
                        ->where('legal_entity_id', $legalEntityId),
                ),
            ],
            'lines.*.product_code' => ['required', 'string', 'max:60'],
            'lines.*.product_description' => ['required', 'string', 'max:200'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'lines.*.unit_of_measure' => ['required', 'string', 'max:20'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.required' => 'A guia precisa de pelo menos uma linha.',
            'movement_end_at.after' => 'O fim do transporte tem de ser posterior ao início.',
            'recipient.tax_identification_number.required' => 'Indique o NIF do destinatário.',
            'customer_public_id.prohibited_if' => 'As guias de devolução identificam um fornecedor, não um cliente guardado.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return [
            'document_type' => (string) $validated['document_type'],
            'establishment_public_id' => (string) $validated['establishment_public_id'],
            'customer_public_id' => $this->nullableString($validated['customer_public_id'] ?? null),
            'movement_date' => (string) $validated['movement_date'],
            'movement_start_at' => (string) $validated['movement_start_at'],
            'movement_end_at' => $this->nullableString($validated['movement_end_at'] ?? null),
            'recipient' => [
                'name' => (string) $validated['recipient']['name'],
                'tax_identification_number' => (string) $validated['recipient']['tax_identification_number'],
                'country_code' => strtoupper((string) $validated['recipient']['country_code']),
                'address' => (string) $validated['recipient']['address'],
                'city' => (string) $validated['recipient']['city'],
                'province' => $this->nullableString($validated['recipient']['province'] ?? null),
            ],
            'origin' => $this->addressProfile($validated['origin']),
            'destination' => $this->addressProfile($validated['destination']),
            'transporter' => [
                'name' => $this->nullableString($validated['transporter']['name'] ?? null),
                'tax_identification_number' => $this->nullableString(
                    $validated['transporter']['tax_identification_number'] ?? null,
                ),
                'vehicle_registration' => $this->nullableString(
                    $validated['transporter']['vehicle_registration'] ?? null,
                ),
            ],
            'gross_weight_grams' => isset($validated['gross_weight_kg'])
                ? $this->scaled((string) $validated['gross_weight_kg'], 3)
                : null,
            'package_count' => isset($validated['package_count'])
                ? (int) $validated['package_count']
                : null,
            'notes' => $this->nullableString($validated['notes'] ?? null),
            'lines' => array_values(array_map(
                fn (array $line): array => [
                    'catalogue_item_public_id' => $this->nullableString(
                        $line['catalogue_item_public_id'] ?? null,
                    ),
                    'product_code' => (string) $line['product_code'],
                    'product_description' => (string) $line['product_description'],
                    'quantity_units' => $this->scaled((string) $line['quantity'], 3),
                    'quantity_scale' => 3,
                    'unit_of_measure' => (string) $line['unit_of_measure'],
                    'unit_price_minor' => $this->scaled((string) ($line['unit_price'] ?? '0'), 2),
                ],
                $validated['lines'],
            )),
        ];
    }

    public function currentLegalEntity(): ?LegalEntity
    {
        $workspace = $this->attributes->get('currentWorkspace');

        return $workspace instanceof Workspace
            ? $workspace->legalEntities()->oldest('id')->first()
            : null;
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array{address: string, city: string, province: string|null, country_code: string}
     */
    private function addressProfile(array $address): array
    {
        return [
            'address' => (string) $address['address'],
            'city' => (string) $address['city'],
            'province' => $this->nullableString($address['province'] ?? null),
            'country_code' => strtoupper((string) $address['country_code']),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function scaled(string $value, int $scale): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');

        return (int) ($whole.substr(str_pad($fraction, $scale, '0'), 0, $scale));
    }
}
