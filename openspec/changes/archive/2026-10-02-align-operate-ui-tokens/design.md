## Context

Motivação: ver `proposal.md` (auditoria UI Operate, fases 1–2).

Hoje o produto já extrai tokens em `frontend/app/utils/pageShell.ts`, `frontend/app/components/data-table/panel.ts`, `frontend/app/utils/workTableUi.ts`, `ErrorRetryAlert.vue` e `useRetryableLoad`. Parte das telas consome corretamente; outras ainda duplicam classes (ex.: `work/modelos/[id].vue`, `work/processos/[id].vue`), omitem `:ui` em tabelas Work (lista em `work/modelos.vue`), ou redefinem `:ui` inline na home (`HomeSales.vue`). Admin e Carteira misturam toast de falha inicial com listas vazias, em desvio do contrato descrito no `DESIGN.md`.

Tenancy, papéis e APIs não mudam — apenas apresentação e recuperação de erro no frontend.

## Goals / Non-Goals

**Goals:**

- Eliminar cópias inline dos tokens de `pageShell.ts` nas páginas identificadas na auditoria (fase 1 mecânica).
- Aplicar `workTableUi` na tabela de Work › Modelos; alinhar `HomeSales` a `panelTableUi`.
- Adicionar matriz rota → token em `DESIGN.md` (shell, painel, tabela).
- Padronizar falha **fatal de carga inicial** em telas Carteira e Admin que ainda dependem só de toast (fase 2), via `useRetryableLoad` + `ErrorRetryAlert`.
- Documentar neste `design.md` decisões de empty state (tabela `#empty`, `UEmpty` de página, filtros sem resultado).

**Non-Goals:**

- Extrair componente único de SelectionBar (fase 3).
- Unificar `SettingsSectionLayout` repetido (fase 3).
- Revisão sistemática de grids KPI / MetricCard (fase 4).
- Troca global `UCard` ↔ `UPageCard` em fichas de registro (fase 5).
- Alterações backend, i18n fora de pt-BR, ou mudança de rotas.

## Decisions

1. **Escopo fases 1–2 apenas, change única.**  
   Justificativa: diffs mecânicos de token e contrato de erro são revisáveis em um PR; refatorações estruturais (SelectionBar) arriscam regressão em bulk actions Work/Carteira.  
   Alternativa descartada: “big bang” da auditoria inteira — alto risco e review impossível.

2. **Nova capability `design-system-ui` em OpenSpec.**  
   Justificativa: requisitos são transversais (não pertencem só a `work-templates` ou `client-portfolio`); archive cria spec principal reutilizável.  
   Alternativa descartada: `skip_specs` — comportamento observável (retry, empty) muda para o operador.

3. **Tokens de tabela por contexto, sem token único global.**  
   Justificativa: `DESIGN.md` já separa `workTableUi`, `panelTableUi` e `sheetTableUi` (densidade e scroll diferentes).  
   Alternativa descartada: forçar `panelTableUi` em Work agrupado — quebra densidade e coluna Item.

4. **Work › Modelos usa `workTableUi` na lista flat.**  
   Justificativa: mesma frente Work, tabela densa em card subtle; não é painel `DataTablePanelList`.  
   Alternativa descartada: migrar lista para `DataTablePanelList` — escopo de layout maior (fase 3+).

5. **HomeSales importa `panelTableUi` do módulo panel.**  
   Justificativa: o `:ui` inline atual espelha parcialmente o painel; centralizar evita drift de bordas arredondadas no `thead`.  
   Alternativa descartada: remover widget demo da home — fora do pedido; dados ainda são placeholder Nuxt dashboard.

6. **Falha fatal: `useRetryableLoad` + `ErrorRetryAlert`; toast só para refresh após sucesso.**  
   Justificativa: alinha ao composable existente e evita copy divergente de retry. Admin: priorizar listas cujo `useAsyncData` inicial falha (`admin/index`, `contas`, `usuarios`, `planos`, `assinaturas`, `suporte`) — seguir padrão já usado em `admin/serpro.vue`. Carteira: confirmar páginas que ainda toastam falha inicial (auditoria citou carteira/admin).  
   Alternativa descartada: alert hand-roll por página — viola Don't do `DESIGN.md`.

7. **Empty states — três faixas (documentar, aplicar onde já houver gap óbvio no escopo).**

   | Situação | Padrão | Exemplo |
   |---|---|---|
   | Lista/painel sem linhas após load OK | `#empty` → `DataTablePanelTableEmpty` ou equivalente panel | Admin lists |
   | Página inteira sem entidade | `UEmpty` naked com ação primária | Work › Modelos sem templates |
   | Filtro/busca sem match | Mensagem na tabela ou empty inline, **sem** ErrorRetryAlert | Carteira clientes filtrados |

   Justificativa: operador distingue “erro de rede” de “nada cadastrado”.  
   Alternativa descartada: sempre `UEmpty` full-page — esconde toolbar/contexto.

8. **Matriz em `DESIGN.md` estende seção “Page shell (roots)”** com colunas: rota (grupo), shell, tabela/painel, notas. Não duplicar tokens — só mapa de consumo.

## Risks / Trade-offs

- **[Risco] PR touch many files** → Mitigação: tasks em commits lógicos (shell → tabelas → erros → doc); smoke manual por frente.
- **[Risco] `panelTableUi` na home altera visual sutil** → Mitigação: screenshot antes/depois; aceitável por alinhamento.
- **[Risco] Admin toast removido confunde quem esperava notificação** → Mitigação: manter toast em falha de *refresh* não fatal; fatal fica no alert.
- **[Trade-off] Fases 3–5 ficam para change futura** → Documentado em proposal e non-goals; evita bloquear ganhos rápidos.

## Migration Plan

1. Implementar tokens mecânicos (imports + `:ui`) — sem feature flag.
2. Migrar páginas Admin/Carteira para contrato de erro — comportamento mais visível; rollback = revert do PR frontend.
3. Atualizar `DESIGN.md` matriz no mesmo PR ou imediatamente após (tasks separada doc-only se preferível).
4. Validar: `pnpm lint`, `pnpm typecheck`, `pnpm test` no frontend; smoke manual Work › Modelos, Carteira › Clientes, uma lista Admin com API indisponível simulada.

Sem migration de dados, deploy ou backend. Tenancy inalterada (`account_id` continua no API; nenhum job novo).

## Open Questions

- Nenhuma que bloqueie specs ou tasks: lista exata de páginas Admin com falha só via toast será confirmada na implementação grep-driven (escopo já inclui “listas Admin de painel” da auditoria).

**Follow-up opcional (fase 3):** change `extract-selection-bar` ou similar quando SelectionBar duplicada for priorizada.
