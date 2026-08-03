<?php

namespace Database\Factories;

use App\FiscalDocumentType;
use App\FiscalSeriesContingency;
use App\FiscalSeriesStatus;
use App\Models\AgtConnection;
use App\Models\Establishment;
use App\Models\FiscalSeries;
use App\Models\LegalEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FiscalSeries> */
class FiscalSeriesFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'legal_entity_id' => LegalEntity::factory()->configured(),
            'workspace_id' => fn (array $attributes): int => $this->legalEntity($attributes)->workspace_id,
            'establishment_id' => function (array $attributes): int {
                $legalEntity = $this->legalEntity($attributes);

                return Establishment::factory()->headOffice()->create([
                    'workspace_id' => $legalEntity->workspace_id,
                    'legal_entity_id' => $legalEntity->id,
                ])->id;
            },
            'agt_connection_id' => function (array $attributes): int {
                $legalEntity = $this->legalEntity($attributes);

                return AgtConnection::factory()->create([
                    'workspace_id' => $legalEntity->workspace_id,
                    'legal_entity_id' => $legalEntity->id,
                ])->id;
            },
            'series_code' => 'FT'.now()->format('y').'TEST',
            'series_year' => (int) now()->format('Y'),
            'document_type' => FiscalDocumentType::Invoice,
            'status' => FiscalSeriesStatus::Open,
            'contingency_indicator' => FiscalSeriesContingency::Normal,
            'invoicing_method' => 'FESF',
            'agt_creation_date' => now()->toDateString(),
            'agt_first_document_no' => '1',
            'agt_last_document_no' => '999999',
            'agt_first_document_created' => null,
            'agt_last_document_created' => null,
            'first_authorized_number' => 1,
            'last_authorized_number' => 999999,
            'next_number' => 1,
            'last_issued_number' => null,
            'last_document_date' => null,
            'synchronized_at' => now(),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function legalEntity(array $attributes): LegalEntity
    {
        $legalEntity = LegalEntity::query()->find($attributes['legal_entity_id']);

        if (! $legalEntity instanceof LegalEntity) {
            throw new \LogicException('A fiscal series requires an existing legal entity.');
        }

        return $legalEntity;
    }
}
