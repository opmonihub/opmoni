# Dashboard template consistency audit

Date: 2026-10-23. Product source: `/home/obsidian/dev/opmoni`, including the existing uncommitted sidebar/context changes. Visual authority: `.dashboard/app`, especially `layouts/default.vue`, `pages/settings.vue`, `pages/settings/index.vue`, and `pages/customers.vue`. This is a read-only investigation for a later refactor. Only audit artifacts were added; application code, configuration, dependencies, data, and release gates were not changed.

The application still uses the correct Nuxt UI foundation. Most drift comes from independently defined navigation models, repeated framing text, and task-specific forms placed inside browsing surfaces. Existing product functionality is broader than the demonstration template; that difference is legitimate. Template composition, hierarchy, and interaction conventions are the reference, rather than literal sample routes, English copy, fixture data, or field counts.

## Coverage and confidence

- Three independent specialists worked from isolated copies of the current source under `.worktrees/template-consistency-audit-20261023T215943Z/{layout,navigation,copy}`. They did not edit product files or operate the browser. The coordinator reviewed the evidence and owned browser inspection. The navigation specialist received one focused follow-up about Fiscal hash handling.
- Source inventory: 90 page files, 144 Vue components, and 56 utilities. Every path was indexed; contextual review concentrated on relevant consumers and shared sources. This is not a claim that every line or every runtime state was reviewed.
- Copy triage found 266 literal description occurrences, including 41 longer than 120 characters. These include conditional and dormant text and exclude some paragraph/interpolated content. They are not 266 defects or rendered descriptions. The contextual copy report identifies 12 concrete examples.
- Paseo browser inspection covered company settings, office settings, Finance, Sales, Reports, office Fiscal processing, and ERP Fiscal. Desktop used 1366×900; mobile checks at 390×844 covered company/office settings and the short Fiscal processing state. This is representative inspection, not full visual acceptance of all pages.
- Four local probes exercised the real sidebar builder for narrow permissions. No new users or permissions were created. No backend authorization suite or restricted-user browser flow was run.
- The detector completed with one warning about Arial in the label-print renderer. It does not explain the user's reported problems and is not an actionable reason to change the dashboard typeface. Semantic evidence and rendered behavior drive the findings below.
- No application test suite, lint, typecheck, build, fiscal canary, transmission, SERPRO call, or other live-provider acceptance was run for this documentation-only audit. Prior passing checks are not treated as validation of these findings.
- Snapshot hashes matched current `frontend/app`, `frontend/shared`, and `.dashboard/app` at consolidation: zero source differences.

Severity: P1 = a significant interaction failure with a concrete user consequence; P2 = a recoverable usability/consistency issue; P3 = lower-impact presentation debt. The consolidated list contains one P1, ten P2, and two P3 findings. A separate unconfirmed scrolling risk is not counted as a reproduced defect.

Subsequent user-requested extension: a fourth specialist reviewed forms against native contracts, canonical specs and both legacy references. Its nine entries (F1–F9) are in [form-findings.md](form-findings.md), including a new-requirement gap rather than only existing defects; they are not added to the original thirteen-finding count. The coordinator inspected the settled product-create drawer at 1280×800 and 390×844 without submitting, and reviewed native provisioning/default consumers. See [forms and defaults plan](forms-and-defaults-plan.md) for V07–V09 and their separate functional scope. Product source was at `d2763b8` for this extension; the original snapshot manifest below remains the record of the earlier audit.

## Findings ordered for the next refactor

### A01 — P1: Fiscal sidebar destinations do not select their corresponding area

**Evidence:** `frontend/app/components/erp/ErpFiscalWorkspace.vue:5` initializes local `activeArea` to `documents`; line 6 initializes `selectedModel` to `55`. Lines 23–26 define local areas, and lines 299–310 render buttons that update only local state. Neither component nor fiscal composable binds these areas to route hashes. The sidebar uses the separate contract in `frontend/app/utils/erpModulesNav.ts:261`, containing NF-e/NFC-e/CT-e/MDF-e and certificate/event destinations.

**Reproduced:** opening `/erp/fiscal#certificates` in a fresh page left the body on “Documentos fiscais” and the local Documentos button selected, despite the sidebar linking to Certificados. Source also shows that `#cte` does not select model 57 or filter the list: the draft model starts at 55 and the list is not model-filtered. A CT-e creation/transmission flow was not executed.

**Impact:** navigation advertises one destination while the working surface shows another. This is more consequential than a spacing difference, especially around fiscal models.

**Template comparison:** `.dashboard/app/pages/settings.vue:4` and `.dashboard/app/layouts/default.vue:37` use actual destinations corresponding to the rendered child route.

**Refactor direction:** establish one explicit mapping between route/hash, permitted area, and model; use it for sidebar, search, local navigation, and reload/history. Include the existing Configuração destination, which is currently absent from the sidebar contract. Preserve model-specific builders, recovery identities, authority resets, explicit confirmations, and all production gates. Do not transmit anything as part of a visual refactor. See navigation finding N7.

### A02 — P2: Settings/catalog toolbar uses a sidebar-style submenu projection

**Evidence:** `frontend/app/utils/erpWorkspaceNav.ts:138` returns `[[EmpresaTrigger], [OperaçãoTrigger], ...]`, with children, from `destinationItems`. `frontend/app/pages/erp/configuracoes.vue:25` sends it directly to horizontal `UNavigationMenu`. `frontend/app/pages/erp/cadastros/index.vue:23` uses the same shape.

**Reproduced:** at 1366px, the settings nav was 1086px wide, with `justify-content: space-between` and four distributed category buttons. At 390px it became two rows, increasing from 48px to 102px high. The specific destination remains behind a disclosure; this is distinct from the direct local links the reference shows.

**Template comparison:** `.dashboard/app/pages/settings.vue:4` keeps related direct route links in one primary array; lines 38–40 render that array in the toolbar. Its separate Documentation group is intentional secondary content, not a model for spreading every local category.

**Refactor direction:** retain the authorized registry but create a dedicated toolbar projection with direct links in one group. Use a deliberate visible destination set and the native horizontally scrolling toolbar for narrow viewports. Decide between all authorized settings destinations and an active-group subset before implementation; do not turn 15 entries into several unbounded rows. A CSS alignment override alone leaves the extra interaction layer. See N1.

### A03 — P2: Catalog navigation exists only on the hub and disappears inside catalogs

**Evidence:** local navigation is defined only by `frontend/app/pages/erp/cadastros/index.vue:14`; individual pages such as `cadastros/produtos.vue:23` render `ErpCatalogPageShell` without its toolbar slot. `ErpCatalogPageShell.vue:28` renders local navigation only when a page supplies that slot. There is no persistent `pages/erp/cadastros.vue` parent.

**Impact:** selecting a catalog removes the local chooser, and moving between products, brands, and units requires returning to the hub or global sidebar. The mobile drawer adds another step.

**Template comparison:** `.dashboard/app/pages/settings.vue:30` keeps the section shell and toolbar around its nested child pages.

**Refactor direction:** choose a persistent owner for local catalog navigation, using the existing shell or nested-page pattern. Avoid nesting a second dashboard panel around panels already owned by children. Keep URLs, granular grants, creation actions, and mobile close/focus behavior. See N2.

### A04 — P2: Existing stock cost-layer destination is absent from sidebar/search

**Evidence:** `frontend/app/components/erp/ErpStockWorkspace.vue:12` includes `lots / Camadas de custo` and its read grant. `frontend/app/utils/erpModulesNav.ts:182` and `erpWorkspaceNav.ts:195` omit that destination. Search is derived from sidebar children at `erpWorkspaceNav.ts:314`.

**Local probe:** an active viewer with only `erp.stock.lots.index` receives an empty sidebar, despite the destination being supported by the stock workspace.

**Refactor direction:** use one permitted destination definition for local navigation, sidebar, and search; include existing `#lots`. Preserve deep links, first-permitted fallback, cost-layer semantics, and SSR hash handling. This finding was verified by source and a real-builder fixture, not by creating a restricted account. See N3.

### A05 — P2: Finance/Cash toolbar and default area disagree with sidebar permissions

**Evidence:** `frontend/app/components/erp/ErpFinanceWorkspace.vue:89` maps every static tab without the read-grant filtering used by `erpWorkspaceNav.ts:235`. Its default at line 29 is Obrigações. `ErpCashWorkspace.vue:9` defaults to Caixas and lines 13–18 expose both areas without filtering.

**Local probes:** the sidebar correctly exposes only Pagamentos for a payments-only viewer and only Sessões for a sessions-only viewer. The local toolbar/default selection is derived from a different list.

**Impact:** a user can enter a denied default area or see unavailable options while another area is usable. Backend read checks remain in place; no authorization bypass is claimed.

**Refactor direction:** derive visible options and permitted default from the same registry, preserving explicit denial feedback for revoked/direct deep links, history, granular writes, and operation recovery. See N4.

### A06 — P2: Settings root always redirects to Empresa, even for other permitted destinations

**Evidence:** `frontend/app/pages/erp/configuracoes/index.vue:2` unconditionally redirects to Empresa. The real sidebar builder permits Estabelecimentos-only access, and `useErpCompanySettings.ts:15` separately requires the company-settings grant.

**Impact:** opening the section root can lead to a denied form while an authorized settings destination exists.

**Refactor direction:** resolve the first permitted child from the shared settings registry, using SSR-compatible replacement navigation. Preserve denial for an explicitly unauthorized child URL. See N6.

### A07 — P2: Office Settings is a useful structural reference but has language/order drift itself

**Evidence:** `frontend/app/pages/settings.vue:5` manually repeats General/Members/Notifications/Security, while `frontend/app/utils/settingsNav.ts:11` already defines Portuguese labels and shared projections. `frontend/app/layouts/default.vue:49` consumes the helper then appends Members separately. The same route changes name and position between sidebar and toolbar. `settings/index.vue:74` and later fields retain English labels/descriptions alongside the explicit Portuguese demo state.

**Reproduced:** Settings, General, Members, Notifications, and Security remain visible in English. At 390px, the seven-link menu is wider than the viewport, but its DashboardToolbar ancestor provides native horizontal scrolling; this is not a demonstrated clipping failure.

**Refactor direction:** reuse the existing registry, add Members at one agreed position, and localize visible labels consistently. Copy the reference structure rather than its sample English text or fixture functionality. Preserve the explicit statement that the profile screen is a demonstration. See N5 and C8.

### A08 — P2: Company settings flattens 35 fields from several tasks into one undifferentiated form

**Evidence:** `frontend/app/utils/erpCompanySettingsFields.ts:14` mixes identification, commercial preferences, calendar, address/contact, and fiscal numbering/credentials. `frontend/app/components/erp/ErpSettingsSectionForm.vue:148` renders the array as one card with repeated separators.

**Reproduced:** company settings renders 35 labeled fields. The generic introductory description wraps onto two lines while providing no rule or consequence beyond the page purpose.

**Template comparison:** `.dashboard/app/pages/settings/index.vue:57` names one coherent task; `settings/security.vue:27` and `:60` split distinct tasks into separate sections. Five demo fields is not a product limit.

**Refactor direction:** introduce optional meaningful field groups in the existing form: company identification, address/contact, preferences, fiscal parameters. Retain one state and save payload initially. Keep metadata, field errors, all keys and numeric/checkbox types, authority invalidation, fiscal environments, and document series. Raw legacy ID replacement requires its own data-contract work. See L2.

### A09 — P3: Page identity, active tab, and introductory text are repeatedly stacked

**Evidence:** `frontend/app/components/erp/ErpCatalogPageShell.vue:34` adds an intro before every workspace that supplies a description; 21 call sites use the shell. Finance repeats the active area as a body heading at `ErpFinanceWorkspace.vue:339`, Cash at `ErpCashWorkspace.vue:240`, and Sales at `ErpSalesWorkspace.vue:608`. Nine pages consume `DataTablePanelList`; Admin Users repeats Usuários in navbar, active navigation, and body header (`pages/admin/usuarios.vue:89`, `components/data-table/PanelList.vue:38`). Office Fiscal processing displays the parent heading “Painel” and child heading “Processamento fiscal”.

**Impact:** repetitive framing competes with actual filters, counts, and records. Many titles are short individually; the combined hierarchy creates the noise.

**Template comparison:** `.dashboard/app/pages/customers.vue:212` identifies the page in the navbar and moves into controls at line 224. A nested task heading such as Profile in settings adds distinct information and should remain.

**Refactor direction:** define one module identity, native active navigation, and a compact working toolbar. Keep distinct task/section headings and an accessible name for each table. Descriptions should explain a decision, consequence, or recovery step; optional ones can already be omitted from the shell. Do not remove warnings or every heading by a global rule. See L5, C1, C2, C8.

### A10 — P2: Engineering vocabulary and generic instructions leak into normal product work

**Evidence:** Reports visibly consumes the `ledgers` description at `frontend/app/utils/erpModulesNav.ts:298` through `ErpReportsWorkspace.vue:122`. Accounting describes documents as “projetados no histórico” (`pages/erp/contabilidade/index.vue:8`); membership management says “identidades existentes” (`ErpCompanyAdministration.vue:394`). The shared catalog editor at `catalogs/ErpCatalogResource.vue:430` repeats generic create/edit instructions across 13 resource instances. Fiscal client summary says an aggregate was not “publicado” (`pages/fiscal/clientes.vue:477`).

**Impact:** users must translate implementation details or read instructions that simply restate a button. Some explanations contain important accounting/fiscal rules, which should be rewritten and placed near the affected decision rather than removed.

**Refactor direction:** apply the PT-BR copy examples below, starting with shared consumers. Treat dormant registry text separately: `ErpModuleHub` has zero consumers, so latent `commit`, `retry`, and `actor` strings are not all current visible defects. See C3–C12.

### A11 — P2: Company administration rebuilds standard controls and inserts large editors above lists

**Evidence:** `frontend/app/components/erp/ErpCompanyAdministration.vue:426` places its open editor before the list; lines 443–568 use styled native inputs/selects/radios/checkboxes. Lines 698–706 expand effective permission names into badges. The component serves Membros and Estabelecimentos.

**Template comparison:** `.dashboard/app/components/customers/AddModal.vue:26` uses an action-triggered Nuxt UI form; `components/settings/MembersList.vue:20` keeps member rows compact. The existing ERP catalog slideover is another product-local example.

**Refactor direction:** keep the administration state and permission logic; reuse Nuxt UI form controls and the established on-demand editor pattern. Summarize grants in list rows and expose full details on demand. Preserve direct-owner restrictions, protected owner handling, identity lookup, custom/default grants, establishment scope, field errors, context fences, and stale-data mutation blocking. No current accessibility failure is asserted solely from raw controls. See L3.

### A12 — P2: Fiscal browsing includes the entire creation form by default

**Evidence:** `frontend/app/components/erp/ErpFiscalWorkspace.vue:361` renders the create card whenever create permission and configuration exist, without a user action opening it. The list has a separate scroll cap at line 345; selected detail follows the list/create column at line 398.

**Impact:** reviewing documents competes with a long fiscal draft workflow, especially when desktop columns become one mobile column.

**Template comparison:** `.dashboard/app/pages/customers.vue:217` keeps creation as an action, and the template Inbox separates browsing/selected detail.

**Refactor direction:** make Novo rascunho an explicit action that opens the existing editor in an appropriate overlay or dedicated surface. Keep browsing as the resting state. Preserve model builders, drafts, idempotency/recovery keys, validation, authority changes, gates, and irreversible-action confirmations. This placement is source-confirmed; a fully configured live creation state was not exercised. See L4.

### A13 — P3: Sales defines a separate navigation/list/pagination grammar

**Evidence:** `frontend/app/components/erp/ErpSalesWorkspace.vue:566` implements route areas with solid/outline buttons; rows at line 627 form a custom desktop grid without column headings, and line 644 implements separate previous/next pagination. Finance, Cash, and Purchases use highlighted `UNavigationMenu` for the equivalent navigation level.

**Reproduced:** Sales shows four small action-style area buttons and a second h1 in the body; the areas have no native link href. This differs from the reference's route navigation and nearby modules.

**Refactor direction:** first adopt the existing navigation projection while retaining hashes and permitted areas; then align the desktop list/footer with existing table tokens and pagination. Preserve compact mobile rows where useful, detail overlays, conversions, returns, payment grants, revision conflicts, and recovery semantics. New filters or sorting are not required for consistency. See L6.

## Copy changes to prepare

The complete current strings and proposals are in `copy-findings.md`; these are the most useful starting points. Shortening is not a character-limit exercise.

| Source | Current text | Proposed treatment |
| --- | --- | --- |
| `pages/erp/configuracoes/empresa.vue:10` | Mantenha os dados cadastrais, o endereço e os parâmetros fiscais da empresa. | Omit the introductory sentence; retain the distinct task title and meaningful field groups. If needed: “Cadastro, endereço e dados fiscais.” |
| `pages/erp/cadastros/marcas.vue:20` | Marcas comerciais disponíveis para os produtos da empresa. | Omit; Marcas, Adicionar marca, and Buscar marca already name the task. |
| `components/erp/catalogs/ErpCatalogResource.vue:431` | Preencha os dados e salve para atualizar o cadastro. | Omit the generic visible instruction; retain proper dialog labeling/description semantics and record-specific help. |
| `utils/erpModulesNav.ts:298` | Relatórios reconciliados aos ledgers lançados; rascunhos e tentativas fiscais indeterminadas não inflam totais. | “Os totais consideram lançamentos confirmados. Rascunhos e transmissões fiscais sem resultado confirmado ficam de fora.” Place near totals/help. |
| `pages/erp/contabilidade/index.vue:8` | Acompanhe documentos fiscais autorizados que foram projetados no histórico do escritório delegado. | “Acompanhe a entrega de documentos fiscais autorizados ao escritório.” Retain actual delegation/delivery states. |
| `components/erp/ErpCompanyAdministration.vue:394` | Gerencie o acesso de identidades existentes à empresa e aos seus estabelecimentos. | “Gerencie o acesso de usuários já cadastrados à empresa e aos estabelecimentos.” |
| `pages/admin/usuarios.vue:91` | Pessoas com acesso ao sistema. | Omit the definition and duplicated body title when the parent identifies Usuários. |
| `pages/fiscal/clientes.vue:477` | O agregado de documentos por cliente ainda não foi publicado. A tabela de documentos continua funcionando normalmente. | “Consulte os documentos na aba Documentos.” Keep a truthful unavailable-summary state; do not assert another service is healthy without evidence. |
| `components/customers/ClientPortfolioEmpty.vue:45` | Não há clientes que correspondam à busca ou aos filtros aplicados. Ajuste os critérios para continuar. | “Ajuste a busca ou limpe os filtros.” |

Always preserve fiscal consequence/manifestation guidance, production restrictions, stale-data and failed-save recovery, required input constraints, private credential behavior, and demo-state truthfulness. Helpful copy belongs next to the action it informs. Long legal/business warnings may need placement and hierarchy changes, not deletion. This audit does not independently validate legal deadlines.

## Separate risk requiring populated acceptance

**R01 — Fiscal processing scroll ownership:** `frontend/app/pages/fiscal.vue:22` sets an overflow-hidden body; `pages/fiscal/processamento.vue:123` supplies a plain non-scrolling root and can append rows/pagination at lines 178–204. Sibling pages use explicit scroll contracts from `utils/pageShell.ts`. The short/empty live state fit at desktop and mobile. A populated 15-row page and its last pagination control were not tested, so clipping remains a source-derived risk rather than a confirmed defect. Validate before changing the shared Fiscal shell; a child-level scroll token may be sufficient.

## Refactor order and acceptance boundaries

The user extended the scope to require the same color theme, typography, formatting, and proportions as `.dashboard` and established Opmoni surfaces across `frontend/app/pages/erp`. The [visual alignment plan](visual-alignment-plan.md) records source evidence, shared-token decisions, V01–V06 tasks, and measurable acceptance criteria. The ERP-only light-mode token overrides in `frontend/app/assets/css/main.css:21` are a direct source of palette divergence, while the base app configuration/font already match the reference. The earlier contrast correction must be reevaluated with evidence as part of this explicitly requested alignment. V06 adds one fully expanded sidebar group at a time across both menu regions, matching the office sidebar's manual-fold and pinned-active-child behavior. The current ERP watcher appends groups and its native menus default to multiple expansion. These additions are planned work; no product styles or navigation code were changed.

The user additionally confirmed that initial setup includes **settings and basic catalogs**, then requested simple product registration with reusable tax configurations and product-specific exceptions. V07–V09 now connect this scope to the visual roadmap. The native product API already permits missing fiscal data; the legacy prefills company defaults, whereas linked dynamic profiles require a new native contract. Preserve that distinction and the existing frozen-sale/fiscal source checks.

0. **Shared visual foundation:** V01/V02 from the visual alignment plan establish reference tokens and component roles before broad restyling. V03–V05 align page-family proportions and validate light/dark, responsive, and cross-area behavior. A01 remains the highest-priority interaction fix.
1. **Navigation correctness and reference composition:** A01–A07 and V06. Use one permitted destination source with explicit sidebar/toolbar/search projections and the established single-group expansion policy. Start with Fiscal hash correctness and the reported company-settings toolbar. Keep native dashboard components and current routes; do not add a parallel navigation system.
2. **Shared hierarchy and copy:** A08–A10. Group company fields, remove redundant framing at consumers, translate leaked engineering language, and align Office Settings localization. Preserve distinct task headings and necessary warnings. Review screenshots before applying any shared-component default globally.
3. **Editors and operational list consistency:** A11–A13, plus the populated R01 check. Adopt existing on-demand editor/table/footer patterns without changing business state ownership.
4. **Forms, usable initial setup and reusable taxation:** V07–V09 in the [extension plan](forms-and-defaults-plan.md). Start with the short product flow and lossless CST defaults, establish native baseline units and active default consumers, then add explicit tax-profile resolution and snapshot/readiness integration. Do not silently import demo data, infer legacy IDs or rewrite existing products/posted operations.

Relevant existing checks to use when implementation begins include `erpWorkspaceNav.test.ts`, `erpModulesNav.test.ts`, `erpSidebarNavigation.test.ts`, `erpMobileShell.test.ts`, `settingsNav.test.ts`, `erpCompanySettingsFields.test.ts`, `erpCompanySettingsApi.test.ts`, `erpFinanceWorkflows.test.ts`, `erpCashWorkflows.test.ts`, `erpStockWorkflows.test.ts`, `erpSalesWorkflows.test.ts`, and `erpFiscalWorkflows.test.ts`. Add targeted cases for Fiscal hash selection/model correspondence, lots-only visibility, first-permitted defaults, and settings root resolution. Passing existing tests alone will not prove the missing cases.

Browser acceptance must cover desktop/mobile, reload/deep link/back-forward, owner/delegated/restricted grants, empty/populated/error/stale states, focus return, long forms, and pending-operation recovery. Visual changes do not constitute fiscal issuance, live-provider, concurrency, or production-release acceptance.

## Evidence and provenance

- `layout-findings.md`: independent layout/component specialist, six detailed findings and strengths.
- `navigation-findings.md`: independent navigation specialist, seven findings including the Fiscal follow-up.
- `copy-findings.md` and `copy-inventory.json`: independent copy specialist, exact strings/proposals and lexical source inventory.
- `form-findings.md`: independent forms/contract specialist from isolated `.worktrees/erp-forms-reference-audit-20261023T222125Z`; nine findings, reference-family coverage and minimal/optional/fiscal product-field matrix, reviewed by the coordinator.
- `forms-and-defaults-plan.md`: V07–V09, including coordinator-verified provisioning/default gaps and the new reusable product tax-configuration contract.
- `forms-browser-evidence.json`: non-submitting product-create desktop/mobile measurements, settled opaque overlay, explicit verification limits and task-tab cleanup.
- `browser-evidence.json`: runtime route, viewport, heading, navigation, and field measurements; no input values or credentials.
- `navigation-probes.json`: four real-builder narrow-permission fixtures and explicit verification limits.
- `detector.json`: completed mechanical scan. Its single print-renderer font warning was inspected and excluded as unrelated to dashboard consistency.
- `source-manifest.json`: source commit/status at snapshot creation. Existing dirty work was preserved.
- Models requested for the independent workers: `gpt-6.1-sol`, high effort for layout/navigation and medium for copy. Model/effort dispatch requests are recorded by the collaboration tools; provider-side routing/usage telemetry was not available, so no cost or routing claim is made.
- Browser backend: requested Paseo workspace tools. The initial audit used task-created tab `70a69c90-d1fd-41d5-bb67-e0b54b5f8bf6`; the forms extension used task-created tab `882f8c89-4014-44dd-b72e-d2300896d937`, subsequently closed and verified absent. User tabs were left untouched. No local browser/server installation or restart was needed.
