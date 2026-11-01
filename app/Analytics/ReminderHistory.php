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
}
