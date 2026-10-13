<?php

return [
    'trial_days' => 14,

    // Monthly AOA prices researched on 2026-10-13. Annual payment costs ten months.
    // Local comparisons: https://nubifact.com/, https://jochplus.ao/planos,
    // https://momenu.toquemedia.net/. Recheck market prices before future revisions.
    'catalogue' => [
        'essencial' => [
            'name' => 'Essencial',
            'summary' => 'Facturação e clientes para profissionais e pequenos negócios.',
            'monthly_amount_minor' => 450_000,
            'features' => ['invoicing'],
        ],
        'negocio' => [
            'name' => 'Negócio',
            'summary' => 'Ponto de venda, inventário e gestão diária numa só assinatura.',
            'monthly_amount_minor' => 950_000,
            'features' => ['invoicing', 'pos', 'inventory', 'transport'],
        ],
        'avancado' => [
            'name' => 'Avançado',
            'summary' => 'Tudo do Negócio, com automação, relatórios Excel e integrações.',
            'monthly_amount_minor' => 1_750_000,
            'features' => ['invoicing', 'pos', 'inventory', 'transport', 'recurring', 'reports', 'integrations'],
        ],
    ],
];
