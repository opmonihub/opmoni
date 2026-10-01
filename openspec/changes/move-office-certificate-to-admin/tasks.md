## 1. Baseline

- [ ] 1.1 Confirmar a árvore limpa o bastante para a change e as suítes atuais verdes, verificar `git status --short` e `cd backend && php artisan test --compact` e `cd frontend && pnpm test`.

## 2. Backend, testes que falham

- [ ] 2.1 Acrescentar em `SerproAccountCertificateTest` os casos de `admin` e `operador` da Account recebendo 403 no POST e no DELETE, e de super_admin gravando e removendo, verificar que `cd backend && php artisan test --compact --filter=SerproAccountCertificate` falha nesses casos antes da policy mudar.
- [ ] 2.2 Acrescentar em `SerproAccountEnablementTest` os casos de `admin`, `operador` e `user` recebendo 403 no PUT, de super_admin alterando o flag, e de Membro lendo 200, verificar que `cd backend && php artisan test --compact --filter=SerproAccountEnablement` falha nesses casos antes do Form Request mudar.
- [ ] 2.3 Acrescentar um teste do `DevAdminSeeder` em ambiente `local`: `admin@example.com` fica Membro `admin` com `is_super_admin` falso, e `super_admin@example.com` fica super_admin com a mesma Account corrente, verificar que `cd backend && php artisan test --compact --filter=DevAdminSeeder` falha antes do seeder mudar.

## 3. Backend, autorização

- [ ] 3.1 Restringir `create` e `delete` de `AccountCertificatePolicy` a `isSuperAdmin()`, mantendo `viewAny` para Membro, verificar `cd backend && php artisan test --compact --filter=SerproAccountCertificate`.
- [ ] 3.2 Restringir `UpdateSerproEnablementRequest` a super_admin, sem alterar `AccountPolicy::update`, verificar `cd backend && php artisan test --compact --filter=SerproAccountEnablement`.

## 4. Seed local

- [ ] 4.1 Ajustar `DevAdminSeeder` para reafirmar os dois logins só em `local`, sem copiar arquivo de `.ref/data/`, verificar `cd backend && php artisan test --compact --filter=DevAdminSeeder`.

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
