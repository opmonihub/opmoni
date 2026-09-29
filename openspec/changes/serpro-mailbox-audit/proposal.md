## Why

A `serpro-contract-and-mailbox` foi arquivada com a leitura de mensagem da caixa postal pronta, mas a grelha de 2026-09-29 tomou decisões depois do arquivamento. A spec não fixa os status HTTP da leitura. O sistema não guarda quem registrou a ciência da intimação, só quando ela foi registrada. E nove controllers escrevem no tenant ou agem no provedor sem registrar o Acesso de suporte na auditoria.

## What Changes

- Fixar na spec de `monitoring` os status da leitura de mensagem: 422 sem `ciencia: true`, 403 para `user`, 404 para mensagem fora da caixa sincronizada, obrigação sem caixa postal ou cliente de outra Account, 409 sem termo ou sem e-CNPJ, e 502 com o rótulo da falha, sem o texto do provedor.
- Declarar que o super_admin em Acesso de suporte pode registrar ciência como qualquer `admin`, com auditoria.
- Gravar `user_id` em `serpro_calls`, para saber quem registrou a ciência.
- Chamar `SupportAudit::logWrite` nos controllers que ainda não o fazem, incluindo a leitura de mensagem.

## Capabilities

### New Capabilities

Nenhuma.

### Modified Capabilities

- `monitoring`: a leitura de mensagem ganha status HTTP, regra de papéis e o registro do autor da ciência.

## Impact

- Backend: migration nova em `database/migrations/` (`user_id` em `serpro_calls`), `app/Services/SerproCallRecorder.php`, `app/Models/SerproCall.php`, `app/Http/Controllers/Tenant/SerproMonitoringMessageController.php` e os controllers de `app/Http/Controllers/Tenant/` listados nas tasks; testes em `tests/Feature/SerproMailboxReadTest.php` e um teste novo de auditoria de suporte.
- Frontend: nenhum. A tela de confirmação já foi entregue pela `serpro-contract-and-mailbox` (antiga 3.2 de `serpro-contract-and-mailbox`).
- Provedor: nenhuma chamada nova. A conferência do `path` do `MSGDETALHAMENTO62` fica com a `serpro-provider-contract`.
