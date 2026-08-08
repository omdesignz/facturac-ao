<?php

namespace App\Actions;

use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\PriceList;
use App\Models\Quote;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Everything a company has put into the app, in a form that opens elsewhere.
 *
 * CSV per table rather than one blob of JSON: the people who ask for this are
 * usually moving to another system or handing books to an accountant, and both
 * open a spreadsheet. A JSON dump is honest but useless to them.
 *
 * The archive holds no keys, no password hashes and no AGT credentials — the
 * point is the records, and shipping the means to impersonate the company
 * alongside them would make the export the weakest link in the account.
 */
class ExportWorkspaceData
{
    /** Where finished archives live, relative to the local disk. */
    public const DIRECTORY = 'exports';

    /**
     * Writes the archive and returns its path on the local disk.
     */
    public function execute(Workspace $workspace, User $requestedBy): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIRECTORY);

        $name = sprintf(
            '%s/%s-%s.zip',
            self::DIRECTORY,
            $workspace->public_id,
            now()->format('Ymd-His'),
        );

        $path = $disk->path($name);
        $archive = new ZipArchive;

        if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível preparar o ficheiro de exportação.');
        }

        $archive->addFromString('LEIA-ME.txt', $this->readme($workspace, $requestedBy));

        foreach ($this->tables($workspace) as $file => $rows) {
            $archive->addFromString($file, $this->csv($rows));
        }

        $archive->close();

        return $name;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function tables(Workspace $workspace): array
    {
        /** @var list<int> $entityIds */
        $entityIds = array_values($workspace->legalEntities()->pluck('id')->all());

        return [
            'estabelecimentos.csv' => $this->rows(
                Establishment::query()->whereIn('legal_entity_id', $entityIds)->get(),
                fn (Establishment $row): array => [
                    'codigo' => $row->code,
                    'nome' => $row->name,
                    'morada' => $row->address_line,
                    'municipio' => $row->municipality,
                    'provincia' => $row->province_code,
                    'sede' => $row->is_head_office ? 'sim' : 'nao',
                    'activo' => $row->is_active ? 'sim' : 'nao',
                ],
            ),

            'clientes.csv' => $this->rows(
                Customer::query()->whereIn('legal_entity_id', $entityIds)->get(),
                fn (Customer $row): array => [
                    'nome' => $row->name,
                    'nif' => $row->tax_identification_number,
                    'pais' => $row->country_code,
                    'morada' => $row->address_line,
                    'email' => $row->email,
                    'telefone' => $row->phone,
                    'prazo_dias' => $row->payment_terms_days,
                    'limite_credito' => $this->money($row->credit_limit_minor),
                    'activo' => $row->is_active ? 'sim' : 'nao',
                ],
            ),

            'artigos.csv' => $this->rows(
                CatalogueItem::query()->whereIn('legal_entity_id', $entityIds)->get(),
                fn (CatalogueItem $row): array => [
                    'codigo' => $row->code,
                    'nome' => $row->name,
                    'descricao' => $row->description,
                    'unidade' => $row->unit_of_measure,
                    'preco' => $this->money($row->unit_price_minor),
                    'iva_percentagem' => $row->tax_percentage,
                    'controla_stock' => $row->tracks_stock ? 'sim' : 'nao',
                    'activo' => $row->is_active ? 'sim' : 'nao',
                ],
            ),

            'tabelas-de-precos.csv' => $this->priceRows($entityIds),

            'documentos.csv' => $this->rows(
                FiscalDocument::query()
                    ->whereIn('legal_entity_id', $entityIds)
                    ->with('withholdings')
                    ->orderBy('document_date')
                    ->get(),
                fn (FiscalDocument $row): array => [
                    'numero' => $row->document_no,
                    'tipo' => $row->document_type->value,
                    'estado' => $row->status->value,
                    'data' => $row->document_date->toDateString(),
                    'vencimento' => $row->due_date?->toDateString(),
                    'cliente' => $row->customer_name,
                    'cliente_nif' => $row->customer_tax_identification_number,
                    'moeda' => $row->currency_code,
                    'cambio' => number_format($row->exchange_rate_micro / 1_000_000, 6, '.', ''),
                    'liquido' => $this->money($row->net_total_minor),
                    'imposto' => $this->money($row->tax_payable_minor),
                    'total' => $this->money($row->gross_total_minor),
                    'pago' => $this->money($row->settlement_total_minor),
                    // Kept back by the buyer, so it never arrives as a payment
                    // and would otherwise look like a document left unpaid.
                    'retido_na_fonte' => $this->money((int) $row->withholdings->sum('amount_minor')),
                    'estado_agt' => $row->agt_document_status,
                    'assinatura_sha256' => $row->document_payload_sha256,
                ],
            ),

            'linhas-de-documentos.csv' => $this->documentLineRows($entityIds),

            'orcamentos.csv' => $this->rows(
                Quote::query()->whereIn('legal_entity_id', $entityIds)->get(),
                fn (Quote $row): array => [
                    'referencia' => $row->reference,
                    'estado' => $row->status->value,
                    'data' => $row->issue_date->toDateString(),
                    'valido_ate' => $row->valid_until->toDateString(),
                    'cliente' => $row->customer_name,
                    'total' => $this->money($row->gross_total_minor),
                ],
            ),
        ];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $models
     * @param  callable(TModel): array<string, mixed>  $map
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $models, callable $map): array
    {
        return array_values($models->map($map)->all());
    }

    /**
     * One row per priced article, flattened out of the lists that hold them.
     *
     * @param  list<int>  $entityIds
     * @return list<array<string, mixed>>
     */
    private function priceRows(array $entityIds): array
    {
        $rows = [];

        $lists = PriceList::query()
            ->whereIn('legal_entity_id', $entityIds)
            ->with('items.catalogueItem')
            ->get();

        foreach ($lists as $list) {
            foreach ($list->items as $item) {
                $rows[] = [
                    'tabela' => $list->name,
                    'artigo' => $item->catalogueItem->code,
                    'preco' => $this->money($item->unit_price_minor),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<int>  $entityIds
     * @return list<array<string, mixed>>
     */
    private function documentLineRows(array $entityIds): array
    {
        $rows = [];

        $documents = FiscalDocument::query()
            ->whereIn('legal_entity_id', $entityIds)
            ->with('lines')
            ->orderBy('document_date')
            ->get();

        foreach ($documents as $document) {
            foreach ($document->lines as $line) {
                $rows[] = [
                    'documento' => $document->document_no,
                    'linha' => $line->line_number,
                    'codigo' => $line->product_code,
                    'descricao' => $line->product_description,
                    'quantidade' => $line->quantity_units / (10 ** $line->quantity_scale),
                    'unidade' => $line->unit_of_measure,
                    'preco_unitario' => $this->money($line->unit_price_base_minor),
                    'liquido' => $this->money($line->net_amount_minor),
                    'imposto' => $this->money($line->tax_amount_minor),
                    'total' => $this->money($line->gross_amount_minor),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function csv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Não foi possível escrever o ficheiro de exportação.');
        }

        // A BOM, because Excel on Windows otherwise reads "Consultoria" as
        // "ConsultoriaÂ" and the whole point was that this opens elsewhere.
        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, array_keys($rows[0]), ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (mixed $value): string => $value === null ? '' : (string) $value,
                $row,
            ), ',', '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function money(?int $minor): string
    {
        return $minor === null ? '' : number_format($minor / 100, 2, '.', '');
    }

    private function readme(Workspace $workspace, User $requestedBy): string
    {
        $generated = now()->toDayDateTimeString();

        return <<<TXT
        Exportação de dados — {$workspace->name}
        Pedida por {$requestedBy->name} em {$generated}.

        Cada ficheiro .csv abre no Excel, no LibreOffice ou em qualquer folha
        de cálculo. Os valores monetários estão em unidades inteiras da moeda
        com duas casas decimais (por exemplo 1250.00).

        O que NÃO vai aqui, de propósito:
          · palavras-passe e chaves de acesso;
          · credenciais e certificados da AGT;
          · dados de outras empresas a que a sua conta tenha acesso.

        documentos.csv inclui a assinatura SHA-256 de cada documento emitido.
        É com ela que se confirma que um documento exportado é o mesmo que foi
        comunicado à AGT.
        TXT;
    }
}
