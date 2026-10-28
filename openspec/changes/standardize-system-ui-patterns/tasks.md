## 1. Baseline and implementation boundaries

- [x] 1.1 Reconcile the existing system inventory against current frontend consumers and create the capability ledger defined in design decision 3, including each consumer's query mode, sort/default, paging, hierarchy, selection, actions and exceptions; verify every operational list consumer has an entry and unsupported capabilities have reasons or separate follow-ups.
- [x] 1.2 Record current Laravel ordering, validation and response-envelope contracts for the three query families, including no-sort defaults and CFOP resolution; verify the recorded fixtures against existing catalog, commercial and platform tests before changing queries.
- [x] 1.3 Record the installed Nuxt UI APIs and official examples used for shared composition, expansion, grouping, modal footer and form errors; verify compatibility with the installed version and retain links in the implementation evidence.
- [x] 1.4 Establish bounded frontend ownership groups and a baseline reference-capture matrix, preserving interrupted worktree drafts and unrelated files; verify parallel implementation uses isolated worktrees and shared primitives have one owner before consumers migrate.

## 2. Shared frontend composition

- [ ] 2.1 Align shared page heading/action placement and content measures through existing page-shell utilities; verify a Settings form, an operational list and a dense workspace retain their distinct width/scroll needs in desktop and mobile captures.
- [ ] 2.2 Consolidate shared table-panel search/filter/sort/column/action presentation using existing data-table components, adapting OfficeErpPanel only where slot contracts match; verify existing consumers retain accessible controls and no duplicate toolbar or unnecessary empty spacing.
- [ ] 2.3 Reconcile overlapping table geometry utilities while retaining Work grouped-row and dense-table behavior; verify representative ordinary, grouped and financial tables in both themes at desktop and narrow-panel widths.
- [ ] 2.4 Share selection count/clear/action presentation where equivalent while retaining domain-owned policies; verify portfolio snapshot exclusions, Work eligible-leaf selection and monitoring loaded-record selection remain distinct and accurately labeled.
- [ ] 2.5 Align form field rows, validation summaries and footer actions with the Settings reference using existing Nuxt UI forms; verify stacked mobile controls, accessible error association and focus on invalid submission without introducing a form generator.

## 3. Authoritative catalog ordering in Laravel

- [x] 3.1 Extend company-catalog list validation with the design's resource-specific sort/direction allowlists; verify supported fields, default ascending direction, direction-without-sort and invalid values through focused request tests.
- [x] 3.2 Apply catalog ordering within existing authorized Company/establishment queries before pagination, using stable identifier ties and nulls last; verify names, carrier identity, vehicle plate and CFOP ordering across multiple pages with correct counts.
- [x] 3.3 Protect omitted-order defaults, envelopes, archived references and explicit/default CFOP resolution; verify regression tests show sorting cannot change reference selection or permit foreign-scope reads/writes.

## 4. Authoritative commercial ordering in Laravel

- [x] 4.1 Add validated ordering for office clients, offers and contracts within OfficeErpQueries and existing commercial readers; verify allowlists, related-client ordering, equal-value ties, counts, old defaults and envelopes with focused tests.
- [x] 4.2 Add validated ordering for invoices and receipts using stored date/identity fields; verify ordering across pages, nullable references, rejected unsupported fields and unchanged exact amounts, balances and no-sort ordering.
- [x] 4.3 Exercise current-office and permission boundaries for all newly sortable commercial resources; verify foreign/denied requests preserve existing response precedence and never create new commercial command or bulk permissions.

## 5. Authoritative platform ordering in Laravel

- [x] 5.1 Extend Admin Account, global-user and subscription validators/readers with allowlisted ordering and deterministic ties; verify multi-page identity/status ordering and the Account-specific versus resource pagination envelopes.
- [x] 5.2 Preserve platform authorization, read-only global users, raw plan arrays and fixed summary/support-log ordering; verify omitted-sort regressions, unsupported-sort 422 cases and ordinary-member denial with focused platform tests.
- [x] 5.3 Verify the new query families on isolated PostgreSQL for null placement, equal values, related-field ordering and pagination counts; record the actual database evidence separately from SQLite tests and use independent PostgreSQL/Redis processes for any concurrency checks.

## 6. Frontend sort and navigation ownership

- [ ] 6.1 Extend existing list criteria codecs and query identities with supported sort/direction, including reset-to-omitted behavior; verify tests cover valid URL precedence, invalid remembered values and exact restoration of legacy defaults.
- [ ] 6.2 Connect company-catalog headers to authoritative ordering and stable domain row IDs; verify a dataset spanning server pages follows the selected ordering and unsupported headers expose no misleading sort control.
- [ ] 6.3 Connect office-commercial list/card ordering controls to the supported API fields; verify retained ordering on same-authority detail round trips, one pagination reset per change and unchanged search/filter aliases.
- [ ] 6.4 Connect Admin sortable lists through their existing response adapters and keep bounded local plan ordering distinct; verify correct counts, reset defaults and read-only action availability.
- [ ] 6.5 Extend lifecycle coverage for rapid sort/filter changes, pending reads and authority loss; verify superseded responses cannot replace current results or clear another request's loading state, hydration does not duplicate initial reads, and selections/drafts are never restored from navigation memory.

## 7. Commercial reference pilot and expansion

- [ ] 7.1 Align the commercial workspace shell and page headings with shared composition while preserving all routes and one page identity; verify direct entry, keyboard navigation, both themes and complete mobile navigation.
- [ ] 7.2 Rework client lists into compact rows with native record expansion and only essential visible facts; verify displaced information/actions remain reachable at 1046px content width and on phones, with expansion attached to stable record IDs after reordering.
- [ ] 7.3 Apply the same native expansion pattern to subscriptions, removing redundant summary copy; verify contract/license facts, optional owner setup and existing permitted actions remain available in desktop/mobile views.
- [ ] 7.4 Present commercial plan management as subtle horizontal cards with module disclosure and existing search/status/paging/actions; verify price, cycle, revision and availability remain clear without widening the list or changing published-plan semantics.
- [ ] 7.5 Align commercial invoice/receipt filters, tables and overview/settings sections with shared controls; verify complete financial queries, record actions, exact amounts and distinct loading/empty/error/recovery states through existing focused tests and browser flows.
- [ ] 7.6 Compare the commercial pilot with matching dashboard-reference captures and record business exceptions; verify heading, measure, spacing, cards and controls in the paired matrix before copying the composition into further modules.

## 8. Bounded modal editor migrations

- [ ] 8.1 Migrate OfficeErpPlanEditor from slideover to native modal using the existing form and command owner; verify field errors, revisions/modules, external footer submission, pending-close guards and original-command recovery.
- [ ] 8.2 Migrate administrative plan editing to the shared modal composition; verify permitted fields, save errors, dirty dismissal and focus return without changing plan effects or authorization.
- [ ] 8.3 Migrate administrative subscription editing to the shared modal composition; verify consequential action explanations, busy state, errors and authority changes using its current command contracts.
- [ ] 8.4 Migrate ErpCatalogResource create/edit modes to a modal while retaining the existing read-only inspector distinction; verify tabs, validation, archive confirmation, dirty-state review, authority loss and all catalog host consumers.
- [ ] 8.5 Verify nested product dialogs, discard confirmations and editor save/close races across the migrated hosts; browser-check focus containment/return, escape and backdrop handling, mobile scrolling and a single active host's close listeners.

## 9. Portfolio, Work, monitoring and fiscal consumers

- [ ] 9.1 Align portfolio list controls and row details with the shared composition; verify supported filters/sorting, stable IDs, all-matching snapshots/exclusions and existing authorized bulk outcomes remain intact.
- [ ] 9.2 Align Work grouped lists with shared controls while retaining the existing grouped-row utility; verify group expansion, indeterminate selection, eligible leaves and independent per-task partial results without submitting synthetic group IDs.
- [ ] 9.3 Align Work model/process page forms with the shared field/action hierarchy; verify existing long workflows, dirty state, server validation and mobile completion remain intact.
- [ ] 9.4 Align monitoring list presentation and existing filter/selection feedback without expanding its query or bulk contract; verify selection remains limited to its supported loaded-client search scope and pending/error states remain distinct.
- [ ] 9.5 Align fiscal processing/list controls and existing detail disclosure with the shared composition; verify existing filters, exports, document inspection and authority checks, preserving fiscal inspection containers and separating exports from mutation actions.

## 10. Administrative, settings and company ERP consumers

- [ ] 10.1 Align Admin Account/global-user/subscription lists with shared composition and capability-aware controls; verify no global-user writes or implicit Super Admin tenant access appear and fixed audit/summary views retain their behavior.
- [ ] 10.2 Align team and business Settings sections with the shared field/card/action hierarchy; verify existing membership limits, permission behavior, save feedback and mobile editing while preserving demonstration-only settings semantics.
- [ ] 10.3 Align company ERP catalog pages with shared list and modal hosts; verify each capability-ledger entry against its resource's search/status/order/paging and establishment/reference behavior rather than assuming identical columns.
- [ ] 10.4 Align company sales and purchase operational lists/forms with shared composition; verify existing item editing, exact-value totals, validation and permitted document commands on desktop and mobile.
- [ ] 10.5 Align company stock and transfer operational lists/forms with shared composition; verify existing warehouse/establishment scope, row actions and transaction behavior with focused domain checks.
- [ ] 10.6 Align company finance lists and workflows with shared controls and intentional table containment; verify exact amounts, permitted commands, unknown-outcome recovery and reachable mobile actions without adding bulk financial mutations.
- [ ] 10.7 Align company reports, settings and accounting-delivery presentation where the ledger identifies useful shared controls; verify report facts, export behavior and the functional delivery table remain intact, preserving accessibility-only tables and intentional report layouts.
- [ ] 10.8 Review remaining home/inbox and task-specific drawers against the ledger and shared shell; verify only applicable presentation changes occur, no mock endpoint is promoted into business behavior and notification/inbox/filter inspectors retain their intended containers.

## 11. Integration acceptance and documentation

- [ ] 11.1 Run focused frontend behavior tests for changed criteria, selection, modal and authority ownership, then the documented frontend lint, typecheck, test and build commands; record actual results and any blocked checks without claiming browser or backend acceptance from static tests.
- [ ] 11.2 Run relevant Laravel query/authorization regressions, then the documented host regular PHPUnit suite with FISCAL_ENVIRONMENT=producao and Pint for changed PHP; record isolated PostgreSQL suite results where relevant and keep provider/live fiscal acceptance separate.
- [ ] 11.3 Complete paired light/dark reference and product captures at desktop, intermediate widths, 1046px content panel and phone widths including the canonical 320px commercial case; verify full tasks, keyboard operation, nested modals, expansion/selection and contained financial scrolling, recording runtime/API limitations explicitly.
- [ ] 11.4 Obtain independent review of changed financial/authority behavior and cross-cutting shared components; resolve material findings and verify fixes with the narrow relevant regression checks before acceptance.
- [ ] 11.5 Reconcile the global design guide, system map, component inventory and capability/exception ledger with the implemented result; verify every proposed consumer is accepted or explicitly pending, unsupported Work/monitoring capabilities remain separate and historical evidence is not rewritten as current acceptance.
- [ ] 11.6 Run strict OpenSpec validation and review the final scoped diff before requesting explicit integration/finalization; verify no unrelated drafts, data, secrets, production gates or automatic worktree cleanup enter the change.
