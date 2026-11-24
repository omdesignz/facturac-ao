<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IssuingVerificationController extends Controller
{
    /**
     * Whether this company asks for two-step verification before issuing.
     *
     * Only the owner decides, behind a fresh password; each change is kept in
     * the security log with who made it.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('currentWorkspace');
        /** @var WorkspaceMembership $membership */
        $membership = $request->attributes->get('currentWorkspaceMembership');

        abort_unless($membership->role === WorkspaceRole::Owner, 403);

        $validated = $request->validate([
            'requires_mfa_to_issue' => ['required', 'boolean'],
        ]);
        $required = (bool) $validated['requires_mfa_to_issue'];

        if ($workspace->requires_mfa_to_issue === $required) {
            return back();
        }

        $workspace->forceFill(['requires_mfa_to_issue' => $required])->save();

        activity('security')
            ->event($required ? 'issuing-mfa-required' : 'issuing-mfa-waived')
            ->causedBy($request->user())
            ->performedOn($workspace)
            ->withProperties(['workspace_id' => $workspace->id])
            ->log($required
                ? 'two-step verification required to issue documents'
                : 'two-step verification waived for issuing documents');

        return back()->with('success', $required
            ? 'A partir de agora, emitir pede a verificação em dois passos.'
            : 'Emitir já não pede a verificação em dois passos. A palavra-passe continua a ser pedida.');
    }
}
