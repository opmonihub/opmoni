<?php

return [
    'operating_writes_enabled' => filter_var(env('ERP_OPERATING_WRITES_ENABLED', false), FILTER_VALIDATE_BOOL),
    'registration_enabled' => filter_var(env('ERP_REGISTRATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    'release_enabled' => filter_var(env('ERP_RELEASE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'release_mode' => env('ERP_RELEASE_MODE', 'disabled'),
    'preview_allowlist' => json_decode((string) env('ERP_PREVIEW_ALLOWLIST', '[]'), true) ?: [],
    'basic_plan_slug' => 'basic',
    'fiscal_provider' => env('ERP_FISCAL_PROVIDER', 'fake'),
    'fiscal_production_enabled' => filter_var(env('ERP_FISCAL_PRODUCTION_ENABLED', false), FILTER_VALIDATE_BOOL),
    'fiscal_certificate_relaxed_validation' => filter_var(env('ERP_FISCAL_CERTIFICATE_RELAXED', false), FILTER_VALIDATE_BOOL),
    'fiscal_canary_scope' => json_decode((string) env('ERP_FISCAL_CANARY_SCOPE', '[]'), true) ?: [],
    'fiscal_canary_operator_token' => env('ERP_FISCAL_CANARY_OPERATOR_TOKEN'),
];
