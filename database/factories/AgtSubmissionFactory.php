<?php

namespace Database\Factories;

use App\AgtSubmissionOperation;
use App\AgtSubmissionStatus;
use App\Models\AgtConnection;
use App\Models\AgtSubmission;
use App\Models\FiscalDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AgtSubmission> */
class AgtSubmissionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'fiscal_document_id' => FiscalDocument::factory()->issued(),
            'workspace_id' => fn (array $attributes): int => $this->document($attributes)->workspace_id,
            'legal_entity_id' => fn (array $attributes): int => $this->document($attributes)->legal_entity_id,
            'agt_connection_id' => function (array $attributes): int {
                $document = $this->document($attributes);

                return AgtConnection::factory()->create([
                    'workspace_id' => $document->workspace_id,
                    'legal_entity_id' => $document->legal_entity_id,
                ])->id;
            },
            'submission_uuid' => (string) Str::uuid(),
            'operation' => AgtSubmissionOperation::RegisterInvoice,
            'schema_version' => '2.0',
            'status' => AgtSubmissionStatus::Pending,
            'request_id' => null,
            'request_body' => fn (array $attributes): string => json_encode([
                'schemaVersion' => '2.0',
                'taxRegistrationNumber' => $this->document($attributes)->legalEntity->tax_identification_number,
                'numberOfEntries' => 1,
                'documents' => [['documentNo' => $this->document($attributes)->document_no]],
            ], JSON_THROW_ON_ERROR),
            'request_body_sha256' => fn (array $attributes): string => hash('sha256', $attributes['request_body']),
            'last_response_body_sha256' => null,
            'last_http_status' => null,
            'last_result_code' => null,
            'last_error_codes' => [],
            'safe_message' => 'Aguardando transmissão à AGT.',
            'attempt_count' => 0,
            'next_attempt_at' => now(),
            'submitted_at' => null,
            'received_at' => null,
            'completed_at' => null,
            'failed_at' => null,
        ];
    }

    public function received(): static
    {
        return $this->state(fn (): array => [
            'status' => AgtSubmissionStatus::Received,
            'request_id' => fake()->numerify('2026###########'),
            'received_at' => now(),
            'next_attempt_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function document(array $attributes): FiscalDocument
    {
        $document = FiscalDocument::query()->find($attributes['fiscal_document_id']);

        if (! $document instanceof FiscalDocument) {
            throw new \LogicException('An AGT submission requires an issued fiscal document.');
        }

        return $document;
    }
}
