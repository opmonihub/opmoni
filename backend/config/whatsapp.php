<?php

return [
    /*
     * Gateway wzap (WhatsApp). O serviço expõe REST na raiz com autenticação
     * por header `apikey`: a chave global (esta config) gerencia instâncias;
     * a chave de instância, emitida uma única vez na criação, fica cifrada no
     * `WhatsAppChannel` e autentica os envios daquela instância.
     */
    'enabled' => filter_var(env('WHATSAPP_DISPATCH_ENABLED', true), FILTER_VALIDATE_BOOL),

    'wzap' => [
        'base_url' => env('WZAP_BASE_URL', 'http://127.0.0.1:8081'),

        /** Chave global de máquina do wzap (WZAP_API_KEY do serviço). */
        'api_key' => env('WZAP_API_KEY'),

        'timeout' => (float) env('WZAP_TIMEOUT', 15),

        /**
         * Upload de mídia pode ser mais lento que uma chamada JSON: timeout
         * dedicado ao POST /instances/{id}/messages/media.
         */
        'media_timeout' => (float) env('WZAP_MEDIA_TIMEOUT', 60),

        /**
         * URL pública que o wzap chama para entregar eventos da instância
         * (webhook). Vazio deixa a instância sem webhook; fora de loopback o
         * serviço exige HTTPS. Preenche `webhook_url` na criação/pareamento.
         */
        'webhook_url' => env('WZAP_WEBHOOK_URL'),
    ],
];
