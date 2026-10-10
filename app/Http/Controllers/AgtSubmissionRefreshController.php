<?php

namespace App\Http\Controllers;

use App\AgtSubmissionStatus;
use App\Fiscal\Documents\AgtSubmissionExecution;
use App\Http\Requests\RefreshAgtSubmissionsRequest;
use App\Jobs\PollAgtSubmissionStatus;
use App\Models\AgtSubmission;
use App\Models\LegalEntity;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class AgtSubmissionRefreshController extends Controller
{
    public function __invoke(RefreshAgtSubmissionsRequest $request): RedirectResponse
    {
        $workspace = $request->attributes->get('currentWorkspace');
        $legalEntity = $workspace instanceof Workspace
            ? $workspace->legalEntities()->oldest('id')->first()
            : null;

        abort_unless($legalEntity instanceof LegalEntity, 404);
        $submissionIds = AgtSubmission::query()
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->whereIn('status', [
                AgtSubmissionStatus::Received,
                AgtSubmissionStatus::Processing,
            ])
            ->limit(100)
            ->pluck('id');

        $scheduled = 0;
        foreach ($submissionIds as $submissionId) {
            DB::transaction(function () use ($submissionId, $legalEntity, &$scheduled): void {
                $submission = AgtSubmissionExecution::locked($submissionId);
                if ($submission === null || $submission->workspace_id !== $legalEntity->workspace_id
                    || $submission->legal_entity_id !== $legalEntity->id || ! $submission->status->canPoll()
                    || $submission->operation_lease_expires_at?->gt(AgtSubmissionExecution::databaseNow())) {
                    return;
                }
                $submission->update(['next_attempt_at' => now()]);
                DB::afterCommit(fn () => PollAgtSubmissionStatus::dispatch($submissionId));
                $scheduled++;
            }, 3);
        }

        return back()->with(
            'success',
            $scheduled === 0
                ? 'Não existem pedidos AGT pendentes de validação.'
                : $scheduled.' pedido(s) colocado(s) na fila de actualização.',
        );
    }
}
