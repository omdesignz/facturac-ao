<?php

namespace App\Actions;

use App\Exceptions\BillingActionRefused;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\PaymentReminder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/**
 * Sends a reminder someone has read and approved.
 *
 * Refuses when there is nobody to send it to, or nothing late to remind about:
 * a reminder for a debt that has since been paid is the one mistake that costs
 * a customer relationship.
 *
 * A phone on a weak network cannot tell a lost answer from a lost request, so it
 * sends a client key with each reminder and repeats it when retrying. The key is
 * kept with the record of the reminder, under a lock on the customer, so a repeat
 * answers with the first result however late it arrives and sends nothing.
 */
class SendPaymentReminder
{
    public function __construct(private PreparePaymentReminder $prepare) {}

    /**
     * @return array{to: string, sent_at: string, replayed: bool}
     */
    public function execute(Customer $customer, string $subject, string $body, ?User $actor = null, ?string $clientKey = null): array
    {
        return DB::transaction(function () use ($customer, $subject, $body, $actor, $clientKey): array {
            Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            $earlier = $clientKey === null ? null : $this->sentWithKey($customer, $clientKey);

            if ($earlier !== null) {
                return [
                    'to' => (string) $earlier->properties['email'],
                    'sent_at' => $earlier->created_at->toIso8601String(),
                    'replayed' => true,
                ];
            }

            $draft = $this->prepare->execute($customer);

            if ($draft['to'] === null) {
                throw BillingActionRefused::because(
                    'Este cliente não tem email registado. Adicione um na ficha do cliente.',
                );
            }

            if ($draft['documents'] === []) {
                throw BillingActionRefused::because(
                    'Este cliente já não tem nada vencido. Actualize a lista.',
                );
            }

            // A second click, or a second tab, within a minute is the same reminder.
            if (! Cache::add("payment-reminder:{$customer->id}:".sha1($subject.$body), true, 60)) {
                throw BillingActionRefused::because(
                    'Este lembrete já foi enviado há instantes.',
                );
            }

            Notification::route('mail', $draft['to'])->notify(new PaymentReminder($subject, $body));

            activity('customer')
                ->causedBy($actor)
                ->performedOn($customer)
                ->event(PreparePaymentReminder::EVENT)
                ->withProperties(array_filter([
                    'email' => $draft['to'],
                    'documents' => array_column($draft['documents'], 'document_no'),
                    'total_minor' => $draft['total_minor'],
                    'client_key' => $clientKey,
                ], fn (mixed $value): bool => $value !== null))
                ->log('payment reminder sent to customer');

            return ['to' => $draft['to'], 'sent_at' => now()->toIso8601String(), 'replayed' => false];
        });
    }

    private function sentWithKey(Customer $customer, string $clientKey): ?Activity
    {
        return Activity::query()
            ->where('subject_type', $customer->getMorphClass())
            ->where('subject_id', $customer->getKey())
            ->where('event', PreparePaymentReminder::EVENT)
            ->where('properties->client_key', $clientKey)
            ->first();
    }
}
