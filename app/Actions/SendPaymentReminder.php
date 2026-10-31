<?php

namespace App\Actions;

use App\Exceptions\BillingActionRefused;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\PaymentReminder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a reminder someone has read and approved.
 *
 * Refuses when there is nobody to send it to, or nothing late to remind about:
 * a reminder for a debt that has since been paid is the one mistake that costs
 * a customer relationship.
 */
class SendPaymentReminder
{
    public function __construct(private PreparePaymentReminder $prepare) {}

    public function execute(Customer $customer, string $subject, string $body, ?User $actor = null): void
    {
        $draft = $this->prepare->execute($customer);

        if ($draft['to'] === null) {
            throw BillingActionRefused::because(
                'Este cliente não tem email registado. Adicione um na ficha do cliente.',
            );
        }

        if ($draft['documents'] === []) {
            throw BillingActionRefused::because(
                'Este cliente já não tem nada vencido. Actualize a página.',
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
            ->withProperties([
                'email' => $draft['to'],
                'documents' => array_column($draft['documents'], 'document_no'),
                'total_minor' => $draft['total_minor'],
            ])
            ->log('payment reminder sent to customer');
    }
}
