<?php

namespace Database\Factories;

use App\AgtSubmissionAttemptOperation;
use App\Models\AgtSubmission;
use App\Models\AgtSubmissionAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgtSubmissionAttempt> */
class AgtSubmissionAttemptFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agt_submission_id' => AgtSubmission::factory(),
            'workspace_id' => fn (array $attributes): int => $this->submission($attributes)->workspace_id,
            'legal_entity_id' => fn (array $attributes): int => $this->submission($attributes)->legal_entity_id,
            'operation' => AgtSubmissionAttemptOperation::RegisterInvoice,
            'attempt_number' => 1,
            'endpoint_path' => '/registarFactura',
            'request_body' => fn (array $attributes): string => $this->submission($attributes)->request_body,
            'request_body_sha256' => fn (array $attributes): string => $this->submission($attributes)->request_body_sha256,
            'response_body' => null,
            'response_body_sha256' => null,
            'http_status' => null,
            'result_code' => null,
            'error_codes' => [],
            'safe_message' => 'Tentativa criada.',
            'started_at' => now(),
            'completed_at' => now(),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function submission(array $attributes): AgtSubmission
    {
        $submission = AgtSubmission::query()->find($attributes['agt_submission_id']);

        if (! $submission instanceof AgtSubmission) {
            throw new \LogicException('An AGT attempt requires an existing submission.');
        }

        return $submission;
    }
}
