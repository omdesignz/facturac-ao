<?php

namespace App\Actions;

use App\AgtConnectionStatus;
use App\Exceptions\BillingActionRefused;
use App\Fiscal\Agt\Support\CanonicalJson;
use App\Fiscal\Documents\TransportDocumentNumber;
use App\Models\AgtConnection;
use App\Models\TransportDocument;
use App\Models\TransportDocumentSequence;
use App\Models\User;
use App\TransportDocumentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class IssueTransportDocument
{
    public function __construct(
        private TransportDocumentNumber $documentNumber,
        private CanonicalJson $canonicalJson,
    ) {}

    public function execute(
        TransportDocument $draft,
        User $issuer,
        int $expectedRevision,
    ): TransportDocument {
        return DB::transaction(function () use ($draft, $issuer, $expectedRevision): TransportDocument {
            $document = TransportDocument::query()
                ->with(['lines', 'establishment', 'legalEntity'])
                ->whereKey($draft->id)
                ->where('workspace_id', $draft->workspace_id)
                ->where('legal_entity_id', $draft->legal_entity_id)
                ->lockForUpdate()
                ->firstOrFail();
            $document->ensureMutable();

            if ($document->revision !== $expectedRevision) {
                throw BillingActionRefused::because(
                    'A guia foi alterada noutra sessão. Recarregue a página antes de emitir.',
                );
            }

            if ($document->lines->isEmpty()) {
                throw BillingActionRefused::because('A guia precisa de pelo menos uma linha.');
            }

            $year = $document->movement_date->year;
            $seriesCode = $this->seriesCode($document->establishment->code, $year);
            TransportDocumentSequence::query()->firstOrCreate([
                'workspace_id' => $document->workspace_id,
                'legal_entity_id' => $document->legal_entity_id,
                'establishment_id' => $document->establishment_id,
                'document_type' => $document->document_type,
                'series_code' => $seriesCode,
                'series_year' => $year,
            ], [
                'next_number' => 1,
            ]);
            $sequence = TransportDocumentSequence::query()
                ->where('legal_entity_id', $document->legal_entity_id)
                ->where('establishment_id', $document->establishment_id)
                ->where('document_type', $document->document_type)
                ->where('series_code', $seriesCode)
                ->where('series_year', $year)
                ->lockForUpdate()
                ->firstOrFail();

            if ($sequence->last_movement_date?->isAfter($document->movement_date)) {
                throw BillingActionRefused::because(
                    'A data desta guia é anterior à última guia emitida na mesma série.',
                );
            }

            $issuedAt = now('Africa/Luanda');
            $number = $sequence->next_number;
            $connection = $this->connection($document);
            $document->fill([
                'transport_document_sequence_id' => $sequence->id,
                'issued_by_user_id' => $issuer->id,
                'updated_by_user_id' => $issuer->id,
                'status' => TransportDocumentStatus::Issued,
                'document_no' => $this->documentNumber->compose(
                    $document->document_type,
                    $seriesCode,
                    $number,
                ),
                'issue_sequence' => $number,
                'software_product_id' => $connection->product_id
                    ?? $this->softwareProductId(),
                'software_product_version' => $connection->product_version
                    ?? (string) config('app.version', '1.0'),
                'software_validation_number' => $connection?->software_validation_number,
                'hash_control' => preg_match(
                    '/^\\d+\/AGT\/\\d{4}$/',
                    trim((string) $connection?->software_validation_number),
                ) === 1 ? '1' : '0',
                'system_entry_at' => $issuedAt,
                'frozen_at' => $issuedAt,
                'issued_at' => $issuedAt,
            ]);
            $document->document_hash = hash(
                'sha256',
                $this->canonicalJson->encode($this->hashPayload($document)),
            );
            $document->save();

            $sequence->forceFill([
                'next_number' => $number + 1,
                'last_issued_number' => $number,
                'last_movement_date' => $document->movement_date,
            ])->save();

            activity('transport-document')
                ->causedBy($issuer)
                ->performedOn($document)
                ->event('issued')
                ->withProperties([
                    'document_no' => $document->document_no,
                    'document_hash' => $document->document_hash,
                    'series_code' => $seriesCode,
                    'issue_sequence' => $number,
                ])
                ->log('transport document issued');

            return $document->load(['lines', 'establishment', 'customer', 'legalEntity']);
        }, 5);
    }

    private function seriesCode(string $establishmentCode, int $year): string
    {
        $code = Str::upper((string) Str::of($establishmentCode)->ascii()->replaceMatches('/[^A-Z0-9._-]+/i', ''));
        $code = $code === '' ? 'MOV' : $code;

        return Str::limit("{$code}{$year}", 32, '');
    }

    private function connection(TransportDocument $document): ?AgtConnection
    {
        return AgtConnection::query()
            ->where('legal_entity_id', $document->legal_entity_id)
            ->whereIn('status', [
                AgtConnectionStatus::Verified,
                AgtConnectionStatus::Ready,
            ])
            ->orderByDesc('verified_at')
            ->latest('id')
            ->first();
    }

    private function softwareProductId(): string
    {
        $product = trim((string) config('agt.software.product_name'));
        $company = trim((string) config('agt.software.company_name'));

        return ($product === '' ? (string) config('app.name') : $product)
            .'/'.($company === '' ? 'VAP SOLUÇÕES, LDA' : $company);
    }

    /** @return array<string, mixed> */
    private function hashPayload(TransportDocument $document): array
    {
        return [
            'documentNo' => $document->document_no,
            'movementDate' => $document->movement_date->toDateString(),
            'movementStartAt' => $document->movement_start_at->format('Y-m-d\TH:i:s'),
            'systemEntryAt' => $document->system_entry_at?->format('Y-m-d\TH:i:s'),
            'recipientTaxID' => $document->recipient_tax_identification_number,
            'origin' => $document->origin_address,
            'destination' => $document->destination_address,
            'grossTotalMinor' => $document->gross_total_minor,
            'lines' => $document->lines->map(fn ($line): array => [
                'number' => $line->line_number,
                'code' => $line->product_code,
                'description' => $line->product_description,
                'quantityUnits' => $line->quantity_units,
                'quantityScale' => $line->quantity_scale,
                'unitPriceMinor' => $line->unit_price_minor,
            ])->values()->all(),
        ];
    }
}
