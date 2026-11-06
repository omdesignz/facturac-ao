<?php

namespace App\Http\Controllers;

use App\Actions\PreparePaymentReminder;
use App\Actions\RecordWhatsAppReminder;
use App\Actions\SendPaymentReminder;
use App\Exceptions\BillingActionRefused;
use App\Http\Requests\RecordWhatsAppReminderRequest;
use App\Http\Requests\SendPaymentReminderRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A reminder for a customer who is late: prepared for someone to read, and sent
 * only when they say so.
 */
class PaymentReminderController extends Controller
{
    public function show(Customer $customer, PreparePaymentReminder $prepare): JsonResponse
    {
        Gate::authorize('update', $customer);

        return response()->json($prepare->execute($customer));
    }

    public function store(
        SendPaymentReminderRequest $request,
        Customer $customer,
        SendPaymentReminder $send,
    ): RedirectResponse {
        Gate::authorize('update', $customer);

        try {
            $send->execute(
                $customer,
                $request->string('subject')->toString(),
                $request->string('body')->toString(),
                $request->user(),
            );
        } catch (BillingActionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Lembrete enviado para {$customer->email}.");
    }

    /**
     * The person sent the reminder themselves on WhatsApp; nothing leaves from here.
     */
    public function whatsapp(
        RecordWhatsAppReminderRequest $request,
        Customer $customer,
        RecordWhatsAppReminder $record,
    ): RedirectResponse {
        Gate::authorize('update', $customer);

        try {
            $record->execute($customer, $request->user(), $request->string('client_key')->toString());
        } catch (BillingActionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Lembrete registado: {$customer->name} foi lembrado pelo WhatsApp.");
    }
}
