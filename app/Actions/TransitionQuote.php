<?php

namespace App\Actions;

use App\Exceptions\BillingActionRefused;
use App\Models\Quote;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\QuoteWasAccepted;
use App\QuoteStatus;

/**
 * Moves a quote along: a draft goes out, and what went out is accepted or refused.
 * Marking it as sent records that it left; nothing is delivered from here.
 */
final readonly class TransitionQuote
{
    /**
     * @throws BillingActionRefused
     */
    public function execute(Quote $quote, User $user, QuoteStatus $status, ?Workspace $workspace): void
    {
        $allowedStatuses = match ($quote->status) {
            QuoteStatus::Draft => [QuoteStatus::Sent],
            QuoteStatus::Sent => [QuoteStatus::Accepted, QuoteStatus::Rejected],
            default => [],
        };

        if ($quote->hasLapsed() || ! in_array($status, $allowedStatuses, true)) {
            throw BillingActionRefused::because('Esta mudança de estado não é permitida para o orçamento.');
        }

        if ($status === QuoteStatus::Sent && $quote->lines()->doesntExist()) {
            throw BillingActionRefused::because('Adicione pelo menos uma linha antes de enviar o orçamento.');
        }

        $quote->forceFill([
            'status' => $status,
            'sent_at' => $status === QuoteStatus::Sent ? now() : $quote->sent_at,
            'decided_at' => $status === QuoteStatus::Sent ? null : now(),
        ])->save();

        activity('quote')
            ->causedBy($user)
            ->performedOn($quote)
            ->event($status->value)
            ->withProperties(['reference' => $quote->reference])
            ->log("quote marked {$status->value}");

        if ($status === QuoteStatus::Accepted && $workspace instanceof Workspace) {
            QuoteWasAccepted::fromQuote($quote)->sendToWorkspace($workspace);
        }
    }
}
