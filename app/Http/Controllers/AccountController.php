<?php

namespace App\Http\Controllers;

use App\Actions\DeleteUserAccount;
use App\Actions\ExportWorkspaceData;
use App\Exceptions\BillingActionRefused;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Taking your data out, and closing the account.
 *
 * The two live on one screen deliberately: someone about to leave should be
 * offered their records before they are asked to confirm losing access to
 * them, not after.
 */
class AccountController extends Controller
{
    public function __construct(
        private DeleteUserAccount $deletion,
        private ExportWorkspaceData $export,
    ) {}

    public function show(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('Settings/Account', [
            'deletion' => $this->deletion->preview($user),
            'exports' => $this->recentExports($request),
        ]);
    }

    /**
     * Builds the archive and hands it straight back.
     *
     * Built inline rather than queued: a company's whole ledger is a few
     * thousand rows, and a file that arrives now beats an email that arrives
     * later. If exports ever outgrow a request, the action moves to a job
     * without the screen changing.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $this->user($request);
        $workspace = $request->attributes->get('currentWorkspace');
        abort_unless($workspace instanceof Workspace, 404);

        $path = $this->export->execute($workspace, $user);

        activity('account')
            ->causedBy($user)
            ->withProperties(['workspace' => $workspace->public_id])
            ->log('workspace data exported');

        return Storage::disk('local')->download(
            $path,
            sprintf('%s-dados.zip', str($workspace->name)->slug()),
        );
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        // Refused before anything is touched, so `back()` still has a session
        // to carry the message on.
        if (! $this->deletion->preview($user)['can_delete']) {
            try {
                $this->deletion->execute($user);
            } catch (BillingActionRefused $exception) {
                return back()->with('error', $exception->getMessage());
            }
        }

        /*
         * Signed out first, and deliberately. Logging out cycles the remember
         * token, which saves the user — and saving a model that has just been
         * deleted inserts it again, against foreign keys that no longer have
         * anywhere to point.
         */
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->deletion->execute($user);

        return redirect()
            ->route('login')
            ->with('status', 'A sua conta foi eliminada. Obrigado por ter usado o serviço.');
    }

    /**
     * Archives still on disk from earlier requests.
     *
     * Shown so nobody wonders whether their download worked, and so a stale
     * copy of a company's ledger is visible rather than quietly accumulating.
     *
     * @return list<array{name: string, size: int, created_at: string}>
     */
    private function recentExports(Request $request): array
    {
        $workspace = $request->attributes->get('currentWorkspace');

        if (! $workspace instanceof Workspace) {
            return [];
        }

        $disk = Storage::disk('local');
        $exports = [];

        foreach ($disk->files(ExportWorkspaceData::DIRECTORY) as $file) {
            if (! str_contains(basename($file), $workspace->public_id)) {
                continue;
            }

            $exports[] = [
                'name' => basename($file),
                'size' => $disk->size($file),
                'created_at' => now()->setTimestamp($disk->lastModified($file))->toIso8601String(),
            ];
        }

        usort($exports, fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return array_slice($exports, 0, 5);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
