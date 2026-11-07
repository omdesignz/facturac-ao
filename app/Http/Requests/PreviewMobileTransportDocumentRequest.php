<?php

namespace App\Http\Requests;

use App\Actions\CreateMobileTransportDocument;
use App\TransportDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A transport guide as the phone may describe it: which kind, from where to
 * where, when the goods are loaded and unloaded, the vehicle, and which items in
 * what quantity. Codes, descriptions, units and values are the server's to read
 * from the catalogue.
 */
class PreviewMobileTransportDocumentRequest extends FormRequest
{
    /**
     * Only the kinds the AGT's SAF-T schema accepts today; GC waits for it.
     */
    public const array ISSUABLE_TYPES = [
        TransportDocumentType::DeliveryNote,
        TransportDocumentType::TransportGuide,
        TransportDocumentType::OwnAssets,
        TransportDocumentType::ReturnNote,
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::enum(TransportDocumentType::class)->only(self::ISSUABLE_TYPES)],
            'establishment_public_id' => ['required', 'string', 'max:40'],
            'customer_public_id' => [
                'nullable',
                'string',
                'max:40',
                'prohibited_if:document_type,'.TransportDocumentType::ReturnNote->value,
            ],
            'recipient' => ['nullable', 'array:name,tax_identification_number'],
            'recipient.name' => ['required_without:customer_public_id', 'nullable', 'string', 'max:200'],
            'recipient.tax_identification_number' => ['required_without:customer_public_id', 'nullable', 'string', 'max:32'],
            'origin' => ['required', 'array:address,city'],
            'origin.address' => ['required', 'string', 'max:250'],
            'origin.city' => ['required', 'string', 'max:50'],
            'destination' => ['required', 'array:address,city'],
            'destination.address' => ['required', 'string', 'max:250'],
            'destination.city' => ['required', 'string', 'max:50'],
            'movement_start_at' => ['required', 'date_format:Y-m-d H:i'],
            'movement_end_at' => ['nullable', 'date_format:Y-m-d H:i', 'after:movement_start_at'],
            'vehicle_registration' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['required', 'array:catalogue_item_public_id,quantity'],
            'lines.*.catalogue_item_public_id' => ['required', 'string', 'max:40'],
            'lines.*.quantity' => [
                'required',
                'string',
                'max:20',
                'regex:/\A(?:0|[1-9]\d{0,8})(?:\.\d{1,3})?\z/',
                'not_in:0,0.0,0.00,0.000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document_type.enum' => 'Escolha uma guia de remessa, de transporte, de activos próprios ou de devolução.',
            'customer_public_id.prohibited_if' => 'As guias de devolução identificam um fornecedor, não um cliente guardado.',
            'recipient.name.required_without' => 'Indique quem recebe a mercadoria.',
            'recipient.tax_identification_number.required_without' => 'Indique o NIF de quem recebe.',
            'origin.address.required' => 'Indique o local de carga.',
            'origin.city.required' => 'Indique a cidade de carga.',
            'destination.address.required' => 'Indique o local de descarga.',
            'destination.city.required' => 'Indique a cidade de descarga.',
            'movement_end_at.after' => 'A descarga tem de ser depois da carga.',
            'lines.required' => 'Adicione pelo menos um artigo à guia.',
            'lines.min' => 'Adicione pelo menos um artigo à guia.',
            'lines.max' => 'Uma guia não pode ter mais de 200 linhas.',
            'lines.*.quantity.regex' => 'A quantidade usa até três casas decimais.',
            'lines.*.quantity.not_in' => 'A quantidade tem de ser superior a zero.',
        ];
    }

    /**
     * The guide in the shape {@see CreateMobileTransportDocument} reads.
     *
     * @return array{
     *     document_type: TransportDocumentType,
     *     establishment_public_id: string,
     *     customer_public_id: string|null,
     *     recipient: array{name: string|null, tax_identification_number: string|null},
     *     origin: array{address: string, city: string},
     *     destination: array{address: string, city: string},
     *     movement_start_at: string,
     *     movement_end_at: string|null,
     *     vehicle_registration: string|null,
     *     notes: string|null,
     *     lines: list<array{catalogue_item_public_id: string, quantity: string}>
     * }
     */
    public function guide(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        /** @var list<array{catalogue_item_public_id: string, quantity: string}> $lines */
        $lines = array_values((array) $validated['lines']);

        return [
            'document_type' => TransportDocumentType::from((string) $validated['document_type']),
            'establishment_public_id' => (string) $validated['establishment_public_id'],
            'customer_public_id' => $this->filled('customer_public_id') ? (string) $validated['customer_public_id'] : null,
            'recipient' => [
                'name' => $this->trimmed($validated['recipient']['name'] ?? null),
                'tax_identification_number' => $this->trimmed($validated['recipient']['tax_identification_number'] ?? null),
            ],
            'origin' => [
                'address' => trim((string) $validated['origin']['address']),
                'city' => trim((string) $validated['origin']['city']),
            ],
            'destination' => [
                'address' => trim((string) $validated['destination']['address']),
                'city' => trim((string) $validated['destination']['city']),
            ],
            'movement_start_at' => (string) $validated['movement_start_at'],
            'movement_end_at' => $this->trimmed($validated['movement_end_at'] ?? null),
            'vehicle_registration' => $this->trimmed($validated['vehicle_registration'] ?? null),
            'notes' => $this->trimmed($validated['notes'] ?? null),
            'lines' => $lines,
        ];
    }

    private function trimmed(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }
}
