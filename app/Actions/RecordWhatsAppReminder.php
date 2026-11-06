<?php

namespace App\Actions;

use App\Exceptions\BillingActionRefused;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Records a reminder the person sent themselves on WhatsApp.
 *
 * Nothing is sent from here: the phone opens WhatsApp with the words ready, the
 * person sends them, and only when they say they did does the phone call this.
 * From then on the customer counts as reminded, exactly as after an email, so
 * Hoje leaves them alone for the quiet days and the next reminder firms up.
 *
 * The client key works as for email: a repeat answers with the first record.
 */
class RecordWhatsAppReminder
{
    public const string CHANNEL = 'whatsapp';

    public function __construct(private PreparePaymentReminder $prepare) {}

    /**
     * @return array{to: string, sent_at: string, replayed: bool}
     */
    public function execute(Customer $customer, ?User $actor, string $clientKey): array
    {
        return DB::transaction(function () use ($customer, $actor, $clientKey): array {
            Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            $earlier = Activity::query()
                ->where('subject_type', $customer->getMorphClass())
                ->where('subject_id', $customer->getKey())
                ->where('event', PreparePaymentReminder::EVENT)
                ->where('properties->client_key', $clientKey)
                ->first();

            if ($earlier !== null) {
                return [
                    'to' => (string) ($earlier->properties['whatsapp'] ?? ''),
                    'sent_at' => $earlier->created_at->toIso8601String(),
                    'replayed' => true,
                ];
            }

            $draft = $this->prepare->execute($customer);

            if ($draft['whatsapp_to'] === null) {
                throw BillingActionRefused::because(
                    'Este cliente não tem um telemóvel válido registado. Corrija-o na ficha do cliente.',
                );
            }

            if ($draft['documents'] === []) {
                throw BillingActionRefused::because(
                    'Este cliente já não tem nada vencido. Actualize a lista.',
                );
            }

            activity('customer')
                ->causedBy($actor)
                ->performedOn($customer)
                ->event(PreparePaymentReminder::EVENT)
                ->withProperties([
                    'channel' => self::CHANNEL,
                    'whatsapp' => $draft['whatsapp_to'],
                    'documents' => array_column($draft['documents'], 'document_no'),
                    'total_minor' => $draft['total_minor'],
                    'client_key' => $clientKey,
                ])
                ->log('payment reminder sent to customer on WhatsApp');

            return ['to' => $draft['whatsapp_to'], 'sent_at' => now()->toIso8601String(), 'replayed' => false];
        });
    }
}
