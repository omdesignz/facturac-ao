<?php

namespace App\Http\Controllers;

use App\ComplaintStatus;
use App\Models\Complaint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff side of the complaints book: triage, answer, close.
 */
class SupportComplaintController extends Controller
{
    public function index(Request $request): Response
    {
        $status = (string) $request->string('status', 'open');

        $query = Complaint::query()->with(['user', 'handledBy']);

        if ($status === 'open') {
            $query->stillOpen();
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        $complaints = $query->latest('created_at')->paginate(20)->withQueryString();

        return Inertia::render('Support/Complaints', [
            'complaints' => [
                'data' => array_values($complaints->getCollection()
                    ->map(fn (Complaint $complaint): array => $this->present($complaint))
                    ->all()),
                'links' => $complaints->linkCollection()->all(),
                'total' => $complaints->total(),
            ],
            'filters' => ['status' => $status],
            'openCount' => Complaint::query()->stillOpen()->count(),
            'overdueCount' => Complaint::query()
                ->stillOpen()
                ->where('response_due_at', '<', now())
                ->count(),
            'statuses' => array_map(
                fn (ComplaintStatus $value): array => [
                    'value' => $value->value,
                    'label' => $value->label(),
                ],
                ComplaintStatus::cases(),
            ),
        ]);
    }

    public function update(Request $request, Complaint $complaint): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:open,in_progress,resolved,rejected'],
            'resolution' => ['nullable', 'string', 'max:4000'],
        ]);

        $status = ComplaintStatus::from((string) $validated['status']);

        // Closing without saying why leaves the customer with nothing to read,
        // and leaves us with nothing to show if they escalate.
        if ($status->isClosed() && trim((string) ($validated['resolution'] ?? '')) === '') {
            return back()->withErrors([
                'resolution' => 'Escreva a resposta antes de fechar a reclamação.',
            ]);
        }

        $complaint->forceFill([
            'status' => $status,
            'resolution' => $validated['resolution'] ?? $complaint->resolution,
            'handled_by_user_id' => $request->user()->id,
            'acknowledged_at' => $complaint->acknowledged_at ?? now(),
            'resolved_at' => $status->isClosed() ? ($complaint->resolved_at ?? now()) : null,
        ])->save();

        activity('complaint')
            ->event('updated')
            ->causedBy($request->user())
            ->performedOn($complaint)
            ->withProperties([
                'reference' => $complaint->reference,
                'status' => $status->value,
            ])
            ->log('complaint updated');

        return back()->with('success', "Reclamação {$complaint->reference} actualizada.");
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Complaint $complaint): array
    {
        return [
            'public_id' => $complaint->public_id,
            'reference' => $complaint->reference,
            'subject' => $complaint->subject,
            'body' => $complaint->body,
            'category_label' => $complaint->category->label(),
            'status' => $complaint->status->value,
            'status_label' => $complaint->status->label(),
            'resolution' => $complaint->resolution,
            'contact_name' => $complaint->contact_name,
            'contact_email' => $complaint->contact_email,
            'contact_phone' => $complaint->contact_phone,
            'account_email' => $complaint->user?->email,
            'handled_by' => $complaint->handledBy?->name,
            'created_at' => $complaint->created_at?->toIso8601String(),
            'response_due_at' => $complaint->response_due_at->toIso8601String(),
            'resolved_at' => $complaint->resolved_at?->toIso8601String(),
            'overdue' => $complaint->isOverdue(),
        ];
    }
}
