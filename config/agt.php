<?php

return [
    'default_environment' => env('AGT_DEFAULT_ENVIRONMENT', 'homologation'),
    'schema_version' => env('AGT_SCHEMA_VERSION', '1.2'),

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
            'typ' => 'JOSE',
            'alg' => 'RS256',
        ],
    ],

    /*
     * The partner portal's current Listar Séries signing example contains only
     * taxRegistrationNumber. Keeping the manifest explicit makes any future AGT
     * clarification a versioned contract change instead of a scattered rewrite.
     */
    'operations' => [
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
