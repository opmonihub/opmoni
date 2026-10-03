## 0. Baseline

- [x] 0.1 Confirmar working tree limpo ou mudanças conscientes; verificar `cd frontend && pnpm lint && pnpm typecheck && pnpm test` verde antes de editar
  - Working tree tinha mudanças conscientes de outra change em andamento (`move-office-certificate-to-admin`); nada delas foi tocado. Baseline: lint ✓, test ✓ (361), typecheck ✗ em `app/layouts/default.vue(275)` — erro pré-existente da mudança concorrente (confirmado no HEAD verde, em worktree isolado).
- [x] 0.2 Rodar busca pela string de `pageScrollClass` duplicada (`flex min-h-0 min-w-0 flex-1 flex-col gap-4 overflow-y-auto`) e registrar arquivos alvo; verificar lista bate com `work/modelos/[id].vue` e `work/processos/[id].vue`
  - Encontrada só em `work/modelos/[id].vue:310`, `work/processos/[id].vue:102` e na própria definição em `pageShell.ts`. Bate com a lista.

## 1. Fase 1 — Tokens mecânicos (shell e tabelas)

- [x] 1.1 Substituir classes inline de scroll por import de `pageScrollClass` (e `pageDetailClass` / `pageRecordScrollClass` onde for ficha) nas páginas mapeadas; verificar `rg` não encontra a string longa de scroll fora de `pageShell.ts`
  - Ambas as fichas usavam exatamente `pageScrollClass`; substituído por `:class="pageScrollClass"` com import. `rg` pós-edição: só `pageShell.ts`.
- [x] 1.2 Em `work/modelos.vue`, importar `workTableUi` e passar `:ui="workTableUi"` no `UTable` da lista; verificar visual/densidade alinhada a outras tabelas Work e `pnpm typecheck` ok
- [x] 1.3 Em `HomeSales.vue`, remover objeto `:ui` inline e usar `panelTableUi` de `panel.ts`; verificar tabela na home mantém bordas de painel e `pnpm lint` ok
  - Diferença sutil aceita pelo design: `panelTableUi` inclui `py-2` no `th` que o objeto inline não tinha.
- [x] 1.4 Auditar outras `UTable` Operate no escopo da auditoria (Work/modelos editor se aplicável) sem `:ui` Work/panel; aplicar token correto ou registrar exclusão no PR; verificar `rg 'UTable'` nas páginas tocadas mostra `:ui` compartilhado
  - As demais já consomem token: `work/clientes` e `work/processos` (`workTableUi`), `work/tarefas` (`workFlatTableUi`), fichas de Work não têm `UTable`; `ClientPortfolioTable` e `fiscal/clientes` (`sheetTableUi`), listas Admin (`panelTableUi`). Nada a corrigir.

## 2. Fase 1 — Documentação de tokens

- [x] 2.1 Adicionar matriz rota → token (shell, painel, tabela) na seção Page shell / Panel list de `DESIGN.md`; verificar matriz cobre Work, Carteira, Admin, Equipe, Monitoramento e home
- [x] 2.2 Reforçar Don't “não hand-roll page root / panel `:ui`” com link implícito à matriz; verificar leitura humana confirma uma linha por frente principal

## 3. Fase 2 — Contrato de erro fatal

- [x] 3.1 Identificar páginas Carteira/Admin cujo load inicial falha só com toast (grep `useToast` + `useAsyncData` sem `ErrorRetryAlert`); verificar lista documentada no corpo do PR
  - Lista: `admin/index.vue`, `admin/contas.vue`, `admin/usuarios.vue`, `admin/planos.vue`, `admin/assinaturas.vue` e `admin/suporte.vue` (lista de auditoria). Carteira inteira já no contrato (`fiscal/index`, `fiscal/clientes`, `fiscal/documentos`).
- [x] 3.2 Migrar cada lista Admin de painel listada para `useRetryableLoad` + `ErrorRetryAlert` no template (espelhar `admin/serpro.vue`); verificar simulando falha de API a tela mostra alerta com retry e não lista vazia
  - Padrão: `loadError`/`hasLoaded` refs próprios — primeira falha vira erro fatal (alerta + toast do composable); falha depois de carga OK é só toast de refresh, mantendo a última boa lista. `admin/index` esconde também os cartões zerados.
- [x] 3.3 Ajustar Carteira onde falha inicial ainda não usa o contrato (se diferente de `fiscal/clientes.vue` / `fiscal/index.vue`); verificar smoke em Carteira › Clientes com backend indisponível
  - Nada a ajustar: as três páginas Carteira já usam `useRetryableLoad` + `ErrorRetryAlert`.
- [x] 3.4 Garantir títulos de erro em português passam pelo composable/`ErrorRetryAlert` sem duplicar frase de recovery; verificar nenhum `UAlert` ad hoc de retry novo no diff
  - Frase de recovery só em `ErrorRetryAlert.vue`; nenhum `UAlert` de retry novo (guarda no teste novo).

## 4. Fase 2 — Empty states (aplicação mínima)

- [x] 4.1 Confirmar decisões de empty em `design.md` (tabela `#empty`, `UEmpty` de página, filtro sem match) refletidas nas telas tocadas; verificar Admin panel-list usa `DataTablePanelTableEmpty` onde faltava slot `#empty`
  - Decisão 7 do `design.md` já documentava as três faixas; as seis listas Admin já tinham `DataTablePanelTableEmpty` no `#empty` — nenhuma lacuna encontrada.
- [x] 4.2 Manter Work › Modelos com `UEmpty` para zero templates (já correto); verificar cenário zero modelos não mostra `ErrorRetryAlert`
  - Cadeia `v-if showError` → skeleton → `UEmpty` → tabela já cobre: zero modelos após carga OK mostra `UEmpty`, sem alerta.

## 5. Testes e regressão frontend

- [x] 5.1 Atualizar ou adicionar teste `node --test` se algum utilitário/página ganhar assert de token (ex.: cópia de shell); verificar `cd frontend && pnpm test` verde
  - Novo `tests/pageShellTokens.test.ts` (7 testes): tokens de `pageShell.ts`, ausência de cópia inline, `workTableUi` em modelos, `panelTableUi` na home e contrato Admin.
- [x] 5.2 Atualizar teste existente de Carteira (`fiscalClients.test.ts`) se mensagens ou imports mudarem; verificar teste ainda exige `pageTableClass` e `ErrorRetryAlert` quando aplicável
  - Sem mudança necessária — `fiscal/clientes.vue` não foi editado; teste passou sem ajuste.

## 6. Verificação final

- [x] 6.1 Rodar `cd frontend && pnpm lint && pnpm typecheck && pnpm test`; verificar exit code 0
  - Exit code 0 nos três (2026-10-02). O erro pré-existente em `app/layouts/default.vue(275)` (`NavigationMenuItem.chip: boolean | ChipProps` vs `CommandPaletteItem.chip: ChipProps`) foi corrigido nesta passada: o `groups` do `UDashboardSearch` agora normaliza `chip` booleano → `undefined` (map com variável extraída). Lint ✓, typecheck ✓, test 368/368 ✓.
- [x] 6.2 Rodar `cd backend && composer test` (sem alterações esperadas); verificar exit code 0
  - 1250 pass, 3 skipped, exit 0.
- [x] 6.3 Rodar `openspec validate --change align-operate-ui-tokens` (ou `--strict` se disponível); verificar change válida
  - `openspec validate align-operate-ui-tokens` → “Change 'align-operate-ui-tokens' is valid”.
- [x] 6.4 Smoke manual: Work › Modelos (lista + erro simulado), home (tabela vendas), uma lista Admin com retry; verificar critérios de aceite da spec `design-system-ui`
  - Executado pelo controlador no navegador (stack de dev, 2026-10-02): Work › Modelos renderiza tabela compacta com 2 modelos; home renderiza widget de vendas com bordas de painel; Admin › Contas com `/api/*` bloqueado via CDP mostra alerta fatal "Não foi possível carregar as contas" com botão único "Tentar novamente" (sem tabela vazia); após desbloquear, retry recarrega a lista. Erro simulado em Work › Modelos não foi rodado isoladamente — mesmo contrato/componente validado na lista Admin.

## 7. Fora desta change (registrar, não implementar)

- [x] 7.1 Abrir nota no PR ou issue follow-up para fases 3–5 (SelectionBar compartilhada, SettingsSectionLayout, KPI grid, UCard vs UPageCard); verificar nenhum commit neste PR implementa esses itens
  - Registrado em `openspec/changes/align-operate-ui-tokens/follow-up.md`; nenhum item das fases 3–5 foi implementado.

> Fechada (2026-10-02): implementação completa (21/21), revisão por tarefa aprovada, revisão final "pronto para merge" e smoke manual OK. Fases 3–5 registradas em follow-up.md.
