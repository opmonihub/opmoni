<?php

return [
    /*
     * Probes de integração SERPRO (PGDAS e futuros). Opt-in: não entram no
     * `composer test` padrão e exigem homologação fiscal.
     */
    'enabled' => filter_var(env('SERPRO_PROBE_ENABLED', false), FILTER_VALIDATE_BOOL),

    /** Cliente canário (AUTO CENTER) para PGDAS em homologação. */
    'homologation_canary_cnpj' => env('SERPRO_PROBE_CANARY_CNPJ', '30288513000100'),

    /** CNPJ do e-CNPJ corrente do escritório; localiza a Account quando --account não é passado. */
    'homologation_account_cnpj' => env('SERPRO_PROBE_ACCOUNT_CNPJ', '48123272000105'),
];
