## Context

Ver proposal.md, seção Why. O estado atual que molda a abordagem:

- `process_template_tasks.department` e `tasks.department` são `string` com padrão `'Fiscal'`. `ProcessGenerationService` copia o texto da etapa para a task na geração.
- `DepartmentMembership::findId($accountId, $name)` resolve nome para id (trim, caixa baixa). Os Form Requests de modelo e de task e o aviso `work.generation.assignee_outside_department` dependem disso.
- `DepartmentController::destroy` responde 422 quando `usageDescription` acha etapa ou task aberta com o nome.
- `TaskController` filtra por `department` (string) com `LOWER(department) = ?` e responde 422 se o nome não existir na Account.
- `departments.name` tem até 40 caracteres, com `unique(account_id, name)`. A coluna de texto das etapas e tasks aceita até 255.
- `AccountObserver::created` já cria a assinatura Básico. Ele roda também em console e seed, onde `CurrentTenant` está vazio.

## Goals / Non-Goals

**Goals:**
- Etapa e task referenciam o departamento por `department_id`, com o nome sempre atual.
- Excluir um departamento nunca falha por uso.
- Accounts novas nascem com quatro departamentos padrão.
- Migrar os dados em texto sem perder nenhum departamento citado.

**Non-Goals:**
- Semear os quatro padrão em Accounts que já existem. A migração só cria o que o texto cita.
- Editar o departamento de uma task direto na API. `PATCH /tasks/{id}` continua sem aceitar `department_id`.
- Filtro de servidor por "Sem departamento". O facet de "Sem departamento" fica no cliente, sobre os dados já carregados.
- Histórico do nome do departamento no momento da geração.

## Decisions

### 1. Etapa e task guardam só `department_id`, FK nullable com `nullOnDelete`

As duas tabelas ganham `foreignId('department_id')->nullable()->constrained()->nullOnDelete()` e perdem a coluna `department`. A exclusão do departamento fica a cargo do banco, sem varredura na aplicação.

Alternativas descartadas:
- Guardar `department_id` e manter o nome congelado ao lado. Foi descartada pelo usuário: a task deve mostrar o nome atual, e duas fontes de nome divergem.
- `restrictOnDelete`. Foi descartada porque contraria a decisão de nunca bloquear a exclusão.

### 2. O departamento sai do congelamento do gerado, de forma consciente

A regra "Congelamento do gerado" deixa de cobrir o departamento: renomear ou excluir um departamento reflete nas tasks já geradas. O vínculo é copiado na geração (a task recebe o `department_id` da etapa). Trocar o departamento de uma etapa depois da geração, portanto, não muda as tasks já geradas. Só o registro do departamento é vivo. Título, prazo, prioridade, descrição e responsável continuam congelados.

Justificativa: departamento é uma classificação da equipe, não um fato da competência. Um nome antigo nas tasks abertas atrapalha o filtro e o board mais do que ajuda.

Alternativas descartadas:
- Manter tudo congelado com snapshot do nome. É o estado atual e é o problema.
- Resolver o departamento da task pela etapa do modelo em tempo de leitura. Processos manuais e etapas editadas depois da geração quebrariam essa ligação.

### 3. A API expõe `department_id` e `department` embutido; a escrita aceita só `department_id`

`ProcessTemplateTaskResource` e `TaskResource` expõem `department_id` e `department` como `{id, name, color}` ou `null`, carregados com eager loading (`with('department')`) nas listagens, no board, no calendário e no payload agrupado. Na escrita de etapas, `steps.*.department_id` é `nullable|integer` e precisa existir em `departments` com o `account_id` corrente. Caso contrário, a resposta é 422. O filtro de listagem, board e calendário troca `department` (string) por `department_id` (inteiro), com 422 para id fora da Account.

Alternativas descartadas:
- Manter `department` como string na escrita e resolver por nome no servidor. Renomeações concorrentes e nomes duplicados em caixa diferente fariam a resolução ambígua.
- Expor só `department_id` e deixar o frontend cruzar com `/departments`. Cada tela precisaria de uma segunda chamada, e o nome ficaria vazio enquanto ela não chega.

### 4. `AccountObserver::created` semeia os quatro padrão com `account_id` explícito

Os departamentos Fiscal (`success`), Pessoal (`info`), Contábil (`primary`) e Societário (`warning`) são criados com `Department::withoutGlobalScopes()->firstOrCreate(['account_id' => ..., 'name' => ...], ['color' => ...])`. O `account_id` vai explícito porque o observer roda em console, seed e testes, onde `CurrentTenant` está vazio e `BelongsToAccount` não preencheria nada. `firstOrCreate` deixa o seed idempotente diante do `unique(account_id, name)`.

Alternativas descartadas:
- Semear no fluxo de registro (controller). Accounts criadas pelo painel de admin, por seeders ou por factories ficariam sem os padrão.
- Criar os padrão sob demanda na primeira abertura de Equipe. O seletor de etapa ficaria vazio numa Account nova.

### 5. A migração cria departamento para todo nome sem correspondência, e só depois troca a coluna

Uma migration única, em três passos:
1. Adiciona `department_id` nullable em `process_template_tasks` e `tasks`.
2. Para cada par `(account_id, trim(department))` distinto, em etapas e tasks e ignorando texto vazio, procura um departamento da mesma Account com `LOWER(name) = LOWER(trim(texto))`. Sem correspondência, cria o departamento com o texto aparado e cortado em 40 caracteres (`mb_substr`), cor `neutral`, e passa a considerá-lo nas próximas comparações da mesma Account. Em seguida faz o `UPDATE` da FK por par.
3. Remove a coluna `department` das duas tabelas.

Texto vazio vira `department_id` null. Dois textos que só diferem em caixa viram um departamento só, com a grafia da primeira ocorrência. Um texto cortado em 40 que colida com um nome existente usa o existente.

O `down` recria `department` como `string` com padrão `'Fiscal'`, copia o nome atual pelo join e remove a FK. Departamentos criados pela migração ficam.

Alternativas descartadas:
- Descartar os nomes sem correspondência (FK null). Foi descartada pelo usuário: perderia a classificação de etapas e tasks antigas.
- Migração em PHP via Eloquent com escopos. Em migration não há tenant, e o escopo global não filtra. A migração usa `DB::table` com `account_id` explícito em toda consulta.

### 6. A exclusão de departamento responde 204 sempre que autorizada

`DepartmentController::destroy` perde `usageDescription` e o 422. A FK com `nullOnDelete` solta etapas e tasks. O frontend mostra "Sem departamento" quando `department` é `null`.

Alternativas descartadas:
- Pedir confirmação no servidor com a contagem de uso. A decisão é não bloquear. Se a contagem for útil, ela pode entrar só no modal do frontend, fora desta change.

### Tenancy, papéis, suporte e auditoria

- Tenancy: `department_id` sempre aponta para um departamento da mesma Account. A validação usa `Rule::exists('departments', 'id')->where('account_id', $accountId)`. Observer e migração gravam `account_id` de forma explícita, sem depender de `CurrentTenant`. Não há job novo.
- Papéis: nada muda. `admin` e `operador` escrevem departamentos e modelos, `user` só lê (403 na escrita).
- Suporte: o modo suporte segue escrevendo na Account alheia. As escritas de departamento e de modelo continuam passando por `SupportAudit::logWrite`, sem verbo novo. A semeadura no observer não é escrita de suporte e não é auditada.
- Segredos: a change não toca certificado, tokens nem XML bruto, e não há integração externa.

## Risks / Trade-offs

- [Quebra de contrato da API: `department` deixa de ser string] → Backend e frontend entram no mesmo deploy. Os tipos em `frontend/app/types/work.ts` mudam primeiro, e o `pnpm typecheck` aponta cada leitura antiga.
- [Renomear um departamento muda o rótulo de tasks já concluídas de meses passados] → É a decisão 2, aceita pelo usuário. A spec de congelamento registra a exceção.
- [A migração pode criar departamentos com grafia estranha ("fiscal ", "FISCAL")] → A comparação é por trim e caixa, então só a primeira grafia vira departamento. O Membro pode renomear ou excluir depois.
- [Nomes com mais de 40 caracteres são cortados] → Improvável em dados reais, porque o formulário atual usa departamentos cadastrados. O corte é determinístico e evita erro de migração.
- [O `down` não remove os departamentos criados pela migração] → Eles são dados válidos da Account. Removê-los apagaria renomeações feitas depois.
- [Accounts existentes não ganham os quatro padrão] → É um non-goal assumido. Quem precisar cria pela tela de Equipe.

## Migration Plan

1. Deploy único de backend e frontend, com a migration da decisão 5.
2. A migração roda em produção só com autorização explícita, conforme o AGENTS.md. Antes dela, fazer backup das tabelas `process_template_tasks`, `tasks` e `departments`.
3. Conferir depois: nenhuma etapa ou task com texto não vazio ficou com `department_id` null, e a quantidade de departamentos criados por Account bate com os nomes sem correspondência.
4. Rollback: `php artisan migrate:rollback --step=1` recria a coluna de texto a partir do nome atual, seguido do deploy da versão anterior.

## Open Questions

- As cores dos quatro padrão (Fiscal `success`, Pessoal `info`, Contábil `primary`, Societário `warning`) foram escolhidas sem pedido explícito. Trocar alguma é só um valor na semeadura e não muda specs nem tasks.
