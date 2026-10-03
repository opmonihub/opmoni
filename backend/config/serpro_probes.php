<?php

return [
    /*
     * Probes opt-in contra o Integra Contador em homologação. Nunca entram no
     * `composer test` padrão — só com `--group=serpro-trial` ou o comando
     * `serpro:probe-pgdas` com esta flag ligada.
     */
    'enabled' => filter_var(env('SERPRO_PROBE_ENABLED', false), FILTER_VALIDATE_BOOL),

    /** Cliente canário (AUTO CENTER) para PGDAS-D em homologação. */
    'homologation_canary_cnpj' => env('SERPRO_PROBE_CANARY_CNPJ', '30288513000100'),

    /**
     * CNPJ do e-CNPJ do escritório (documento contratante gravado no certificado
     * da Account), usado para localizar a conta quando `--account=` não veio.
     */
    'homologation_account_cnpj' => env('SERPRO_PROBE_ACCOUNT_CNPJ', '48123272000105'),
];
