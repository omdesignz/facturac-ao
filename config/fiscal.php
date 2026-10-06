<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Record retention
    |--------------------------------------------------------------------------
    |
    | How long issued fiscal documents must be kept. Angola's Código Geral
    | Tributário puts the general obligation at five years counted from the end
    | of the year the document belongs to, and the application refuses to
    | destroy anything still inside that window — including on the owner's own
    | request, because the obligation is not theirs to waive.
    |
    */

    'retention_years' => (int) env('FISCAL_RETENTION_YEARS', 5),

    /*
    |--------------------------------------------------------------------------
    | SAF-T (AO)
    |--------------------------------------------------------------------------
    |
    | The audit file the AGT asks for. The version is the schema the export is
    | written against; the country region is fixed for Angola and lives here so
    | it is stated once rather than repeated through the builder.
    |
    */

    'saft' => [
        'version' => env('SAFT_VERSION', '1.01_01'),
        'tax_country_region' => 'AO',
        'currency_code' => 'AOA',
        'namespace' => 'urn:OECD:StandardAuditFile-Tax:AO_1.01_01',
        'schema_path' => resource_path('saft/SAFTAO1.01_01.xsd.gz.b64'),
        'schema_sha256' => 'e9a938e1f47ac3d84ffbb26d0d95b827fc769a065c9d20533d0262c12f8c2631',
    ],

    /*
    |--------------------------------------------------------------------------
    | Currencies a document may be written in
    |--------------------------------------------------------------------------
    |
    | The kwanza first, because almost every document is in it and it is the
    | only one that needs no exchange rate. The rest are the currencies Angolan
    | companies actually invoice in; anything outside this list is refused
    | rather than accepted and filed as an unrecognised code.
    |
    */

    'currencies' => ['AOA', 'USD', 'EUR', 'ZAR', 'GBP', 'BRL', 'CNY', 'NAD'],

    /*
    |--------------------------------------------------------------------------
    | Printed documents
    |--------------------------------------------------------------------------
    |
    | The AGT expects a printed document to carry the validated-program notice
    | and enough of the signature to be checked against the record. The logo box
    | is the space a company's mark is fitted into without pushing the fiscal
    | fields out of the header.
    |
    */

    'print' => [
        /*
         * Where each issued document's PDF is kept, as issued. Private, and
         * part of the backup: these are records, not a cache that can be
         * rebuilt, because a re-render may not match what the customer got.
         */
        'archive_disk' => env('FISCAL_ARCHIVE_DISK', 'local'),
        'archive_directory' => 'fiscal-documents',
        'paper' => 'A4',
        'logo_max_width_mm' => 45,
        'logo_max_height_mm' => 18,
        'digest_characters' => 8,
    ],

];
