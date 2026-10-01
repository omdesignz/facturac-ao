<?php

use App\Fiscal\Agt\Contracts\JwsSigner;
use App\Fiscal\Agt\V1_2\AgtRequestPayloadBuilder;
use App\Fiscal\Calculation\FiscalCalculator;
use App\Fiscal\Documents\V1_2\FiscalDocumentPayloadBuilder;
use App\FiscalDocumentType;
use App\FiscalSeriesContingency;
use App\Models\AgtConnection;
use App\Models\LegalEntity;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

function phaseFourPayloadSigner(): JwsSigner
{
    return new class implements JwsSigner
    {
        public function sign(array $payload, string $keyReference): string
        {
            return 'signed:'.$keyReference.':'.json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        }

        public function fingerprint(string $keyReference): string
        {
            return hash('sha256', $keyReference);
        }
    };
}

function phaseFourPayloadConnection(): AgtConnection
{
    return new AgtConnection([
        'schema_version' => '1.2',
        'establishment_number' => 'AO-LAD-001',
        'product_id' => 'facturac.ao',
        'product_version' => '0.5.0',
        'software_validation_number' => 'AGT-SW-2026',
        'software_key_reference' => 'software/vap',
        'taxpayer_key_reference' => 'taxpayer/003043408LA033-hml',
    ]);
}

test('the list series request signs only the official taxpayer field set', function () {
    $builder = new AgtRequestPayloadBuilder(
        phaseFourPayloadSigner(),
        new FiscalDocumentPayloadBuilder(new FiscalCalculator),
    );
    $legalEntity = new LegalEntity([
        'tax_identification_number' => '003043408LA033',
    ]);
    $payload = $builder->listSeries(
        phaseFourPayloadConnection(),
        $legalEntity,
        CarbonImmutable::parse('2026-08-02 08:30:45', 'Africa/Luanda'),
    );

    expect($payload)->toMatchArray([
        'schemaVersion' => '1.2',
        'taxRegistrationNumber' => '003043408LA033',
        'submissionTimeStamp' => '2026-08-02T07:30:45Z',
        'seriesYear' => '2026',
        'establishmentNumber' => 'AO-LAD-001',
    ])
        ->and($payload['jwsSignature'])
        ->toBe('signed:taxpayer/003043408LA033-hml:{"taxRegistrationNumber":"003043408LA033"}')
        ->and(data_get($payload, 'softwareInfo.softwareInfoDetail'))
        ->toBe([
            'productId' => 'facturac.ao',
            'productVersion' => '0.5.0',
            'softwareValidationNumber' => 'AGT-SW-2026',
        ])
        ->and(data_get($payload, 'softwareInfo.jwsSoftwareSignature'))
        ->toBe('signed:software/vap:{"productId":"facturac.ao","productVersion":"0.5.0","softwareValidationNumber":"AGT-SW-2026"}');
});

test('the series request signs the exact current AGT field set', function () {
    $builder = new AgtRequestPayloadBuilder(
        phaseFourPayloadSigner(),
        new FiscalDocumentPayloadBuilder(new FiscalCalculator),
    );
    $legalEntity = new LegalEntity([
        'tax_identification_number' => '003043408LA033',
    ]);
    $payload = $builder->requestSeries(
        phaseFourPayloadConnection(),
        $legalEntity,
        FiscalDocumentType::InvoiceReceipt,
        2026,
        FiscalSeriesContingency::Normal,
        '80f267e1-e1d3-44d0-bde6-6b94a7b7a9af',
        CarbonImmutable::parse('2026-08-02 08:30:45', 'Africa/Luanda'),
    );

    expect($payload)->toMatchArray([
        'schemaVersion' => '1.2',
        'submissionUUID' => '80f267e1-e1d3-44d0-bde6-6b94a7b7a9af',
        'taxRegistrationNumber' => '003043408LA033',
        'submissionTimeStamp' => '2026-08-02T07:30:45Z',
        'seriesYear' => '2026',
        'documentType' => 'FR',
        'establishmentNumber' => 'AO-LAD-001',
        'seriesContingencyIndicator' => 'N',
    ])
        ->and($payload['jwsSignature'])
        ->toBe('signed:taxpayer/003043408LA033-hml:{"taxRegistrationNumber":"003043408LA033","seriesYear":"2026","documentType":"FR","establishmentNumber":"AO-LAD-001","seriesContingencyIndicator":"N"}');
});

test('the status request signs the taxpayer and AGT request identifiers exactly', function () {
    $builder = new AgtRequestPayloadBuilder(
        phaseFourPayloadSigner(),
        new FiscalDocumentPayloadBuilder(new FiscalCalculator),
    );
    $legalEntity = new LegalEntity([
        'tax_identification_number' => '003043408LA033',
    ]);
    $payload = $builder->invoiceStatus(
        phaseFourPayloadConnection(),
        $legalEntity,
        '123456789012345',
        '80f267e1-e1d3-44d0-bde6-6b94a7b7a9af',
        CarbonImmutable::parse('2026-08-02 12:00:00', 'UTC'),
    );

    expect($payload)->toMatchArray([
        'schemaVersion' => '1.2',
        'submissionUUID' => '80f267e1-e1d3-44d0-bde6-6b94a7b7a9af',
        'taxRegistrationNumber' => '003043408LA033',
        'submissionTimeStamp' => '2026-08-02T12:00:00Z',
        'requestID' => '123456789012345',
    ])
        ->and($payload['jwsSignature'])
        ->toBe('signed:taxpayer/003043408LA033-hml:{"taxRegistrationNumber":"003043408LA033","requestID":"123456789012345"}');
});
