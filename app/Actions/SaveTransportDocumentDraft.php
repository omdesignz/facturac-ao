<?php

namespace App\Actions;

use App\Exceptions\BillingActionRefused;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\TransportDocument;
use App\Models\User;
use App\TransportDocumentStatus;
use App\TransportDocumentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class SaveTransportDocumentDraft
{
    /**
     * @param  array<string, mixed>  $profile
     */
    public function execute(
        LegalEntity $legalEntity,
        User $user,
        array $profile,
        ?TransportDocument $draft = null,
    ): TransportDocument {
        return DB::transaction(function () use ($legalEntity, $user, $profile, $draft): TransportDocument {
            $document = $draft instanceof TransportDocument
                ? $this->lockedDraft($draft, $legalEntity)
                : new TransportDocument;
            $isNew = ! $document->exists;
            $establishment = $this->establishment($legalEntity, $profile);
            $documentType = TransportDocumentType::from((string) $profile['document_type']);
            $customer = $this->customer($legalEntity, $profile);

            if ($documentType === TransportDocumentType::ReturnNote && $customer instanceof Customer) {
                throw BillingActionRefused::because(
                    'As guias de devolução devem identificar um fornecedor, não um cliente guardado.',
                );
            }

            $movementStart = CarbonImmutable::parse(
                (string) $profile['movement_start_at'],
                $establishment->timezone,
            );
            $movementEnd = filled($profile['movement_end_at'] ?? null)
                ? CarbonImmutable::parse((string) $profile['movement_end_at'], $establishment->timezone)
                : null;

            if ($movementStart->toDateString() !== (string) $profile['movement_date']) {
                throw BillingActionRefused::because(
                    'A data do movimento tem de coincidir com a data de início do transporte.',
                );
            }

            $document->fill([
                'workspace_id' => $legalEntity->workspace_id,
                'legal_entity_id' => $legalEntity->id,
                'establishment_id' => $establishment->id,
                'customer_id' => $customer?->id,
                'created_by_user_id' => $document->created_by_user_id ?? $user->id,
                'updated_by_user_id' => $user->id,
                'document_type' => $documentType,
                'status' => TransportDocumentStatus::Draft,
                'revision' => $isNew ? 1 : $document->revision + 1,
                'movement_date' => $profile['movement_date'],
                'movement_start_at' => $movementStart,
                'movement_end_at' => $movementEnd,
                'recipient_name' => $profile['recipient']['name'],
                'recipient_tax_identification_number' => $profile['recipient']['tax_identification_number'],
                'recipient_country_code' => $profile['recipient']['country_code'],
                'recipient_address' => $profile['recipient']['address'],
                'recipient_city' => $profile['recipient']['city'],
                'recipient_province' => $profile['recipient']['province'],
                'origin_address' => $profile['origin']['address'],
                'origin_city' => $profile['origin']['city'],
                'origin_province' => $profile['origin']['province'],
                'origin_country_code' => $profile['origin']['country_code'],
                'destination_address' => $profile['destination']['address'],
                'destination_city' => $profile['destination']['city'],
                'destination_province' => $profile['destination']['province'],
                'destination_country_code' => $profile['destination']['country_code'],
                'transporter_name' => $profile['transporter']['name'],
                'transporter_tax_identification_number' => $profile['transporter']['tax_identification_number'],
                'vehicle_registration' => $profile['transporter']['vehicle_registration'],
                'gross_weight_grams' => $profile['gross_weight_grams'],
                'package_count' => $profile['package_count'],
                'currency_code' => $legalEntity->currency_code,
                'notes' => $profile['notes'],
            ]);
            $document->save();

            $totals = $this->replaceLines($document, $legalEntity, $profile['lines']);
            $document->forceFill([
                'net_total_minor' => $totals,
                'tax_payable_minor' => 0,
                'gross_total_minor' => $totals,
            ])->save();

            activity('transport-document')
                ->causedBy($user)
                ->performedOn($document)
                ->event($isNew ? 'created' : 'updated')
                ->withProperties([
                    'document_public_id' => $document->public_id,
                    'document_type' => $document->document_type->value,
                    'revision' => $document->revision,
                ])
                ->log($isNew ? 'transport document created' : 'transport document updated');

            return $document->load(['lines.catalogueItem', 'establishment', 'customer']);
        }, 5);
    }

    private function lockedDraft(
        TransportDocument $draft,
        LegalEntity $legalEntity,
    ): TransportDocument {
        $locked = TransportDocument::query()
            ->whereKey($draft->id)
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->lockForUpdate()
            ->firstOrFail();
        $locked->ensureMutable();

        return $locked;
    }

    /** @param array<string, mixed> $profile */
    private function establishment(LegalEntity $legalEntity, array $profile): Establishment
    {
        return Establishment::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('public_id', $profile['establishment_public_id'])
            ->where('is_active', true)
            ->firstOrFail();
    }

    /** @param array<string, mixed> $profile */
    private function customer(LegalEntity $legalEntity, array $profile): ?Customer
    {
        if ($profile['customer_public_id'] === null) {
            return null;
        }

        return Customer::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('public_id', $profile['customer_public_id'])
            ->firstOrFail();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(
        TransportDocument $document,
        LegalEntity $legalEntity,
        array $lines,
    ): int {
        $document->lines()->delete();
        $catalogueItems = $this->catalogueItems($legalEntity, $lines);
        $total = 0;

        foreach (array_values($lines) as $index => $line) {
            $catalogueItem = $line['catalogue_item_public_id'] === null
                ? null
                : $catalogueItems->get($line['catalogue_item_public_id']);
            $amount = intdiv(
                (int) $line['quantity_units'] * (int) $line['unit_price_minor'],
                10 ** (int) $line['quantity_scale'],
            );

            $document->lines()->create([
                'workspace_id' => $document->workspace_id,
                'legal_entity_id' => $document->legal_entity_id,
                'catalogue_item_id' => $catalogueItem?->id,
                'line_number' => $index + 1,
                'product_code' => $line['product_code'],
                'product_description' => $line['product_description'],
                'quantity_units' => $line['quantity_units'],
                'quantity_scale' => $line['quantity_scale'],
                'unit_of_measure' => $line['unit_of_measure'],
                'unit_price_minor' => $line['unit_price_minor'],
                'net_amount_minor' => $amount,
            ]);

            $total += $amount;
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return Collection<string, CatalogueItem>
     */
    private function catalogueItems(LegalEntity $legalEntity, array $lines): Collection
    {
        $publicIds = collect($lines)
            ->pluck('catalogue_item_public_id')
            ->filter()
            ->unique()
            ->values();

        /** @var Collection<string, CatalogueItem> */
        return CatalogueItem::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->whereIn('public_id', $publicIds)
            ->get()
            ->keyBy('public_id');
    }
}
