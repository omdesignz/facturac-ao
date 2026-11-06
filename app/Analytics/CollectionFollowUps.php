<?php

namespace App\Analytics;

use App\Models\Customer;
use App\Models\LegalEntity;

/**
 * Internal team work, independent of whether a balance has since been settled.
 *
 * @phpstan-type FollowUp array{revision: int, next_contact_on: string|null, promised_payment_on: string|null, note: string|null, completed: bool, next_action_on: string|null, is_due: bool, updated_at: string|null, updated_by: string|null}
 */
final class CollectionFollowUps
{
    /** @return FollowUp */
    public function forCustomer(Customer $customer): array
    {
        $dates = array_filter([
            $customer->collection_next_contact_on?->toDateString(),
            $customer->collection_promised_payment_on?->toDateString(),
        ]);
        $next = $dates === [] ? null : min($dates);

        return [
            'revision' => $customer->collection_revision,
            'next_contact_on' => $customer->collection_next_contact_on?->toDateString(),
            'promised_payment_on' => $customer->collection_promised_payment_on?->toDateString(),
            'note' => $customer->collection_note,
            'completed' => $customer->collection_completed_at !== null,
            'next_action_on' => $next,
            'is_due' => $customer->collection_completed_at === null && $next !== null && $next <= now('Africa/Luanda')->toDateString(),
            'updated_at' => $customer->collection_updated_at?->toIso8601String(),
            'updated_by' => $customer->collectionUpdater?->name,
        ];
    }

    /** @return list<array{customer_public_id: string, name: string, follow_up: FollowUp}> */
    public function forCompany(LegalEntity $entity): array
    {
        return array_values($entity->customers()->where('workspace_id', $entity->workspace_id)
            ->whereNull('collection_completed_at')
            ->where(fn ($query) => $query->whereNotNull('collection_next_contact_on')->orWhereNotNull('collection_promised_payment_on')->orWhereNotNull('collection_note'))
            ->with('collectionUpdater:id,name')->get()
            ->map(fn (Customer $customer): array => [
                'customer_public_id' => $customer->public_id,
                'name' => $customer->name,
                'follow_up' => $this->forCustomer($customer),
            ])
            ->sortBy(fn (array $row): string => ($row['follow_up']['next_action_on'] ?? '9999-12-31').'|'.$row['name'].'|'.$row['customer_public_id'])
            ->values()->all());
    }
}
