## 1. Baseline

- [ ] 1.1 Confirmar a árvore limpa e as suítes verdes antes de mudar código; verificar com `git status --short`, `cd backend && composer test` e `cd frontend && pnpm test`
- [ ] 1.2 Validar a change antes da implementação; verificar com `openspec validate departments-fk --strict`

## 2. Backend: testes RED

- [ ] 2.1 Escrever em `backend/tests/Feature/Tenancy/DepartmentTest.php` os testes da criação da Account com Fiscal, Pessoal, Contábil e Societário (account_id explícito, sem tenant) e da exclusão com 204 que solta etapas e tasks; verificar que falham com `php artisan test --compact --filter=DepartmentTest`
- [ ] 2.2 Escrever em `backend/tests/Feature/Tenancy/WorkTemplateTest.php` os testes de etapa com `department_id` da Account, etapa sem departamento e 422 para departamento de outra Account; verificar que falham com `php artisan test --compact --filter=WorkTemplateTest`
- [ ] 2.3 Escrever em `backend/tests/Feature/Tenancy/WorkGenerationTest.php` e `WorkFreezeTest.php` os testes de geração que copia `department_id`, de renomeação refletida em task gerada e de troca de departamento da etapa que não altera o gerado; verificar que falham com `php artisan test --compact --filter='WorkGenerationTest|WorkFreezeTest'`
- [ ] 2.4 Escrever em `backend/tests/Feature/Tenancy/WorkTaskTest.php` os testes de `department` embutido (nome atual ou null) e do filtro `department_id` com 422 para id de outra Account; verificar que falham com `php artisan test --compact --filter=WorkTaskTest`
- [ ] 2.5 Escrever um teste de migração que parte de etapas e tasks com texto (nome existente em outra caixa, nome inexistente, texto vazio, nome com mais de 40 caracteres) e confere departamentos criados por Account e FKs preenchidas; verificar que falha com `php artisan test --compact --filter=DepartmentFkMigrationTest`

## 3. Backend: esquema e migração

- [ ] 3.1 Criar com `php artisan make:migration --no-interaction` a migration que adiciona `department_id` nullable com `nullOnDelete`, cria departamentos para nomes sem correspondência (trim, caixa baixa, `mb_substr` 40, cor `neutral`, `account_id` explícito), preenche a FK e remove `department`, com `down` que recria o texto pelo join; verificar com `php artisan test --compact --filter=DepartmentFkMigrationTest`
- [ ] 3.2 Trocar `department` por `department_id` no fillable e adicionar a relação `department()` em `backend/app/Models/Task.php` e `backend/app/Models/ProcessTemplateTask.php`, com `scopeOfDepartment` por id; verificar com `php artisan test --compact --filter=WorkTaskTest`

## 4. Backend: departamentos

- [ ] 4.1 Semear os quatro departamentos padrão em `backend/app/Observers/AccountObserver.php` com `withoutGlobalScopes()->firstOrCreate` e `account_id` explícito; verificar com `php artisan test --compact --filter=DepartmentTest`
- [ ] 4.2 Remover `usageDescription` e o 422 "em uso" de `backend/app/Http/Controllers/Tenant/DepartmentController.php`, mantendo o `SupportAudit::logWrite` da exclusão; verificar com `php artisan test --compact --filter='DepartmentTest|WorkSupportAuditTest'`

## 5. Backend: Work

- [ ] 5.1 Validar `steps.*.department_id` como nullable e existente na Account em `backend/app/Http/Requests/Tenant/StoreProcessTemplateRequest.php` e `UpdateProcessTemplateRequest.php`, com o vínculo do responsável conferido por id; verificar com `php artisan test --compact --filter=WorkTemplateTest`
- [ ] 5.2 Copiar `department_id` da etapa para a task e usar o id no aviso `work.generation.assignee_outside_department` em `backend/app/Services/ProcessGenerationService.php`; verificar com `php artisan test --compact --filter='WorkGenerationTest|WorkFreezeTest|WorkRecurrenceCommandTest'`
- [ ] 5.3 Conferir o vínculo do responsável pelo `department_id` da task em `backend/app/Http/Requests/Tenant/UpdateTaskRequest.php`; verificar com `php artisan test --compact --filter=WorkTaskTest`
- [ ] 5.4 Trocar o filtro `department` por `department_id` com 422 para id fora da Account em `backend/app/Http/Controllers/Tenant/TaskController.php` (listagem, board, calendário e agrupado), com eager loading de `department`; verificar com `php artisan test --compact --filter=WorkTaskTest`
- [ ] 5.5 Expor `department_id` e `department` (`{id, name, color}` ou null) em `backend/app/Http/Resources/TaskResource.php` e `ProcessTemplateTaskResource.php`, e remover os usos de `DepartmentMembership::findId` que sobrarem nos fluxos de Work; verificar com `php artisan test --compact --filter='WorkTaskTest|WorkTemplateTest|WorkProcessTest'`

## 6. Frontend: testes RED

- [ ] 6.1 Escrever em `frontend/tests/` os testes do facet de departamento por id com a opção "Sem departamento" em `workTarefasFilters.ts` e `workFacetFilters.ts`; verificar que falham com `cd frontend && pnpm test`

## 7. Frontend: implementação

- [ ] 7.1 Trocar `department: string` por `department_id: number | null` e `department: { id, name, color } | null` em `WorkTemplateStep`, no payload de etapas e em `WorkTask` de `frontend/app/types/work.ts`; verificar que `pnpm typecheck` aponta as leituras antigas
- [ ] 7.2 Trocar o texto livre com padrão "Fiscal" por um seletor de departamentos da Account, com opção vazia, em `frontend/app/pages/work/modelos/[id].vue`; verificar com `cd frontend && pnpm typecheck`
- [ ] 7.3 Passar o filtro `department_id` em `frontend/app/composables/useWork.ts` e ajustar `workTarefasFilters.ts`, `workFacetFilters.ts`, `workClientesFilters.ts` e `workProcessosFilters.ts` para facet por id com "Sem departamento"; verificar com `cd frontend && pnpm test`
- [ ] 7.4 Mostrar o nome atual ou "Sem departamento" em `frontend/app/components/work/WorkTarefasCard.vue` e em `frontend/app/components/work/calendar/*`; verificar com `cd frontend && pnpm typecheck && pnpm lint`
- [ ] 7.5 Remover o tratamento do 422 "em uso" da exclusão em `frontend/app/pages/equipe/departamentos.vue`; verificar com `cd frontend && pnpm typecheck`

## 8. Verificação final

- [ ] 8.1 Rodar a suíte do backend; verificar com `cd backend && composer test`
- [ ] 8.2 Formatar o PHP alterado; verificar com `cd backend && vendor/bin/pint --dirty --format agent`
- [ ] 8.3 Rodar lint, tipos e testes do frontend; verificar com `cd frontend && pnpm lint && pnpm typecheck && pnpm test`
- [ ] 8.4 Validar a change; verificar com `openspec validate departments-fk --strict`
- [ ] 8.5 Procurar segredos em logs e respostas novas (senha de certificado, tokens, XML bruto); verificar com `git diff main -- backend/app | grep -niE 'password|token|xml|pfx'` sem ocorrências novas
