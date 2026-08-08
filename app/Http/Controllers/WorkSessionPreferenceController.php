<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkSessionPreferenceController extends Controller
{
    /**
     * Change how long this user's work sessions last, everywhere they sign in.
     *
     * Saving also restarts the current block, so the new length takes effect
     * immediately rather than at the next sign-in.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'work_session_minutes' => [
                'required',
                'integer',
                'min:'.(int) config('work_session.min_minutes'),
                'max:'.(int) config('work_session.max_minutes'),
            ],
        ]);

        $request->user()->forceFill([
            'work_session_minutes' => (int) $validated['work_session_minutes'],
        ])->save();

        $request->session()->put(
            (string) config('work_session.started_at_key'),
            now()->getTimestamp(),
        );

        return back()->with('success', 'Duração da sessão de trabalho actualizada.');
    }
}
