<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform contact and legal identity
    |--------------------------------------------------------------------------
    |
    | Defaults for everything a customer might need in order to reach us, or to
    | know who is processing their data. Every key here can be overridden from
    | the support console without a deploy; these are only the fallbacks.
    |
    */

    'settings' => [
        'support_email' => env('PLATFORM_SUPPORT_EMAIL', 'apoio@vapsolucoes.ao'),
        'support_phone' => env('PLATFORM_SUPPORT_PHONE', '+244 923 000 000'),
        'support_whatsapp' => env('PLATFORM_SUPPORT_WHATSAPP', '+244 923 000 000'),
        'support_hours' => 'Segunda a sexta, das 8h30 às 17h30 (WAT)',
        'complaints_email' => env('PLATFORM_COMPLAINTS_EMAIL', 'reclamacoes@vapsolucoes.ao'),

        /** Working days we commit to a first reply on a complaint. */
        'complaints_response_days' => '5',

        'company_legal_name' => 'VAP Soluções, Lda.',
        'company_nif' => '5000000000',
        'company_address' => 'Luanda, Angola',
        'data_protection_email' => env('PLATFORM_DPO_EMAIL', 'privacidade@vapsolucoes.ao'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumer protection
    |--------------------------------------------------------------------------
    |
    | A customer who is not satisfied with our own handling of a complaint may
    | escalate to INADEC, the national consumer authority. Naming that route is
    | part of the obligation, not a courtesy, so it is not editable away.
    |
    */

    'consumer_authority' => [
        'name' => 'INADEC — Instituto Nacional de Defesa do Consumidor',
        'url' => 'https://www.inadec.gov.ao',
        'phone' => '+244 923 190 000',
    ],

];
