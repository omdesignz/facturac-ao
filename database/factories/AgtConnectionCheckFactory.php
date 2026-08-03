<?php

namespace Database\Factories;

use App\AgtConnectionCheckStatus;
use App\AgtOperation;
use App\Models\AgtConnection;
use App\Models\AgtConnectionCheck;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AgtConnectionCheck>
 */
class AgtConnectionCheckFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agt_connection_id' => AgtConnection::factory(),
            'workspace_id' => function (array $attributes): int {
                return $this->resolveAgtConnection($attributes)->workspace_id;
            },
            'legal_entity_id' => function (array $attributes): int {
                return $this->resolveAgtConnection($attributes)->legal_entity_id;
            },
            'operation' => AgtOperation::ListSeriesProbe,
            'status' => AgtConnectionCheckStatus::Passed,
            'probe_uuid' => (string) Str::uuid(),
            'endpoint_path' => '/listarSeries',
            'request_body_sha256' => hash('sha256', 'request'),
            'response_body_sha256' => hash('sha256', 'response'),
            'http_status' => 200,
            'result_code' => '0',
            'error_codes' => [],
            'safe_message' => 'Ligação ao ambiente de homologação verificada.',
            'duration_ms' => fake()->numberBetween(30, 500),
            'attempt_count' => 1,
            'requested_by_user_id' => User::factory(),
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function resolveAgtConnection(array $attributes): AgtConnection
    {
        $connection = AgtConnection::query()->find($attributes['agt_connection_id']);

        if (! $connection instanceof AgtConnection) {
            throw new \LogicException('An AGT check requires an existing connection.');
        }

        return $connection;
    }
}
