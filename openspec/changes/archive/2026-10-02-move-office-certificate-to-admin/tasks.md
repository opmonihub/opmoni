## 1. Baseline

- [x] 1.1 Confirmar a árvore limpa o bastante para a change e as suítes atuais verdes, verificar `git status --short` e `cd backend && php artisan test --compact` e `cd frontend && pnpm test`.

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
- [x] 4.2 Fazer `admin@example.com` o único login da conta 1, com `is_super_admin` e vínculo `admin`, apagar `super_admin@example.com`, e cobrir no teste que essa Account corrente não é acesso de suporte, verificar `cd backend && php artisan test --compact --filter=DevAdminSeeder`.

### Observações da implementação

- O e-mail do super_admin virou a constante `DevAdminSeeder::SUPER_ADMIN_EMAIL` (`super_admin@example.com`), no mesmo padrão de `EMAIL`/`PASSWORD` já publicados.
- O seeder reafirma `is_super_admin` nos dois lados a cada execução: `false` no login de Membro e `true` no super_admin, então uma base dev antiga em que `admin@example.com` tinha o flag é corrigida pela próxima rodada do seed.
- A task 4.2 substitui o segundo e-mail: `admin@example.com` passa a ser super_admin e Membro da conta 1, e `super_admin@example.com` sai. Sem o vínculo, a conta própria aparece como acesso de suporte.

## 5. Frontend, testes que falham

- [x] 5.1 Atualizar `frontend/tests/monitoringRoutes.test.ts` para não esperar `/monitoring/termos`, e cobrir em teste de `adminNav` a entrada "Certificado do escritório", verificar que `cd frontend && pnpm test` falha nesses casos antes da navegação mudar.

### Observações da implementação

- `frontend/tests/adminNav.test.ts` é um arquivo novo: não existia cobertura do `adminNav`. Os 5 casos do describe "the office certificate entry" falham hoje (entrada inexistente), e voltam a passar na task 6.1.
- Em `monitoringRoutes.test.ts`, os dois casos que falham são "offers Painel and the integration screens as the module tabs" (lista agora é `['/monitoring', '/monitoring/execucoes']`) e o novo "keeps the office certificate out of the module". O caso "does not resolve the moved office-certificate screen" (`parseMonitoringSlug(['termos']) === null`) já passa, porque o slug nunca foi obrigação — ele fixa o contrato do catch-all para depois da remoção da página.
- O teste "keeps the office certificate out of the module" continuará verde após a mudança: ele passa a ser a guarda contra regressão, não só a falha vermelha.

## 6. Frontend, tela

- [x] 6.1 Mover o cadastro de `frontend/app/pages/monitoring/termos.vue` para `frontend/app/pages/admin/certificado.vue` e registrá-lo em `adminNav.ts`, verificar `cd frontend && pnpm test`.
- [x] 6.2 Tirar o link de `monitoringIntegrationLinks` e o título correspondente em `monitoring.vue`, mantendo execuções e obrigações, verificar `cd frontend && pnpm test`.
- [x] 6.3 Tirar "Certificado do escritório" de `adminNav.ts` e de `/admin/certificado`, e colocá-lo em `frontend/app/pages/settings/certificado.vue` só com arquivo e senha, sem termo e sem interruptor, na toolbar de `settings.vue` e nos filhos de Configurações do sidebar, só para super_admin, verificar `cd frontend && pnpm test`.
- [x] 6.4 Em `/admin/serpro`, permitir usar o e-CNPJ da conta 1 sem segunda cópia ou enviar outro arquivo, verificar `cd frontend && pnpm test`.

### Observações da implementação

- A página foi movida com `git mv` (94% de similaridade). O `definePageMeta` próprio saiu — o shell `pages/admin.vue` já aplica `['auth', 'super-admin']` — e o `useMonitoringActions` saiu junto: a navbar do Admin não tem o contador `monitoring-refresh`. O `useRetryableLoad` continua e mantém o `ErrorRetryAlert` + `retry`; a tela não oferece botão de atualizar na navbar, como as demais páginas do Admin.
- `canWriteCertificate` segue sobre `canManageClients` (verdadeiro para `is_super_admin` via `can()`), e o bloco de habilitação segue sobre `canManageMembers`. As guards de escrita que ficavam no código e nos comentários foram reescritas para dizer `is_super_admin`, que é o que `AccountCertificatePolicy` e `UpdateSerproEnablementRequest` exigem agora — os textos antigos diziam `admin`/`operador` e estariam mentindo.
- A entrada em `adminPages` ficou logo depois de `Serpro` (`i-lucide-file-signature`), porque as duas são a superfície do Integra Contador no Painel Global.
- A seção Integração do painel de monitoramento ficou com um cartão só, então o grid virou coluna única (`grid gap-3`) em vez de um cartão ocupando metade de um `sm:grid-cols-2`; o subtítulo passou a falar só do histórico de sincronizações.
- Comentários que diziam "duas telas de integração" em `monitoringNav.ts` e a referência a `termos.vue` em `monitoringPresentation.ts` foram atualizados para o estado novo.
- A task 6.3 desfaz a aba no Painel Global e deixa em Configurações só o arquivo. A task 6.4 faz `/admin/serpro` usar o e-CNPJ da conta 1 ou outro arquivo.
- **6.3**: a tela virou `frontend/app/pages/settings/certificado.vue`, com `middleware: ['auth', 'super-admin']` próprio porque o shell `pages/settings.vue` é só `auth`. O termo e o interruptor saíram — ficam o cartão de metadados, a remoção com confirmação e o formulário de arquivo+senha. A navegação foi extraída para `frontend/app/utils/settingsNav.ts` (`settingsPages`, `settingsTabs`, `settingsSidebarChildren`), que a toolbar de `settings.vue` e o sidebar de `layouts/default.vue` compartilham, com a aba entrando só quando `isSuperAdmin`. Cobertura nova em `frontend/tests/settingsNav.test.ts`; `adminNav.test.ts` passou a afirmar a ausência da entrada e a manter a cobertura de `/admin/serpro`.
- **6.4**: o `PUT /api/serpro/connection` ganhou `use_account_certificate` (boolean). Com a flag, `SerproConnectionManager` grava `contracting_account_id` apontando para a primeira `Account` e zera as colunas de certificado próprio — os bytes e a senha moram na linha corrente de `account_certificates`, resolvidos por leitura em `SerproConnection::contractingCertificate()`, então trocar o e-CNPJ em Configurações não exige reenvio aqui. Arquivo novo encerra o vínculo; os dois juntos são 422. `SerproConnectivity` passou a conferir `hasCertificate()` em vez da coluna, e a resource lê os metadados da linha emprestada. Migração `2026_10_02_000000_add_contracting_account_to_serpro_connections_table`.
- **4.2**: `DevAdminSeeder` agora garante `admin@example.com` como `is_super_admin` + Membro `admin` da primeira Account e apaga `super_admin@example.com` se existir; `SUPER_ADMIN_EMAIL` virou a constante do que remover.
- **7.1**: a frase do `CONTEXT.md` já dizia "enviado pelo super_admin" desde o commit `9d27e50`; nada a mudar.

## 7. Glossário

- [x] 7.1 Ajustar em `CONTEXT.md` a frase do certificado do escritório para dizer que quem envia é o super_admin, verificar que a frase não cita mais `admin` ou `operador` como quem envia.

## 8. Verificação

- [x] 8.1 Rodar a suíte PHP e o formatador, verificar `cd backend && composer test` e `cd backend && vendor/bin/pint --dirty --format agent`.
- [x] 8.2 Rodar lint, tipos e testes do frontend, verificar `cd frontend && pnpm lint && pnpm typecheck && pnpm test`.
- [x] 8.3 Validar a change e procurar segredo em log ou resposta nova, verificar `openspec validate --change move-office-certificate-to-admin` e uma busca no diff por senha de certificado, XML do termo e token.
