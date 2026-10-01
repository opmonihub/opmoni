## 1. Baseline

- [ ] 1.1 Confirmar a árvore limpa o bastante para a change e as suítes atuais verdes, verificar `git status --short` e `cd backend && php artisan test --compact` e `cd frontend && pnpm test`.

## 2. Backend, testes que falham

- [x] 2.1 Acrescentar em `SerproAccountCertificateTest` os casos de `admin` e `operador` da Account recebendo 403 no POST e no DELETE, e de super_admin gravando e removendo, verificar que `cd backend && php artisan test --compact --filter=SerproAccountCertificate` falha nesses casos antes da policy mudar.
- [x] 2.2 Acrescentar em `SerproAccountEnablementTest` os casos de `admin`, `operador` e `user` recebendo 403 no PUT, de super_admin alterando o flag, e de Membro lendo 200, verificar que `cd backend && php artisan test --compact --filter=SerproAccountEnablement` falha nesses casos antes do Form Request mudar.
- [x] 2.3 Acrescentar um teste do `DevAdminSeeder` em ambiente `local`: `admin@example.com` fica Membro `admin` com `is_super_admin` falso, e `super_admin@example.com` fica super_admin com a mesma Account corrente, verificar que `cd backend && php artisan test --compact --filter=DevAdminSeeder` falha antes do seeder mudar.

### Observações da implementação

- O caso de `operador`/`user` recebendo 403 no PUT já existia (`test_operador_e_user_recebem_403_e_nada_muda`); o caso novo cobre o `admin`, e o conjunto fica completo.
- Os casos de super_admin gravando (`test_super_admin_grava_e_remove_o_ecnpj_da_conta_corrente`, `test_super_admin_altera_o_flag_da_conta_corrente`) já passam hoje, porque `HasTenantRole::tenantRole` e `AccountPolicy::update` já tratam `isSuperAdmin()` como `admin`. Eles fixam o comportamento para a task 3 não regredir.
- Na task 3, os testes existentes que escrevem como `admin`/`operador` (por exemplo `test_admin_e_operador_enviam_o_ecnpj_do_escritorio` e `test_admin_habilita_com_a_conexao_inteira`) vão falhar com o 403 novo e precisam ser migrados para o helper de super_admin — a mudança de regra é exatamente essa.

## 3. Backend, autorização

- [x] 3.1 Restringir `create` e `delete` de `AccountCertificatePolicy` a `isSuperAdmin()`, mantendo `viewAny` para Membro, verificar `cd backend && php artisan test --compact --filter=SerproAccountCertificate`.
- [x] 3.2 Restringir `UpdateSerproEnablementRequest` a super_admin, sem alterar `AccountPolicy::update`, verificar `cd backend && php artisan test --compact --filter=SerproAccountEnablement`.

### Observações da implementação

- `UpdateSerproEnablementRequest::authorize` checa `$user->isSuperAdmin()` direto, sem consultar `AccountPolicy::update` — a decisão do design é não restringir a edição da Account, que o `admin` da conta continua fazendo.
- Os testes que escreviam como `admin`/`operador` migraram para `superAdminDe` (POST/DELETE do e-CNPJ e PUT do flag); os GETs seguem como Membro. Os nomes `test_admin_e_operador_enviam_o_ecnpj_do_escritorio` e `test_admin_habilita_com_a_conexao_inteira` viraram `test_super_admins_*` para não mentir o papel.
- O helper foi padronizado em `superAdminDe` nos três arquivos (`SerproAccountCertificateTest`, `SerproAccountEnablementTest`, `SerproAuthorizationTermTest` — este último ganhou o helper novo).
- `SupportAuditCoverageTest::test_membro_nao_gera_log_nas_mesmas_escritas` passou a afirmar `403` no PUT do flag: a recusa vem antes de qualquer `SupportAudit::logWrite`, e a invariante do caso (Membro não gera log) ficou ainda mais estrita.
- `test_a_policy_do_ecnpj_nao_declara_verbos_que_enderecem_a_linha` teve a segunda metade atualizada: `admin`/`operador`/`user` não passam mais em `create`/`delete` no Gate; quem passa é `is_super_admin`.

## 4. Seed local

- [x] 4.1 Ajustar `DevAdminSeeder` para reafirmar os dois logins só em `local`, sem copiar arquivo de `.ref/data/`, verificar `cd backend && php artisan test --compact --filter=DevAdminSeeder`.

### Observações da implementação

- O e-mail do super_admin virou a constante `DevAdminSeeder::SUPER_ADMIN_EMAIL` (`super_admin@example.com`), no mesmo padrão de `EMAIL`/`PASSWORD` já publicados.
- O seeder reafirma `is_super_admin` nos dois lados a cada execução: `false` no login de Membro e `true` no super_admin, então uma base dev antiga em que `admin@example.com` tinha o flag é corrigida pela próxima rodada do seed.
- O super_admin não ganha vínculo em `account_user`: ele não é Membro, e o `current_account_id` só aponta a Account de desenvolvimento.

## 5. Frontend, testes que falham

- [ ] 5.1 Atualizar `frontend/tests/monitoringRoutes.test.ts` para não esperar `/monitoring/termos`, e cobrir em teste de `adminNav` a entrada "Certificado do escritório", verificar que `cd frontend && pnpm test` falha nesses casos antes da navegação mudar.

## 6. Frontend, tela

- [ ] 6.1 Mover o cadastro de `frontend/app/pages/monitoring/termos.vue` para `frontend/app/pages/admin/certificado.vue` e registrá-lo em `adminNav.ts`, verificar `cd frontend && pnpm test`.
- [ ] 6.2 Tirar o link de `monitoringIntegrationLinks` e o título correspondente em `monitoring.vue`, mantendo execuções e obrigações, verificar `cd frontend && pnpm test`.

## 7. Glossário

- [ ] 7.1 Ajustar em `CONTEXT.md` a frase do certificado do escritório para dizer que quem envia é o super_admin, verificar que a frase não cita mais `admin` ou `operador` como quem envia.

## 8. Verificação

- [ ] 8.1 Rodar a suíte PHP e o formatador, verificar `cd backend && composer test` e `cd backend && vendor/bin/pint --dirty --format agent`.
- [ ] 8.2 Rodar lint, tipos e testes do frontend, verificar `cd frontend && pnpm lint && pnpm typecheck && pnpm test`.
- [ ] 8.3 Validar a change e procurar segredo em log ou resposta nova, verificar `openspec validate --change move-office-certificate-to-admin` e uma busca no diff por senha de certificado, XML do termo e token.
