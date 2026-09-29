## Why

A etapa do blueprint e a task guardam o departamento como texto livre, copiado na geração. Renomear um departamento deixa etapas e tasks com o nome antigo. Excluir um departamento é bloqueado com 422 enquanto houver etapa ou task aberta que cite o nome. Os filtros comparam strings em caixa baixa. Com departamentos já cadastrados por Account, faz sentido que etapa e task apontem para o registro, e não para uma cópia do nome.

## What Changes

- Etapa do blueprint (`process_template_tasks`) e task (`tasks`) passam a guardar só `department_id`, uma FK nullable para `departments` com `nullOnDelete`. A coluna de texto `department` sai.
- A task sempre mostra o nome atual do departamento. Sem departamento, a interface mostra "Sem departamento".
- **BREAKING**: a API troca `department` (string) por `department_id` (nullable) na escrita de etapas e passa a expor `department_id` + `department` (`{id, name, color}` ou `null`) em etapas e tasks. O filtro de listagem, board e calendário passa a receber `department_id`.
- Toda Account nova nasce com os departamentos Fiscal, Pessoal, Contábil e Societário. O Membro pode renomear e excluir os padrão como qualquer outro.
- Excluir um departamento nunca é bloqueado. Etapas e tasks que o usavam ficam sem departamento. A regra atual de 422 "em uso" sai.
- O congelamento do gerado deixa de valer para o departamento: renomear ou excluir um departamento reflete nas tasks já geradas. Os demais campos continuam congelados.
- Migração de dados: todo nome em texto que não bata (trim, sem diferenciar caixa) com um departamento da mesma Account vira um departamento novo nessa Account. Depois a FK é preenchida e a coluna de texto é removida.

## Capabilities

### New Capabilities

Nenhuma.

### Modified Capabilities

- `team-departments`: departamentos padrão na criação da Account e exclusão que libera etapas e tasks para "Sem departamento".
- `work-templates`: etapa do blueprint referencia um departamento da mesma Account (opcional, 422 para outra Account). A geração mensal copia a referência ao departamento, não o nome.
- `work-tasks`: a task referencia o departamento em vez de guardar um snapshot do nome. A listagem filtra por `department_id`.
- `work-processes`: o congelamento do gerado exclui o departamento.

## Impact

**Backend**
- Migrations novas em `backend/database/migrations/`: adicionar `department_id` em `process_template_tasks` e `tasks`, migrar dados e remover `department`. Tabelas de origem: `2026_09_24_000002_create_process_template_tasks_table.php`, `2026_09_24_000005_create_tasks_table.php` e `2026_09_24_004009_create_departments_table.php`.
- `backend/app/Observers/AccountObserver.php`: semear os quatro departamentos padrão.
- `backend/app/Models/Task.php` e `backend/app/Models/ProcessTemplateTask.php`: fillable, relação `department()` e `scopeOfDepartment` por id.
- `backend/app/Services/ProcessGenerationService.php`: copiar `department_id` e usar o id no aviso de responsável fora do departamento.
- `backend/app/Services/DepartmentMembership.php`: `findId` por nome deixa de ter uso nos fluxos de Work.
- `backend/app/Http/Requests/Tenant/{Store,Update}ProcessTemplateRequest.php` e `UpdateTaskRequest.php`: validar `department_id` da Account e o vínculo do responsável por id.
- `backend/app/Http/Controllers/Tenant/TaskController.php`: filtro `department_id` com 422 para id fora da Account.
- `backend/app/Http/Controllers/Tenant/DepartmentController.php`: remover `usageDescription` e o bloqueio 422 do `destroy`.
- `backend/app/Http/Resources/{Task,ProcessTemplateTask}Resource.php`: expor `department_id` e `department`.
- Testes em `backend/tests/Feature/Tenancy/` (`DepartmentTest`, `WorkTemplateTest`, `WorkGenerationTest`, `WorkTaskTest`, `WorkFreezeTest`).

**Frontend**
- `frontend/app/types/work.ts`: `WorkTemplateStep`, payload de etapas e `WorkTask` com `department_id` e `department` nullable.
- `frontend/app/pages/work/modelos/[id].vue`: seletor de departamento da Account, com opção vazia, no lugar do texto livre com padrão "Fiscal".
- `frontend/app/composables/useWork.ts` e `frontend/app/utils/{workTarefasFilters,workFacetFilters,workClientesFilters,workProcessosFilters}.ts`: facet e filtro por id, com "Sem departamento".
- `frontend/app/components/work/WorkTarefasCard.vue` e `frontend/app/components/work/calendar/*`: nome atual ou "Sem departamento".
- `frontend/app/pages/equipe/departamentos.vue`: a exclusão deixa de tratar o 422 "em uso".

**Provedor**
- Nenhum. A change não toca o SERPRO nem outra integração externa.
