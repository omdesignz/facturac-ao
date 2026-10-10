<?php

namespace App\Fiscal\Documents;

use App\AgtSubmissionStatus;
use App\Fiscal\Agt\Support\CanonicalJson;
use App\Models\AgtSubmission;
use App\Models\AgtSubmissionObservation;
use Carbon\CarbonImmutable;

/** A single versioned interpretation of normalized evidence, never remote messages. */
final class AgtObservationReducer
{
    /** @return array<string, mixed> */
    public static function empty(): array
    {
        return ['version' => 1, 'classification' => 'unknown', 'knowledge' => 'never_known', 'reported_state' => null, 'sync' => 'idle', 'delivery_state' => 'unknown', 'reason' => 'not_submitted', 'observation_id' => null, 'observed_at' => null, 'last_known_state' => null, 'last_known_observation_id' => null, 'last_successful_sync_at' => null, 'projected_at' => null, 'applied_sequence' => 0];
    }

    /** Local queue evidence is expressly not a reported AGT state.
     * @return array<string, mixed>
     */
    public static function queued(): array
    {
        return [...self::empty(), 'delivery_state' => 'pending', 'sync' => 'pending', 'reason' => 'delivery_pending', 'operational_status' => 'pending'];
    }

    public static function validProjection(mixed $state): bool
    {
        $shape = [...self::empty(), 'operational_status' => 'unknown'];
        if (! is_array($state) || count($state) !== count($shape) || array_diff_key($state, $shape) !== [] || ($state['version'] ?? null) !== 1) {
            return false;
        }
        $enums = ['classification' => ['unknown', 'partial', 'authoritative', 'conflicting'],
            'knowledge' => ['never_known', 'known', 'unknown_response', 'conflicting_evidence', 'legacy_unverified'],
            'reported_state' => [null, 'valid', 'invalid', 'processing', 'processing_cancelled'],
            'sync' => ['idle', 'pending', 'failed'], 'delivery_state' => ['unknown', 'pending', 'sending', 'acknowledged', 'failed'],
            'reason' => ['not_submitted', 'delivery_pending', 'delivery_acknowledged', 'processing_reported', 'validation_reported', 'invalidity_reported', 'processing_cancelled', 'request_failed', 'refresh_pending', 'sync_failed', 'stale_observation', 'unknown_response', 'evidence_conflict', 'legacy_unverified'],
            'last_known_state' => [null, 'valid', 'invalid', 'processing', 'processing_cancelled'],
            'operational_status' => ['unknown', 'pending', 'sending', 'received', 'processing', 'valid', 'invalid', 'cancelled']];
        foreach ($enums as $key => $allowed) {
            if (! in_array($state[$key], $allowed, true)) {
                return false;
            }
        }
        foreach (['observation_id', 'last_known_observation_id'] as $key) {
            if ($state[$key] !== null && (! is_int($state[$key]) || $state[$key] < 1)) {
                return false;
            }
        }
        foreach (['observed_at', 'last_successful_sync_at', 'projected_at'] as $key) {
            if ($state[$key] !== null && (! is_string($state[$key]) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $state[$key]))) {
                return false;
            }
            if ($state[$key] !== null) {
                try {
                    if (CarbonImmutable::parse($state[$key])->utc()->toIso8601String() !== $state[$key]) {
                        return false;
                    }
                } catch (\Throwable) {
                    return false;
                }
            }
        }

        return is_int($state['applied_sequence']) && $state['applied_sequence'] >= 0
            && ($state['knowledge'] !== 'known' || ($state['classification'] === 'authoritative' && $state['observation_id'] !== null && $state['observed_at'] !== null))
            && $state['operational_status'] === self::operationalStatus($state);
    }

    /** @param iterable<AgtSubmissionObservation> $observations
     * @return array<string, mixed>
     */
    public static function reduce(iterable $observations): array
    {
        $state = self::empty();
        $conflicted = false;
        $terminal = null;
        foreach ($observations as $entry) {
            $outcome = $entry->outcome;
            $state['projected_at'] = $entry->recorded_at->toIso8601String();
            if (($outcome['version'] ?? null) !== 1 || $entry->reducer_version !== 1
                || ! hash_equals($entry->outcome_sha256, hash('sha256', app(CanonicalJson::class)->encode($outcome)))) {
                $conflicted = true;
            }
            if ($entry->kind === 'conflict') {
                $conflicted = true;
            }
            if ($entry->kind === 'claim') {
                $state['applied_sequence'] = $entry->operation_sequence;
                $state['sync'] = 'pending';
                $state['reason'] = 'refresh_pending';
                if (($outcome['operation'] ?? null) === 'register_invoice') {
                    $state['delivery_state'] = 'sending';
                    $state['reason'] = 'delivery_pending';
                }
            } elseif (in_array($entry->kind, ['result', 'failure', 'legacy_import'], true)) {
                $reported = $outcome['reported_state'] ?? null;
                if (in_array($reported, ['valid', 'invalid'], true)) {
                    if ($terminal !== null && $terminal !== $reported) {
                        $conflicted = true;
                    }
                    $terminal = $reported;
                }
                if (($outcome['eligible_generation'] ?? false) && $entry->operation_sequence >= $state['applied_sequence']) {
                    $state['applied_sequence'] = $entry->operation_sequence;
                    foreach (['classification', 'knowledge', 'reported_state', 'sync', 'delivery_state', 'reason'] as $key) {
                        $state[$key] = $outcome[$key] ?? self::empty()[$key];
                    }
                    $state['observation_id'] = $entry->id;
                    $state['observed_at'] = $entry->observed_at?->toIso8601String();
                    if (($outcome['successful_sync'] ?? false) && in_array($reported, ['valid', 'invalid', 'processing', 'processing_cancelled'], true)) {
                        $state['last_known_state'] = $reported;
                        $state['last_known_observation_id'] = $entry->id;
                        $state['last_successful_sync_at'] = $state['observed_at'];
                    }
                }
                if (($outcome['classification'] ?? null) === 'conflicting') {
                    $conflicted = true;
                }
            }
        }
        if ($conflicted) {
            $state['classification'] = 'conflicting';
            $state['knowledge'] = 'conflicting_evidence';
            $state['reported_state'] = null;
            $state['reason'] = 'evidence_conflict';
        }

        $state['operational_status'] = self::operationalStatus($state);

        return $state;
    }

    /** @param array<string, mixed> $state */
    public static function operationalStatus(array $state): string
    {
        if ($state['knowledge'] === 'known' && $state['sync'] !== 'failed') {
            if ($state['reported_state'] === 'processing') {
                return 'processing';
            }
            if ($state['sync'] === 'idle') {
                return $state['reported_state'] === 'processing_cancelled' ? 'cancelled' : ($state['reported_state'] ?? 'unknown');
            }
        }
        if ($state['knowledge'] === 'never_known' && $state['sync'] !== 'failed') {
            return match ($state['delivery_state']) {
                'sending' => 'sending', 'acknowledged' => 'received', 'pending' => 'pending', default => 'unknown',
            };
        }

        return 'unknown';
    }

    /** @return array<string, mixed> */
    public static function rebuild(AgtSubmission $submission): array
    {
        $entries = AgtSubmissionObservation::query()->where('agt_submission_id', $submission->id)
            ->orderBy('operation_sequence')->orderBy('id')->get();
        if ($entries->isEmpty() && $submission->operation_sequence === 0 && $submission->status === AgtSubmissionStatus::Pending) {
            return self::queued();
        }

        return self::reduce($entries);
    }
}
