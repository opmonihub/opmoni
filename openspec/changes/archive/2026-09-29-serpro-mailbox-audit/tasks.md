## 1. Baseline

- [x] 1.1 Conferir a árvore e as suítes antes de começar; verificar com `git status --short`, `cd backend && composer test` e `cd frontend && pnpm test`

## 2. Autor da ciência

- [x] 2.1 Escrever o teste RED que confere `user_id` em `serpro_calls` depois da leitura com ciência; verificar que `php artisan test --compact --filter=SerproMailboxReadTest` falha antes da 2.2
- [x] 2.2 Adicionar `user_id` nullable em `serpro_calls`, com FK para `users` e `nullOnDelete`, e gravá-lo no `SerproCallRecorder` quando houver usuário na requisição; verificar com `php artisan test --compact --filter=SerproMailboxReadTest`

## 3. Auditoria de suporte

- [x] 3.1 Escrever o teste RED do super_admin em Acesso de suporte registrando ciência, conferindo a leitura com 200 e a linha em `support_access_logs`; verificar que `php artisan test --compact --filter=SerproMailboxReadTest` falha antes da 3.2
- [x] 3.2 Chamar `SupportAudit::logWrite` no `SerproMonitoringMessageController`; verificar com `php artisan test --compact --filter=SerproMailboxReadTest`
- [x] 3.3 Escrever testes RED que conferem a linha em `support_access_logs` para `ClientCertificateController`, `ClientEcacPowerOfAttorneyController`, `ClientCnpjRefreshController`, `ClientSavedFilterController`, `ClientSelectionController`, `SerproAccountEnablementController`, `SerproSyncRunController` e `SerproMonitoringAssociationController`; verificar que falham com `php artisan test --compact --filter=SupportAuditCoverageTest`
- [x] 3.4 Chamar `SupportAudit::logWrite` em cada um desses controllers; verificar com `php artisan test --compact --filter=SupportAuditCoverageTest`

> Se a `client-onboarding-modules` for aplicada antes, o `ClientEcacPowerOfAttorneyController` já terá saído e fica fora da 3.3.

## 4. Verificação

- [x] 4.1 Rodar a suíte e o formatador do backend; verificar com `cd backend && composer test && vendor/bin/pint --dirty --format agent`

> Fechada (2026-09-29): Pint passou e os testes desta change passam (SerproMailboxReadTest, SupportAuditCoverageTest e os controllers tocados). A suíte completa ficou com 59 falhas, todas em testes RED de `departments-fk` e `client-onboarding-modules`, que a implementação dessas changes resolve.
- [x] 4.2 Rodar as checagens do frontend, que esta change não altera; verificar com `cd frontend && pnpm lint && pnpm typecheck && pnpm test`
- [x] 4.3 Validar a change; verificar com `openspec validate serpro-mailbox-audit --strict`
- [x] 4.4 Buscar segredos em logs e respostas; verificar com `rg -n "token|senha|password|corpoModelo" backend/storage/logs` sem ocorrência desta change
