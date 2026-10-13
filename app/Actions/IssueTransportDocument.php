<?php

namespace App\Actions;

use App\AgtConnectionStatus;
use App\Billing\SubscriptionAccess;
use App\Exceptions\BillingActionRefused;
use App\Fiscal\Documents\TransportDocumentNumber;
use App\Fiscal\Saft\TransportDocumentSigner;
use App\Models\AgtConnection;
use App\Models\TransportDocument;
use App\Models\TransportDocumentSequence;
use App\Models\User;
use App\SubscriptionFeature;
use App\TransportDocumentStatus;
use App\TransportDocumentType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class IssueTransportDocument
{
    public function __construct(
        private TransportDocumentNumber $documentNumber,
        private TransportDocumentSigner $signer,
    ) {}

    public function execute(
        TransportDocument $draft,
        User $issuer,
        int $expectedRevision,
    ): TransportDocument {
        app(SubscriptionAccess::class)->require($draft->workspace_id, SubscriptionFeature::Transport);

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

            if ($document->document_type === TransportDocumentType::Consignment) {
                throw BillingActionRefused::because('A emissão de guias GC aguarda um esquema SAF-T aceite pela AGT. O esquema actual só aceita GR, GT, GA e GD.');
            }

            if ($document->currency_code !== 'AOA') {
                throw BillingActionRefused::because('As guias devem ser valorizadas em AOA. Configure a moeda da empresa antes de emitir.');
            }
            if (! in_array($document->movement_direction, ['incoming', 'outgoing'], true)) {
                throw BillingActionRefused::because('Indique se a guia acompanha uma entrada ou saída de mercadorias.');
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
                'system_entry_at' => $issuedAt,
                'frozen_at' => $issuedAt,
                'issued_at' => $issuedAt,
            ]);
            $previous = TransportDocument::query()
                ->where('transport_document_sequence_id', $sequence->id)
                ->whereNotNull('document_no')
                ->orderByDesc('issue_sequence')
                ->first();
            $this->signer->sign($document, $previous);
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

        $suffix = (string) config('agt.saft_signing.series_suffix', '');

        if (preg_match('/^[A-Za-z0-9._-]{0,8}$/', $suffix) !== 1 || strlen($code) + 4 + strlen($suffix) > 32) {
            throw BillingActionRefused::because('O código do estabelecimento e o sufixo da série SAF-T excedem o formato permitido.');
        }

        return "{$code}{$year}{$suffix}";
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
}
