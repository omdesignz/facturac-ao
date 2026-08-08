<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\NotificationTopic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * What each user wants to hear about.
 */
class NotificationPreferenceController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'topics' => ['required', 'array'],
            'topics.*.database' => ['required', 'boolean'],
            'topics.*.mail' => ['required', 'boolean'],
        ]);

        /** @var array<string, array{database: bool, mail: bool}> $submitted */
        $submitted = $validated['topics'];

        $preferences = [];

        // Only the configurable topics are stored, and only by name. A topic
        // the form did not mention keeps its default rather than being read as
        // a silence the user asked for.
        foreach (NotificationTopic::configurable() as $topic) {
            if (! array_key_exists($topic->value, $submitted)) {
                continue;
            }

            $preferences[$topic->value] = [
                'database' => (bool) $submitted[$topic->value]['database'],
                'mail' => (bool) $submitted[$topic->value]['mail'],
            ];
        }

        $user->forceFill(['notification_preferences' => $preferences])->save();

        return back()->with('success', 'Preferências de notificação guardadas.');
    }
}
