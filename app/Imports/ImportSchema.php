<?php

namespace App\Imports;

use App\DataImportType;
use Illuminate\Support\Str;

class ImportSchema
{
    /**
     * @return list<array{key: string, label: string, required: bool, example: string}>
     */
    public function fields(DataImportType $type): array
    {
        return match ($type) {
            DataImportType::Customers => [
                ['key' => 'name', 'label' => 'Nome ou razão social', 'required' => true, 'example' => 'Kwanza Criativo, Lda.'],
                ['key' => 'tax_identification_number', 'label' => 'NIF', 'required' => true, 'example' => '5417123456'],
                ['key' => 'country_code', 'label' => 'Código do país', 'required' => false, 'example' => 'AO'],
                ['key' => 'address_line', 'label' => 'Morada', 'required' => false, 'example' => 'Rua Rainha Ginga, Luanda'],
                ['key' => 'email', 'label' => 'E-mail', 'required' => false, 'example' => 'financeiro@empresa.ao'],
                ['key' => 'phone', 'label' => 'Telefone', 'required' => false, 'example' => '+244 923 000 000'],
                ['key' => 'is_active', 'label' => 'Activo', 'required' => false, 'example' => 'Sim'],
            ],
            DataImportType::CatalogueItems => [
                ['key' => 'code', 'label' => 'Código', 'required' => true, 'example' => 'SERV-001'],
                ['key' => 'type', 'label' => 'Tipo', 'required' => true, 'example' => 'Serviço'],
                ['key' => 'name', 'label' => 'Nome', 'required' => true, 'example' => 'Consultoria mensal'],
                ['key' => 'description', 'label' => 'Descrição', 'required' => false, 'example' => 'Acompanhamento de gestão'],
                ['key' => 'unit_of_measure', 'label' => 'Unidade', 'required' => false, 'example' => 'UN'],
                ['key' => 'unit_price', 'label' => 'Preço unitário', 'required' => true, 'example' => '150000,00'],
                ['key' => 'currency_code', 'label' => 'Moeda', 'required' => false, 'example' => 'AOA'],
                ['key' => 'tax_type', 'label' => 'Tipo de imposto', 'required' => false, 'example' => 'IVA'],
                ['key' => 'tax_code', 'label' => 'Código do imposto', 'required' => false, 'example' => 'NOR'],
                ['key' => 'tax_percentage', 'label' => 'Taxa de imposto', 'required' => false, 'example' => '14'],
                ['key' => 'tax_exemption_code', 'label' => 'Motivo de isenção', 'required' => false, 'example' => 'M00'],
                ['key' => 'is_active', 'label' => 'Activo', 'required' => false, 'example' => 'Sim'],
            ],
        };
    }

    /** @return list<string> */
    public function requiredKeys(DataImportType $type): array
    {
        $requiredKeys = [];

        foreach ($this->fields($type) as $field) {
            if ($field['required']) {
                $requiredKeys[] = $field['key'];
            }
        }

        return $requiredKeys;
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string>
     */
    public function suggestedMapping(DataImportType $type, array $headers): array
    {
        $available = collect($headers)->mapWithKeys(
            fn (string $header): array => [Str::slug($header, '_') => $header],
        );
        $mapping = [];

        foreach ($this->fields($type) as $field) {
            $mapping[$field['key']] = '';

            foreach ($this->aliases($type, $field['key']) as $alias) {
                $match = $available->get(Str::slug($alias, '_'));

                if (is_string($match)) {
                    $mapping[$field['key']] = $match;

                    break;
                }
            }
        }

        return $mapping;
    }

    /** @return array{headers: list<string>, sample: list<string>} */
    public function template(DataImportType $type): array
    {
        return match ($type) {
            DataImportType::Customers => [
                'headers' => ['Nome', 'NIF', 'País', 'Morada', 'Email', 'Telefone', 'Activo'],
                'sample' => ['Kwanza Criativo, Lda.', '5417123456', 'AO', 'Rua Rainha Ginga, Luanda', 'financeiro@empresa.ao', '+244 923 000 000', 'Sim'],
            ],
            DataImportType::CatalogueItems => [
                'headers' => ['Código', 'Tipo', 'Nome', 'Descrição', 'Unidade', 'Preço unitário', 'Moeda', 'Tipo imposto', 'Código imposto', 'Taxa imposto', 'Motivo isenção', 'Activo'],
                'sample' => ['SERV-001', 'Serviço', 'Consultoria mensal', 'Acompanhamento de gestão', 'UN', '150000,00', 'AOA', 'IVA', 'NOR', '14', '', 'Sim'],
            ],
        };
    }

    /** @return list<string> */
    private function aliases(DataImportType $type, string $field): array
    {
        $common = [
            'is_active' => ['activo', 'ativa', 'active', 'is_active', 'estado'],
        ];
        $aliases = match ($type) {
            DataImportType::Customers => [
                'name' => ['nome', 'name', 'cliente', 'razao_social', 'denominacao'],
                'tax_identification_number' => ['nif', 'tax_identification_number', 'tax_id', 'numero_contribuinte'],
                'country_code' => ['pais', 'country_code', 'codigo_pais'],
                'address_line' => ['morada', 'endereco', 'address', 'localizacao'],
                'email' => ['email', 'e_mail', 'correio_electronico'],
                'phone' => ['telefone', 'telemovel', 'phone', 'contacto'],
                ...$common,
            ],
            DataImportType::CatalogueItems => [
                'code' => ['codigo', 'code', 'sku', 'product_code', 'codigo_artigo'],
                'type' => ['tipo', 'type', 'tipo_artigo'],
                'name' => ['nome', 'name', 'produto', 'artigo', 'servico'],
                'description' => ['descricao', 'description', 'detalhes'],
                'unit_of_measure' => ['unidade', 'unit', 'unit_of_measure', 'unidade_medida'],
                'unit_price' => ['preco_unitario', 'preco', 'unit_price', 'valor_unitario'],
                'currency_code' => ['moeda', 'currency', 'currency_code'],
                'tax_type' => ['tipo_imposto', 'tax_type', 'imposto'],
                'tax_code' => ['codigo_imposto', 'tax_code'],
                'tax_percentage' => ['taxa_imposto', 'tax_percentage', 'taxa_iva'],
                'tax_exemption_code' => ['motivo_isencao', 'tax_exemption_code', 'codigo_isencao'],
                ...$common,
            ],
        };

        return [$field, ...($aliases[$field] ?? [])];
    }
}
