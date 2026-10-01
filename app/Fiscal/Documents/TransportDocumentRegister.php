<?php

namespace App\Fiscal\Documents;

use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\TransportDocument;
use App\Models\User;
use App\TransportDocumentStatus;
use App\TransportDocumentType;
use Illuminate\Database\Eloquent\Builder;

final readonly class TransportDocumentRegister
{
    /**
     * @param  array{q: string, status: string, type: string, establishment: string, from: string, to: string}  $filters
     * @return array<string, mixed>
     */
    public function for(LegalEntity $legalEntity, array $filters, User $user): array
    {
        $base = TransportDocument::query()
            ->where('legal_entity_id', $legalEntity->id);
        $query = clone $base;

        $this->applyFilters($query, $filters);

        $documents = $query
            ->with(['establishment', 'customer'])
            ->latest('movement_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return [
            'documents' => [
                'data' => array_values($documents->getCollection()
                    ->map(fn (TransportDocument $document): array => $this->row($document, $user))
                    ->all()),
                'links' => $documents->linkCollection()->all(),
                'total' => $documents->total(),
                'from' => $documents->firstItem(),
                'to' => $documents->lastItem(),
            ],
            'summary' => [
                'total' => (clone $base)->count(),
                'draft' => (clone $base)->where('status', TransportDocumentStatus::Draft)->count(),
                'issued' => (clone $base)->where('status', TransportDocumentStatus::Issued)->count(),
                'cancelled' => (clone $base)->where('status', TransportDocumentStatus::Cancelled)->count(),
            ],
            'filters' => $filters,
            'options' => [
                'types' => array_map(fn (TransportDocumentType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ], TransportDocumentType::cases()),
                'establishments' => array_values($legalEntity->establishments()
                    ->orderByDesc('is_head_office')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Establishment $establishment): array => [
                        'value' => $establishment->public_id,
                        'label' => $establishment->name,
                    ])->all()),
            ],
            'permissions' => [
                'create' => $user->can('create', TransportDocument::class),
            ],
        ];
    }

    /**
     * @param  Builder<TransportDocument>  $query
     * @param  array{q: string, status: string, type: string, establishment: string, from: string, to: string}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']).'%';

                $query->where(function (Builder $query) use ($search): void {
                    $query->where('document_no', 'like', $search)
                        ->orWhere('recipient_name', 'like', $search)
                        ->orWhere('recipient_tax_identification_number', 'like', $search)
                        ->orWhere('vehicle_registration', 'like', $search);
                });
            })
            ->when(
                $filters['status'] !== 'all',
                fn (Builder $query) => $query->where('status', $filters['status']),
            )
            ->when(
                $filters['type'] !== '',
                fn (Builder $query) => $query->where('document_type', $filters['type']),
            )
            ->when($filters['establishment'] !== '', function (Builder $query) use ($filters): void {
                $query->whereHas('establishment', fn (Builder $query) => $query
                    ->where('public_id', $filters['establishment']));
            })
            ->when(
                $filters['from'] !== '',
                fn (Builder $query) => $query->whereDate('movement_date', '>=', $filters['from']),
            )
            ->when(
                $filters['to'] !== '',
                fn (Builder $query) => $query->whereDate('movement_date', '<=', $filters['to']),
            );
    }

    /** @return array<string, mixed> */
    private function row(TransportDocument $document, User $user): array
    {
        return [
            'public_id' => $document->public_id,
            'document_no' => $document->document_no,
            'document_type' => $document->document_type->value,
            'document_type_label' => $document->document_type->label(),
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'movement_date' => $document->movement_date->toDateString(),
            'movement_start_at' => $document->movement_start_at->toIso8601String(),
            'recipient_name' => $document->recipient_name,
            'recipient_tax_identification_number' => $document->recipient_tax_identification_number,
            'destination' => trim("{$document->destination_city}, {$document->destination_province}", ', '),
            'vehicle_registration' => $document->vehicle_registration,
            'establishment' => [
                'public_id' => $document->establishment->public_id,
                'name' => $document->establishment->name,
                'code' => $document->establishment->code,
            ],
            'gross_total_minor' => $document->gross_total_minor,
            'currency_code' => $document->currency_code,
            'can_edit' => $user->can('update', $document),
            'can_print' => $document->document_no !== null,
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];
    }
}
