<?php

namespace App\Http;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who is signed in, in terms a person recognises.
 *
 * The user agent is turned into "Chrome no macOS" rather than shown raw. The
 * question being answered is "do I recognise this?", and a hundred characters
 * of version string is not how anyone answers it.
 */
class ActiveSessions
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user, string $currentSessionId): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        $rows = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->limit(50)
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        $sessions = [];

        foreach ($rows as $row) {
            $agent = (string) ($row->user_agent ?? '');

            $sessions[] = [
                'id' => (string) $row->id,
                'is_current' => (string) $row->id === $currentSessionId,
                'ip_address' => $row->ip_address,
                'browser' => $this->browser($agent),
                'platform' => $this->platform($agent),
                'last_active_at' => $row->last_activity === null
                    ? null
                    : now()->setTimestamp((int) $row->last_activity)->toIso8601String(),
            ];
        }

        return $sessions;
    }

    private function browser(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            // Chrome ships "Safari" in its own string, so it has to be ruled
            // out before Safari can be claimed.
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            $agent === '' => 'Desconhecido',
            default => 'Outro navegador',
        };
    }

    private function platform(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Desconhecido',
        };
    }
}
