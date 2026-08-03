<?php

namespace Database\Factories;

use App\FiscalTaxType;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLineTax;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalDocumentLineTax>
 */
class FiscalDocumentLineTaxFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fiscal_document_line_id' => FiscalDocumentLine::factory(),
            'workspace_id' => function (array $attributes): int {
                return $this->resolveLine($attributes)->workspace_id;
            },
            'legal_entity_id' => function (array $attributes): int {
                return $this->resolveLine($attributes)->legal_entity_id;
            },
            'fiscal_document_id' => function (array $attributes): int {
                return $this->resolveLine($attributes)->fiscal_document_id;
            },
            'tax_type' => FiscalTaxType::Vat,
            'tax_country_region' => 'AO',
            'tax_code' => 'NOR',
            'tax_rate_basis_points' => 1_400,
            'tax_contribution_minor' => 14_00,
            'tax_exemption_code' => null,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function resolveLine(array $attributes): FiscalDocumentLine
    {
        $line = FiscalDocumentLine::query()->find($attributes['fiscal_document_line_id']);

        if (! $line instanceof FiscalDocumentLine) {
            throw new \LogicException('A fiscal line tax requires an existing line.');
        }

        return $line;
    }
}
