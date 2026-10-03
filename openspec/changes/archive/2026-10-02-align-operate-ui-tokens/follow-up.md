# Follow-up — fases 3–5 da auditoria UI Operate

Registrado pela change `align-operate-ui-tokens`. Nenhum destes itens é
implementado neste PR — são candidatos a change própria (ex.:
`extract-selection-bar`).

- **Fase 3 — SelectionBar compartilhada:** extrair o componente único de barra
  de seleção flutuante hoje duplicado entre Work e Carteira
  (`rounded-xl bg-default shadow-lg ring ring-default`). Risco de regressão em
  bulk actions; exige contrato próprio (props de ações, contagem, dispensa).
- **Fase 3 — SettingsSectionLayout:** unificar o layout de seção repetido nas
  telas de configuração.
- **Fase 4 — Grid de KPIs:** revisão sistemática de grids KPI / `MetricCard`
  (densidade, alinhamento, carga em telas pequenas).
- **Fase 5 — `UCard` ↔ `UPageCard`:** troca global em fichas de registro
  (Work › Modelos, processos, clientes), hoje decidida caso a caso.

### Achados Minor da revisão (fases 1–2)

- `refreshErrorTitle` é configuração morta nas 6 páginas Admin — o toast de
  refresh efetivo é o `toast.add` duplicado na própria página (ex.:
  `frontend/app/pages/admin/contas.vue:71,88`); wording vive em dois lugares
  por página. A limpeza natural é o composable fino do item abaixo.
- Boilerplate `loadError`/`hasLoaded` repetido em 6 páginas Admin (~15 linhas
  idênticas, ex.: `admin/contas.vue:58-71`) — extrair para composable/opção do
  `useRetryableLoad` numa change futura.
- Guarda de scroll em `frontend/tests/pageShellTokens.test.ts:33-36` varre só
  as 2 fichas conhecidas — varrer `app/pages` inteiro fecharia o cenário da
  spec literalmente.
- Regex do guard de `UAlert` em `pageShellTokens.test.ts:78-88`
  (`/UAlert[^>]*@retry/`) não pega retry via `:actions`; alinhar comentário ao
  assert.
- `class="mt-4"` ad hoc no alerta de `frontend/app/pages/admin/index.vue:112`
  — decisão de layout da página de resumo, resolvida pela fase 4 (grids
  KPI/MetricCard).

Estado atual deliberado: a matriz rota → token em `DESIGN.md` e o contrato de
erro/empty já valem para telas novas mesmo antes dessas refatorações.
