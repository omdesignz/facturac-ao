<?php

namespace App\Actions;

use App\ComplaintCategory;
use App\ComplaintStatus;
use App\Models\Complaint;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\ComplaintReceived;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Enters a complaint in the book and starts the response clock.
 */
class FileComplaint
{
    /**
     * @param  array{category: string, subject: string, body: string, contact_name: string, contact_email: string, contact_phone: string|null}  $data
     */
    public function execute(Request $request, ?User $user, array $data): Complaint
    {
        $complaint = DB::transaction(function () use ($request, $user, $data): Complaint {
            // Locked so two complaints filed in the same second cannot be handed
            // the same reference number.
            $complaint = new Complaint([
                'user_id' => $user?->id,
                'workspace_id' => $user?->current_workspace_id,
                'category' => ComplaintCategory::from($data['category']),
                'status' => ComplaintStatus::Open,
                'subject' => $data['subject'],
                'body' => $data['body'],
                'contact_name' => $data['contact_name'],
                'contact_email' => $data['contact_email'],
                'contact_phone' => $data['contact_phone'],
                'response_due_at' => $this->responseDeadline(),
                'ip_address' => $request->ip(),
            ]);

            $complaint->reference = Complaint::nextReference();
            $complaint->save();

            return $complaint;
        });

        activity('complaint')
            ->event('filed')
            ->causedBy($user)
            ->performedOn($complaint)
            ->withProperties([
                'reference' => $complaint->reference,
                'category' => $complaint->category->value,
            ])
            ->log('complaint filed');

        // Sent to the address given on the form, which may not be the account's:
        // the person complaining is the one who needs the reference.
        $complaint->notify(new ComplaintReceived(
            reference: $complaint->reference,
            subject: $complaint->subject,
            categoryLabel: $complaint->category->label(),
            responseDueAt: $complaint->response_due_at->toIso8601String(),
        ));

        return $complaint;
    }

    /**
     * Working days, not calendar days: a complaint filed on Friday afternoon is
     * not owed an answer by Sunday.
     */
    private function responseDeadline(): CarbonImmutable
    {
        $days = max(1, (int) PlatformSetting::get('complaints_response_days'));

        return now()->addWeekdays($days);
    }
}
