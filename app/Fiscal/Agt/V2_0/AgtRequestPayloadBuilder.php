<?php

namespace App\Fiscal\Agt\V2_0;

use App\Fiscal\Agt\Contracts\JwsSigner;
use App\Fiscal\Agt\Exceptions\SigningKeyUnavailable;
use App\Fiscal\Agt\Exceptions\UnsupportedAgtSchema;
use App\Fiscal\Documents\V2_0\FiscalDocumentPayloadBuilder;
use App\FiscalDocumentType;
use App\FiscalSeriesContingency;
use App\Models\AgtConnection;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use Carbon\CarbonInterface;
use DomainException;

final readonly class AgtRequestPayloadBuilder
{
    public function __construct(
        private JwsSigner $jwsSigner,
        private FiscalDocumentPayloadBuilder $documentPayloadBuilder,
    ) {}

    /** @return array<string, mixed> */
    public function registerInvoice(
        AgtConnection $connection,
        FiscalDocument $document,
        string $submissionUuid,
        CarbonInterface $submittedAt,
    ): array {
        UnsupportedAgtSchema::assertSupported($connection->schema_version);
        UnsupportedAgtSchema::assertSupported($document->payload_schema_version);

        if (blank($document->document_jws)) {
            throw new SigningKeyUnavailable('O documento final ainda não possui assinatura JWS.');
        }

        return [
            'schemaVersion' => $connection->schema_version,
            'submissionUUID' => $submissionUuid,
            'taxRegistrationNumber' => (string) $document->legalEntity->tax_identification_number,
            'submissionTimeStamp' => $submittedAt->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
            'softwareInfo' => $this->softwareInfo($connection),
            'numberOfEntries' => 1,
            'documents' => [[
                ...$this->documentPayloadBuilder->document($document),
                'jwsDocumentSignature' => $document->document_jws,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    public function invoiceStatus(
        AgtConnection $connection,
        LegalEntity $legalEntity,
        string $requestId,
        string $submissionUuid,
        CarbonInterface $submittedAt,
    ): array {
        UnsupportedAgtSchema::assertSupported($connection->schema_version);

        $signaturePayload = [
            'taxRegistrationNumber' => (string) $legalEntity->tax_identification_number,
            'requestID' => $requestId,
        ];

        return [
            'schemaVersion' => $connection->schema_version,
            'submissionUUID' => $submissionUuid,
            'taxRegistrationNumber' => (string) $legalEntity->tax_identification_number,
            'submissionTimeStamp' => $submittedAt->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
            'softwareInfo' => $this->softwareInfo($connection),
            'requestID' => $requestId,
            'jwsSignature' => $this->jwsSigner->sign(
                $signaturePayload,
                $this->taxpayerKeyReference($connection),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function listSeries(
        AgtConnection $connection,
        LegalEntity $legalEntity,
        CarbonInterface $submittedAt,
    ): array {
        UnsupportedAgtSchema::assertSupported($connection->schema_version);

        $signaturePayload = [
            'taxRegistrationNumber' => (string) $legalEntity->tax_identification_number,
        ];

        return [
            'schemaVersion' => $connection->schema_version,
            'taxRegistrationNumber' => (string) $legalEntity->tax_identification_number,
            'submissionTimeStamp' => $submittedAt->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
            'seriesYear' => (int) $submittedAt->clone()->timezone('Africa/Luanda')->format('Y'),
            'establishmentNumber' => (string) $connection->establishment_number,
            'jwsSignature' => $this->jwsSigner->sign(
                $signaturePayload,
                $this->taxpayerKeyReference($connection),
            ),
            'softwareInfo' => $this->softwareInfo($connection),
        ];
    }

    /** @return array<string, mixed> */
    public function requestSeries(
        AgtConnection $connection,
        LegalEntity $legalEntity,
        FiscalDocumentType $documentType,
        int $seriesYear,
        FiscalSeriesContingency $contingency,
        string $submissionUuid,
        CarbonInterface $submittedAt,
    ): array {
        UnsupportedAgtSchema::assertSupported($connection->schema_version);

        $signaturePayload = [
            'taxRegistrationNumber' => (string) $legalEntity->tax_identification_number,
            'seriesYear' => $seriesYear,
            'documentType' => $documentType->value,
            'establishmentNumber' => (string) $connection->establishment_number,
            'seriesContingencyIndicator' => $contingency->value,
        ];

        return [
            'schemaVersion' => $connection->schema_version,
            'submissionUUID' => $submissionUuid,
            'taxRegistrationNumber' => (string) $legalEntity->tax_identification_number,
            'submissionTimeStamp' => $submittedAt->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
            'softwareInfo' => $this->softwareInfo($connection),
            'seriesYear' => $seriesYear,
            'documentType' => $documentType->value,
            'establishmentNumber' => (string) $connection->establishment_number,
            'jwsSignature' => $this->jwsSigner->sign(
                $signaturePayload,
                $this->taxpayerKeyReference($connection),
            ),
            'seriesContingencyIndicator' => $contingency->value,
        ];
    }

    /** @return array<string, mixed> */
    private function softwareInfo(AgtConnection $connection): array
    {
        $signatureVersion = config('agt.software.signature_version');

        if (! is_int($signatureVersion) || $signatureVersion < 1) {
            throw new DomainException('Configure a versão positiva da chave de assinatura registada na AGT.');
        }

        $detail = [
            'productId' => (string) $connection->product_id,
            'productVersion' => (string) $connection->product_version,
            'softwareValidationNumber' => (string) $connection->software_validation_number,
            'signatureVersion' => $signatureVersion,
        ];

        return [
            'softwareInfoDetail' => $detail,
            'jwsSoftwareSignature' => $this->jwsSigner->sign(
                $detail,
                $this->softwareKeyReference($connection),
            ),
        ];
    }

    private function softwareKeyReference(AgtConnection $connection): string
    {
        if (blank($connection->software_key_reference)) {
            throw new SigningKeyUnavailable('A referência da chave do software não está configurada.');
        }

        return (string) $connection->software_key_reference;
    }

    private function taxpayerKeyReference(AgtConnection $connection): string
    {
        if (blank($connection->taxpayer_key_reference)) {
            throw new SigningKeyUnavailable('A referência da chave do contribuinte não está configurada.');
        }

        return (string) $connection->taxpayer_key_reference;
    }
}
