<?php

return [
    'default_environment' => env('AGT_DEFAULT_ENVIRONMENT', 'homologation'),
    'schema_version' => env('AGT_SCHEMA_VERSION', '2.0'),

    'software' => [
        'product_name' => env('AGT_SOFTWARE_PRODUCT_NAME', 'facturac.ao'),
        'company_name' => env('AGT_SOFTWARE_COMPANY_NAME', 'VAP SOLUÇÕES, LDA'),
        // This is the producer company's NIF, never the representative's NIF.
        'company_tax_id' => env('AGT_SOFTWARE_COMPANY_TAX_ID'),
        'signature_version' => (int) env('AGT_SOFTWARE_SIGNATURE_VERSION', 1),
    ],

    'homologation_fixture' => [
        'alias' => env('AGT_HOMOLOGATION_FIXTURE_ALIAS', 'HML-T01'),
        'tax_identification_number' => env('AGT_HOMOLOGATION_FIXTURE_NIF'),
        'user_email' => env('AGT_HOMOLOGATION_FIXTURE_EMAIL', 'agt.hml-t01@facturac.test'),
        'user_password' => env('AGT_HOMOLOGATION_FIXTURE_PASSWORD'),
    ],

    /*
     * Public simulation identities published by AGT in the partner portal workbook.
     * Private key material remains in the configured signing vault, never in config.
     */
    'homologation_test_identities' => [
        'HML-T01' => [
            'tax_identification_number' => '5000413178',
            'legal_name' => 'NIF TESTE PROJECTO SIGT',
            'taxpayer_key_reference' => 'official-tests/5000413178',
        ],
        'HML-T02' => [
            'tax_identification_number' => '5001441337',
            'legal_name' => 'NIF DE TESTE IIRS - NAO RESIDENTE',
            'taxpayer_key_reference' => 'official-tests/5001441337',
        ],
        'HML-T03' => [
            'tax_identification_number' => '5000471283',
            'legal_name' => 'PROJECTO SIGT - NIF TESTE - DRT',
            'taxpayer_key_reference' => 'official-tests/5000471283',
        ],
        'HML-T04' => [
            'tax_identification_number' => '5000537039',
            'legal_name' => 'SONHE TESTE',
            'taxpayer_key_reference' => 'official-tests/5000537039',
        ],
        'HML-T05' => [
            'tax_identification_number' => '5000930091',
            'legal_name' => 'ESTE PETROLIFERO',
            'taxpayer_key_reference' => 'official-tests/5000930091',
        ],
    ],

    'environments' => [
        'homologation' => [
            'enabled' => true,
            'base_url' => env('AGT_HOMOLOGATION_BASE_URL', 'https://sifphml.minfin.gov.ao/sigt/fe/v1'),
        ],
        'production' => [
            'enabled' => (bool) env('AGT_PRODUCTION_ENABLED', false),
            'base_url' => env('AGT_PRODUCTION_BASE_URL', 'https://sifp.minfin.gov.ao/sigt/fe/v1'),
        ],
    ],

    'transport' => [
        'connect_timeout_seconds' => (int) env('AGT_CONNECT_TIMEOUT', 3),
        'timeout_seconds' => (int) env('AGT_REQUEST_TIMEOUT', 10),
        'poll_interval_seconds' => (int) env('AGT_POLL_INTERVAL', 30),
        'rate_limit_per_minute' => (int) env('AGT_RATE_LIMIT_PER_MINUTE', 30),
    ],

    'signing' => [
        'key_directory' => env('AGT_SIGNING_KEY_DIRECTORY', storage_path('app/private/agt-keys')),
        'minimum_rsa_bits' => 2048,
        'jws_header' => [
            'typ' => 'JWT',
            'alg' => 'RS256',
        ],
    ],

    'qr' => [
        'verification_url' => env(
            'AGT_QR_VERIFICATION_URL',
            'https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe',
        ),
    ],

    /*
     * The partner portal's current Listar Séries signing example contains only
     * taxRegistrationNumber. Keeping the manifest explicit makes any future AGT
     * clarification a versioned contract change instead of a scattered rewrite.
     */
    'operations' => [
        'request_series' => [
            'path' => '/solicitarSerie',
            'request_signature_fields' => [
                'taxRegistrationNumber',
                'seriesYear',
                'documentType',
                'establishmentNumber',
                'seriesContingencyIndicator',
            ],
        ],
        'list_series' => [
            'path' => '/listarSeries',
            'request_signature_fields' => ['taxRegistrationNumber'],
        ],
        'register_invoice' => [
            'path' => '/registarFactura',
            'maximum_documents' => 30,
        ],
        'invoice_status' => [
            'path' => '/obterEstado',
            'request_signature_fields' => ['taxRegistrationNumber', 'requestID'],
        ],
    ],
];
