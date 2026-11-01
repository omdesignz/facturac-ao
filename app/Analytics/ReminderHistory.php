<?php

namespace App\Analytics;

use App\Actions\PreparePaymentReminder;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/**
 * When each customer last received a reminder, read from the record every send
 * leaves behind.
 */
class ReminderHistory
{
    /** How long a reminded customer stays off Hoje, unless they owe more late than the reminder said. */
    public const int QUIET_DAYS = 7;

    /**
     * @param  list<string>  $customerPublicIds
     * @return array<string, string> ISO 8601 times, by customer public id
     */
    public function lastSentAt(array $customerPublicIds): array
    {
        $publicIds = Customer::query()->whereIn('public_id', $customerPublicIds)->pluck('public_id', 'id');

        return Activity::query()
            ->where('subject_type', (new Customer)->getMorphClass())
            ->whereIn('subject_id', $publicIds->keys())
            ->where('event', PreparePaymentReminder::EVENT)
            ->toBase()
            ->selectRaw('subject_id, max(created_at) as sent_at')
            ->groupBy('subject_id')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                $publicIds[$row->subject_id] => CarbonImmutable::parse($row->sent_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The customers among these who were reminded recently enough to leave alone.
     *
     * @param  array<int, array{customer_public_id: string|null, overdue_minor: int}>  $rows
     * @return list<string> customer public ids
     */
    public function recentlyReminded(array $rows): array
    {
        $overdue = collect($rows)
            ->filter(fn (array $row): bool => $row['customer_public_id'] !== null)
            ->pluck('overdue_minor', 'customer_public_id');
        $publicIds = Customer::query()->whereIn('public_id', $overdue->keys())->pluck('public_id', 'id');

        return array_values(Activity::query()
            ->where('subject_type', (new Customer)->getMorphClass())
            ->whereIn('subject_id', $publicIds->keys())
            ->where('event', PreparePaymentReminder::EVENT)
            ->where('created_at', '>=', now()->subDays(self::QUIET_DAYS))
            ->orderBy('id')
            ->get()
            ->keyBy('subject_id')
            ->filter(fn (Activity $sent): bool => $overdue[$publicIds[$sent->subject_id]] <= (int) $sent->properties['total_minor'])
            ->map(fn (Activity $sent): string => $publicIds[$sent->subject_id])
            ->all());
    }
}
