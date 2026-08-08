<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RenewWorkSessionController extends Controller
{
    /**
     * Begin a fresh work block.
     *
     * This is the only way to extend a session short of signing in again —
     * activity does not do it, because the deadline is deliberately not an
     * idle timer.
     *
     * Answers with a redirect rather than the new figure: the caller is an
     * Inertia visit, and the shared work-session prop on the page it lands on
     * already carries the fresh countdown. Returning JSON here would be a
     * response Inertia cannot read, and it would show the payload to the user
     * instead of applying it.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $request->session()->put(
            (string) config('work_session.started_at_key'),
            now()->getTimestamp(),
        );

        return back();
    }
}
