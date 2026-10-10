<?php

namespace App\Fiscal\Saft;

use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\LegalEntity;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\TransportDocument;
use App\Models\TransportDocumentLine;
use App\TransportDocumentType;
use Carbon\CarbonImmutable;

final class SaftExportSummary
{
    /** @return array<string, mixed> */
    public function for(
        LegalEntity $legalEntity,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?Establishment $establishment = null,
    ): array {
        $fiscal = FiscalDocument::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->when(
                $establishment instanceof Establishment,
                fn ($query) => $query->where('establishment_id', $establishment->id),
            )
            ->whereNotNull('document_no')
            ->whereBetween('document_date', [$from->toDateString(), $to->toDateString()]);
        $movement = TransportDocument::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->when(
                $establishment instanceof Establishment,
                fn ($query) => $query->where('establishment_id', $establishment->id),
            )
            ->whereNotNull('document_no')
            ->whereBetween('movement_date', [$from->toDateString(), $to->toDateString()]);
        $quotes = Quote::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->when(
                $establishment instanceof Establishment,
                fn ($query) => $query->where('establishment_id', $establishment->id),
            )
            ->whereNotNull('sent_at')
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()]);

        $paymentTypes = ['RC', 'RG', 'AR'];
        $payments = (clone $fiscal)->whereIn('document_type', $paymentTypes)->count();
        $invoices = (clone $fiscal)->whereNotIn('document_type', $paymentTypes)->count();
        $incompleteDocuments = (clone $fiscal)
            ->whereNotIn('document_type', $paymentTypes)
            ->whereDoesntHave('lines')
            ->pluck('document_no')
            ->merge((clone $fiscal)
                ->whereIn('document_type', $paymentTypes)
                ->whereDoesntHave('settlements')
                ->pluck('document_no'))
            ->merge((clone $movement)->whereDoesntHave('lines')->pluck('document_no'))
            ->merge((clone $quotes)->whereDoesntHave('lines')->pluck('reference'))
            ->filter()
            ->values();
        $incompleteDocumentCount = $incompleteDocuments->count();
        $incompleteDocumentSample = $incompleteDocuments->take(5)->implode(', ')
            .($incompleteDocumentCount > 5 ? ' e mais' : '');
        $customerCount = collect($legalEntity->customers()->pluck('public_id'))
            ->merge((clone $fiscal)->whereNull('customer_id')->pluck('public_id')->map(
                fn (string $publicId): string => "F{$publicId}",
            ))
            ->merge((clone $movement)
                ->where('document_type', '!=', TransportDocumentType::ReturnNote->value)
                ->whereNull('customer_id')
                ->pluck('public_id')
                ->map(fn (string $publicId): string => "T{$publicId}"))
            ->merge((clone $quotes)->whereNull('customer_id')->pluck('public_id')->map(
                fn (string $publicId): string => "Q{$publicId}",
            ))
            ->unique()
            ->count();
        $supplierCount = (clone $movement)
            ->where('document_type', TransportDocumentType::ReturnNote->value)
            ->pluck('recipient_tax_identification_number')
            ->map(fn (string $taxId): string => mb_strtoupper(trim($taxId)))
            ->unique()
            ->count();
        $productCodes = collect($legalEntity->catalogueItems()->pluck('code'))
            ->merge(FiscalDocumentLine::query()
                ->whereIn(
                    'fiscal_document_id',
                    (clone $fiscal)->whereNotIn('document_type', $paymentTypes)->select('id'),
                )
                ->pluck('product_code'))
            ->merge(TransportDocumentLine::query()
                ->whereIn('transport_document_id', (clone $movement)->select('id'))
                ->pluck('product_code'))
            ->merge(QuoteLine::query()
                ->whereIn('quote_id', (clone $quotes)->select('id'))
                ->get(['quote_id', 'line_number', 'product_code'])
                ->map(fn (QuoteLine $line): string => filled($line->product_code)
                    ? (string) $line->product_code
                    : "Q{$line->quote_id}-{$line->line_number}"))
            ->unique()
            ->count();
        $connection = $legalEntity->agtConnections()->latest('id')->first();
        $producerTaxId = trim((string) config('agt.software.company_tax_id'));
        $validationNumber = trim((string) $connection?->software_validation_number);
        $hasValidValidationNumber = preg_match(
            '/^\\d+\/AGT\/\\d{4}$/',
            $validationNumber,
        ) === 1;
        $readiness = [
            [
                'key' => 'records',
                'label' => 'Integridade dos documentos',
                'ready' => $incompleteDocumentCount === 0,
                'blocking' => true,
                'detail' => $incompleteDocumentCount === 0
                    ? 'Todos os documentos numerados têm os detalhes exigidos pelo esquema.'
                    : "{$incompleteDocumentCount} documento(s) numerado(s) não têm linhas ou liquidações: {$incompleteDocumentSample}. Exigem reparação controlada e não serão omitidos do SAF-T.",
            ],
            [
                'key' => 'company',
                'label' => 'Identidade fiscal da empresa',
                'ready' => filled($legalEntity->tax_identification_number),
                'blocking' => false,
                'detail' => filled($legalEntity->tax_identification_number)
                    ? 'NIF e razão social disponíveis no cabeçalho.'
                    : 'Conclua a identidade fiscal antes de entregar o ficheiro.',
            ],
            [
                'key' => 'producer',
                'label' => 'NIF do produtor do software',
                'ready' => $producerTaxId !== '',
                'blocking' => false,
                'detail' => $producerTaxId !== ''
                    ? 'Produtor do software identificado.'
                    : 'Defina AGT_SOFTWARE_COMPANY_TAX_ID antes da entrega oficial.',
            ],
            [
                'key' => 'validation',
                'label' => 'Número de validação do software',
                'ready' => $hasValidValidationNumber,
                'blocking' => false,
                'detail' => $hasValidValidationNumber
                    ? "Validação {$validationNumber}."
                    : 'Use o formato n.º/AGT/ano; durante a homologação o SAF-T indica software ainda não validado.',
            ],
            [
                'key' => 'addresses',
                'label' => 'Morada do estabelecimento',
                'ready' => filled(($establishment
                    ?? $legalEntity->establishments()->where('is_head_office', true)->first())?->address_line),
                'blocking' => false,
                'detail' => 'A morada alimenta o cabeçalho e os locais de carga.',
            ],
        ];

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'establishment' => $establishment?->public_id,
                'scope_label' => $establishment->name ?? 'Todos os estabelecimentos',
            ],
            'counts' => [
                'sales_invoices' => $invoices,
                'movement_of_goods' => $movement->count(),
                'working_documents' => $quotes->count(),
                'payments' => $payments,
                'customers' => $customerCount,
                'suppliers' => $supplierCount,
                'products' => $productCodes,
            ],
            'readiness' => $readiness,
            'ready' => collect($readiness)->every(fn (array $check): bool => $check['ready']),
            'exportable' => $incompleteDocumentCount === 0,
        ];
    }
}
