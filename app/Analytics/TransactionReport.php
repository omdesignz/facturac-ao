<?php

namespace App\Analytics;

use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class TransactionReport
{
    public const int MAX_DOCUMENTS = 5000;

    public const int MAX_DETAIL_ROWS = 20000;

    public const array DEFAULT_COLUMNS = ['document_no', 'document_date', 'document_type', 'status', 'customer_name', 'customer_nif', 'currency_code', 'net', 'tax', 'gross', 'billing', 'received', 'outstanding'];

    public function __construct(private ReceivablesQuery $receivables) {}

    /** @return array<string, array{label: string, format: string, width: int}> */
    public static function columns(): array
    {
        $definitions = [
            'document_no' => ['Documento', 'text', 25],
            'document_date' => ['Data', 'date', 15],
            'document_type' => ['Tipo', 'text', 25],
            'status' => ['Estado do documento', 'text', 23],
            'customer_name' => ['Cliente', 'text', 38],
            'customer_nif' => ['NIF do cliente', 'text', 20],
            'customer_address' => ['Morada do cliente', 'text', 40],
            'establishment' => ['Estabelecimento', 'text', 25],
            'currency_code' => ['Moeda', 'text', 12],
            'due_date' => ['Vencimento', 'date', 15],
            'net' => ['Valor sem imposto', 'money', 22],
            'discount' => ['Descontos', 'money', 20],
            'tax' => ['Impostos', 'money', 20],
            'gross' => ['Total do documento', 'money', 24],
            'billing' => ['Facturado após NC', 'money', 24],
            'received' => ['Pagamento neste documento', 'money', 30],
            'paid' => ['Liquidado até à exportação', 'money', 29],
            'credited' => ['Creditado até à exportação', 'money', 29],
            'outstanding' => ['Saldo à data da exportação', 'money', 29],
            'payment_method' => ['Meio de pagamento', 'text', 30],
            'payment_date' => ['Data do pagamento', 'date', 20],
            'reference' => ['Documento de referência', 'text', 27],
            'notes' => ['Observações', 'text', 45],
        ];

        return array_map(fn (array $column): array => ['label' => $column[0], 'format' => $column[1], 'width' => $column[2]], $definitions);
    }

    /** @param array<string, mixed> $filters
     * @return Builder<FiscalDocument>
     */
    public function query(LegalEntity $company, array $filters): Builder
    {
        $query = FiscalDocument::query()->where('workspace_id', $company->workspace_id)
            ->where('legal_entity_id', $company->id)->whereNot('status', FiscalDocumentStatus::Draft)
            ->whereBetween('document_date', [
                CarbonImmutable::parse($filters['from'], 'Africa/Luanda')->startOfDay(),
                CarbonImmutable::parse($filters['to'], 'Africa/Luanda')->endOfDay(),
            ]);
        foreach (['types' => 'document_type', 'statuses' => 'status'] as $filter => $column) {
            if ($filters[$filter] ?? []) {
                $query->whereIn($column, $filters[$filter]);
            }
        }
        foreach (['currency' => 'currency_code', 'payment_method' => 'payment_method'] as $filter => $column) {
            if ($filters[$filter] ?? null) {
                $query->where($column, $filters[$filter]);
            }
        }
        if ($filters['establishment'] ?? null) {
            $query->whereHas('establishment', fn (Builder $q) => $q->where('public_id', $filters['establishment']));
        }
        if ($filters['customer'] ?? null) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['customer']).'%';
            $query->where(fn (Builder $q) => $q->whereRaw("LOWER(customer_name) LIKE LOWER(?) ESCAPE '!'", [$term])
                ->orWhereRaw("LOWER(customer_tax_identification_number) LIKE LOWER(?) ESCAPE '!'", [$term]));
        }

        return $query;
    }

    /** @param array<string, mixed> $filters
     * @return array{count: int, rows: list<array<string, string|int|float|null>>}
     */
    public function preview(LegalEntity $company, array $filters): array
    {
        $query = $this->query($company, $filters);

        return ['count' => $query->count(), 'rows' => array_values($this->documents($query)->limit(10)->get()
            ->map(fn (FiscalDocument $document): array => $this->row($document))->all())];
    }

    /** @param Builder<FiscalDocument> $query
     * @return Builder<FiscalDocument>
     */
    public function documents(Builder $query): Builder
    {
        return $this->receivables->withBalances($query->select([
            'id', 'public_id', 'workspace_id', 'legal_entity_id', 'establishment_id', 'document_no',
            'document_date', 'document_type', 'status', 'customer_name', 'customer_tax_identification_number',
            'customer_address', 'currency_code', 'due_date', 'net_total_minor', 'tax_payable_minor',
            'gross_total_minor', 'settlement_total_minor', 'payment_amount_minor', 'payment_method',
            'payment_date', 'references_document_no', 'notes',
        ]))->with('establishment:id,name')->orderBy('document_date')->orderBy('id');
    }

    /** @param array<string, mixed> $filters
     * @return list<int>
     */
    public function documentIds(LegalEntity $company, array $filters): array
    {
        $ids = $this->query($company, $filters)->limit(self::MAX_DOCUMENTS + 1)->pluck('id');
        if ($ids->count() > self::MAX_DOCUMENTS) {
            throw ValidationException::withMessages(['from' => 'O relatório excede 5 000 documentos. Reduza o período ou aplique mais filtros.']);
        }

        return array_values($ids->map(fn ($id): int => (int) $id)->all());
    }

    /** @return array<string, string|int|float|null> */
    public function row(FiscalDocument $document): array
    {
        $type = $document->document_type;
        $billable = in_array($type->value, BillingDefinition::billableTypes(), true);
        $sign = $type === FiscalDocumentType::CreditNote ? -1 : ($billable ? 1 : 0);
        $received = $type->isPaidOnIssue() || $type === FiscalDocumentType::Receipt ? (int) $document->payment_amount_minor : 0;

        return [
            'document_no' => $document->document_no,
            'document_date' => $document->document_date->toDateString(),
            'document_type' => $type->label(), 'status' => $document->status->label(),
            'customer_name' => $document->customer_name,
            'customer_nif' => $document->customer_tax_identification_number,
            'customer_address' => $document->customer_address,
            'establishment' => $document->establishment->name,
            'currency_code' => $document->currency_code,
            'due_date' => $document->due_date?->toDateString(),
            'net' => $document->net_total_minor / 100, 'discount' => $document->settlement_total_minor / 100,
            'tax' => $document->tax_payable_minor / 100, 'gross' => $document->gross_total_minor / 100,
            'billing' => $sign * $document->gross_total_minor / 100, 'received' => $received / 100,
            'paid' => $billable ? $this->receivables->paidMinor($document) / 100 : null,
            'credited' => $billable ? (int) $document->getAttribute('credited_minor') / 100 : null,
            'outstanding' => $billable ? $this->receivables->outstandingMinor($document) / 100 : null,
            'payment_method' => $document->payment_method?->label(),
            'payment_date' => $document->payment_date?->toDateString(),
            'reference' => $document->references_document_no, 'notes' => $document->notes,
        ];
    }
}
