<?php

namespace App\Analytics;

use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TransactionWorkbook
{
    public function __construct(private TransactionReport $report) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<int>|null  $documentIds
     * @param  (callable(int): void)|null  $progress
     */
    public function build(LegalEntity $company, array $filters, ?array $documentIds = null, ?callable $progress = null): Spreadsheet
    {
        $documentIds ??= $this->report->documentIds($company, $filters);
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($limit !== -1 && $limit < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('facturac.ao')->setTitle($filters['title'])->setCompany($company->legal_name);
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $summary = $book->getActiveSheet()->setTitle('Resumo');
        $transactions = $book->createSheet()->setTitle('Transacções');
        $months = $book->createSheet()->setTitle('Por mês');
        $columns = [];
        foreach ($filters['columns'] as $key) {
            $columns[$key] = TransactionReport::columns()[$key];
        }
        $this->heading($transactions, $company, $filters, $columns);
        $summaryColumns = $this->definitions([
            ['Grupo', 'text', 30], ['Moeda', 'text', 12], ['Documentos', 'number', 16],
            ['Facturado após NC', 'money', 25], ['Pagamentos nos documentos', 'money', 30],
        ]);
        $this->heading($summary, $company, $filters, $summaryColumns);
        $this->heading($months, $company, $filters, $summaryColumns);
        $items = ($filters['include_lines'] ?? false) ? $book->createSheet()->setTitle('Artigos') : null;
        $payments = ($filters['include_payments'] ?? false) ? $book->createSheet()->setTitle('Liquidações') : null;
        $itemColumns = $this->definitions([
            ['Documento', 'text', 25], ['Data', 'date', 15], ['Cliente', 'text', 35], ['Moeda', 'text', 12],
            ['Linha', 'number', 10], ['Código', 'text', 22], ['Descrição', 'text', 40],
            ['Quantidade', 'quantity', 17], ['Unidade', 'text', 12], ['Preço unitário', 'quantity', 22],
            ['Desconto', 'money', 18], ['Valor sem imposto', 'money', 22], ['Impostos', 'money', 18],
            ['Total', 'money', 20], ['Tratamento fiscal', 'text', 35],
        ]);
        $paymentColumns = $this->definitions([
            ['Recibo', 'text', 25], ['Data de emissão', 'date', 18], ['Data de pagamento', 'date', 21],
            ['Cliente', 'text', 35], ['Moeda', 'text', 12], ['Documento liquidado', 'text', 26],
            ['Valor liquidado', 'money', 22], ['Meio de pagamento', 'text', 30],
        ]);
        if ($items) {
            $this->heading($items, $company, $filters, $itemColumns);
        }
        if ($payments) {
            $this->heading($payments, $company, $filters, $paymentColumns);
        }
        $query = $this->report->documents(FiscalDocument::query()->where('workspace_id', $company->workspace_id)->where('legal_entity_id', $company->id)->whereIn('id', $documentIds));
        if ($items) {
            $query->with('lines.taxes');
        }
        if ($payments) {
            $query->with('settlements');
        }
        $rowNumber = $itemNumber = $paymentNumber = 7;
        $groups = $monthly = [];
        foreach ($query->lazy(50) as $document) {
            if ($progress !== null) {
                $progress($rowNumber - 7);
            }
            if ($rowNumber - 6 > TransactionReport::MAX_DOCUMENTS) {
                throw ValidationException::withMessages(['from' => 'Reduza o período: limite de 5 000 documentos.']);
            }
            $row = $this->report->row($document);
            $this->writeRow($transactions, $rowNumber++, array_map(fn (string $key): mixed => $row[$key], array_keys($columns)), array_values($columns));
            $this->aggregate($groups, $document->document_type->label(), $row);
            $this->aggregate($monthly, substr((string) $row['document_date'], 0, 7), $row);
            if ($items) {
                foreach ($document->lines as $line) {
                    $this->assertDetailSize($itemNumber);
                    $this->writeRow($items, $itemNumber++, [
                        $document->document_no, $row['document_date'], $row['customer_name'], $row['currency_code'],
                        $line->line_number, $line->product_code, $line->product_description,
                        $line->quantity_units / 10 ** $line->quantity_scale, $line->unit_of_measure,
                        $line->unit_price_micros / 100_000_000, $line->settlement_amount_minor / 100,
                        $line->net_amount_minor / 100, $line->tax_amount_minor / 100, $line->gross_amount_minor / 100,
                        $line->taxes->map(fn ($tax): string => $tax->tax_type->value.' '.$tax->tax_code.' '.($tax->tax_rate_basis_points / 100).'%'.($tax->tax_exemption_code ? ' '.$tax->tax_exemption_code : ''))->implode('; '),
                    ], $itemColumns);
                }
            }
            if ($payments) {
                foreach ($document->settlements as $settlement) {
                    $this->assertDetailSize($paymentNumber);
                    $this->writeRow($payments, $paymentNumber++, [
                        $document->document_no, $row['document_date'], $row['payment_date'], $row['customer_name'],
                        $row['currency_code'], $settlement->settled_document_no, $settlement->amount_minor / 100, $row['payment_method'],
                    ], $paymentColumns);
                }
            }
        }
        if ($rowNumber - 7 !== count($documentIds)) {
            throw ValidationException::withMessages(['from' => 'A selecção de documentos mudou. Crie uma nova exportação.']);
        }
        if ($progress !== null) {
            $progress($rowNumber - 7);
        }
        foreach ([[$summary, $groups], [$months, $monthly]] as [$sheet, $entries]) {
            ksort($entries);
            $index = 7;
            foreach ($entries as $entry) {
                $this->writeRow($sheet, $index++, [$entry['label'], $entry['currency'], $entry['count'], $entry['billing'] / 100, $entry['received'] / 100], $summaryColumns);
            }
            $this->finish($sheet, $index - 1, 5);
        }
        $this->finish($transactions, $rowNumber - 1, count($columns));
        if ($items) {
            $this->finish($items, $itemNumber - 1, count($itemColumns));
        }
        if ($payments) {
            $this->finish($payments, $paymentNumber - 1, count($paymentColumns));
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /** @param array<string, array{label: string, currency: string, count: int, billing: int, received: int}> $groups
     * @param  array<string, string|int|float|null>  $row
     */
    private function aggregate(array &$groups, string $label, array $row): void
    {
        $currency = (string) $row['currency_code'];
        $key = $currency.'|'.$label;
        $groups[$key] ??= ['label' => $label, 'currency' => $currency, 'count' => 0, 'billing' => 0, 'received' => 0];
        $groups[$key]['count']++;
        $groups[$key]['billing'] += (int) round((float) $row['billing'] * 100);
        $groups[$key]['received'] += (int) round((float) $row['received'] * 100);
    }

    private function assertDetailSize(int $row): void
    {
        if ($row - 6 > TransactionReport::MAX_DETAIL_ROWS) {
            throw ValidationException::withMessages(['include_lines' => 'O detalhe excede 20 000 linhas. Reduza o período ou exporte sem detalhe.']);
        }
    }

    /** @param list<array{0: string, 1: string, 2: int}> $columns
     * @return list<array{label: string, format: string, width: int}>
     */
    private function definitions(array $columns): array
    {
        return array_map(fn (array $column): array => ['label' => $column[0], 'format' => $column[1], 'width' => $column[2]], $columns);
    }

    /** @param array<string, mixed> $filters
     * @param  array<array{label: string, format: string, width: int}>  $columns
     */
    private function heading(Worksheet $sheet, LegalEntity $company, array $filters, array $columns): void
    {
        $last = Coordinate::stringFromColumnIndex(count($columns));
        $sheet->setShowGridlines(false);
        $sheet->mergeCells("A1:{$last}1");
        $sheet->setCellValueExplicit('A1', $filters['title'].' · '.$sheet->getTitle(), DataType::TYPE_STRING);
        $sheet->getStyle('A1')->getFont()->setSize(20)->setBold(true)->getColor()->setRGB('173B3A');
        $sheet->getRowDimension(1)->setRowHeight(36);
        $sheet->mergeCells("A2:{$last}2");
        $sheet->setCellValueExplicit('A2', $company->legal_name.' · NIF '.$company->tax_identification_number.' · '.$filters['from'].' a '.$filters['to'], DataType::TYPE_STRING);
        $sheet->mergeCells("A3:{$last}3");
        $sheet->setCellValueExplicit('A3', 'Exportado em '.now('Africa/Luanda')->format('d/m/Y H:i').' (Luanda) · '.$this->filterDescription($company, $filters), DataType::TYPE_STRING);
        $sheet->mergeCells("A4:{$last}4");
        $sheet->setCellValueExplicit('A4', 'Período pela data de emissão; rascunhos excluídos. Facturado inclui impostos: facturas + ND − NC; recibos não duplicam vendas. Saldos actuais. Moedas separadas.', DataType::TYPE_STRING);
        $sheet->getStyle("A2:{$last}4")->getFont()->setSize(10)->getColor()->setRGB('526264');
        $sheet->getStyle("A2:{$last}4")->getAlignment()->setWrapText(true);
        $textWidth = array_sum(array_column($columns, 'width')) * 5.25;
        foreach ([1, 2, 3, 4] as $row) {
            $fontSize = $row === 1 ? 20 : 10;
            $lines = max(1, (int) ceil(mb_strwidth((string) $sheet->getCell('A'.$row)->getValue()) * $fontSize * 0.55 / $textWidth));
            $sheet->getStyle('A'.$row)->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight(max($row === 1 ? 36 : 30, $lines * $fontSize * 1.5));
        }
        foreach (array_values($columns) as $index => $column) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValueExplicit($letter.'6', $column['label'], DataType::TYPE_STRING);
            $sheet->getColumnDimension($letter)->setWidth($column['width']);
        }
        $sheet->getStyle("A6:{$last}6")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '173B3A']],
            'alignment' => ['wrapText' => true, 'vertical' => 'center'],
        ]);
        $sheet->getRowDimension(6)->setRowHeight(34);
        $sheet->freezePane('C7');
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(6, 6);
        $sheet->getHeaderFooter()->setOddFooter('&Lfacturac.ao · Relatório de gestão&R&P / &N');
    }

    /** @param array<string, mixed> $filters */
    private function filterDescription(LegalEntity $company, array $filters): string
    {
        return implode(' · ', array_filter([
            ($filters['types'] ?? []) ? 'Tipos: '.implode(', ', $filters['types']) : 'Todos os tipos',
            ($filters['statuses'] ?? []) ? 'Estados: '.implode(', ', $filters['statuses']) : 'Todos os estados emitidos',
            ($filters['currency'] ?? null) ?: 'Todas as moedas',
            ($filters['customer'] ?? null) ? 'Cliente: '.$filters['customer'] : null,
            ($filters['establishment'] ?? null) ? 'Estabelecimento: '.$company->establishments()->where('public_id', $filters['establishment'])->value('name') : null,
            ($filters['payment_method'] ?? null) ? 'Pagamento: '.$filters['payment_method'] : null,
        ]));
    }

    /** @param list<mixed> $values
     * @param  list<array{label: string, format: string, width: int}>  $columns
     */
    private function writeRow(Worksheet $sheet, int $row, array $values, array $columns): void
    {
        foreach ($values as $index => $value) {
            if ($value === null) {
                continue;
            }
            $cell = Coordinate::stringFromColumnIndex($index + 1).$row;
            $format = $columns[$index]['format'];
            if ($format === 'date') {
                $value = Date::stringToExcel((string) $value);
            }
            $sheet->setCellValueExplicit($cell, $value, $format === 'text' ? DataType::TYPE_STRING : DataType::TYPE_NUMERIC);
            if ($format === 'text') {
                $sheet->getStyle($cell)->getAlignment()->setWrapText(true);
            }
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(match ($format) {
                'date' => 'dd/mm/yyyy', 'money' => '#,##0.00;[Red](#,##0.00);"–"',
                'quantity' => '#,##0.0000', 'number' => '0', default => '@',
            });
        }
    }

    private function finish(Worksheet $sheet, int $lastRow, int $columnCount): void
    {
        $last = Coordinate::stringFromColumnIndex($columnCount);
        if ($lastRow >= 7) {
            $table = new Table("A6:{$last}{$lastRow}", 'Report'.($sheet->getParent()->getIndex($sheet) + 1));
            $table->setStyle((new TableStyle)->setTheme(TableStyle::TABLE_STYLE_MEDIUM2)->setShowRowStripes(true));
            $sheet->addTable($table);
            $sheet->getStyle("A7:{$last}{$lastRow}")->getAlignment()->setVertical('top');
        } else {
            $sheet->setCellValueExplicit('A7', 'Sem movimentos para os filtros seleccionados.', DataType::TYPE_STRING);
        }
        $sheet->getPageSetup()->setPrintArea('A1:'.$last.max(7, $lastRow));
        $sheet->setSelectedCell('A1');
    }
}
