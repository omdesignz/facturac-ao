<?php

namespace App\Http\Controllers;

use App\AgtSubmissionStatus;
use App\Http\Requests\RefreshAgtSubmissionsRequest;
use App\Jobs\PollAgtSubmissionStatus;
use App\Models\AgtSubmission;
use App\Models\LegalEntity;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

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

        AgtSubmission::query()
            ->whereKey($submissionIds)
            ->update(['next_attempt_at' => now()]);
        $submissionIds->each(
            fn (int $submissionId) => PollAgtSubmissionStatus::dispatch($submissionId),
        );

        return back()->with(
            'success',
            $submissionIds->isEmpty()
                ? 'Não existem pedidos AGT pendentes de validação.'
                : $submissionIds->count().' pedido(s) colocado(s) na fila de actualização.',
        );
    }
}
