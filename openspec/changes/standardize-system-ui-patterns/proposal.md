## Why

Opmoni currently combines several page, table and form compositions for equivalent tasks, so navigation feels inconsistent and some operational lists lack useful sorting, filtering or selection controls. A shared Nuxt UI language, grounded in the local dashboard reference and existing product components, will make office and company workflows more predictable without removing domain behavior.

## What Changes

- Establish common compositions for operational lists, settings/forms, record workflows and reports using existing shared components, semantic tokens and installed Nuxt UI examples. Preserve intentional operational widths and scroll ownership rather than impose one layout on every task.
- Consolidate overlapping list headings, toolbars, filters, identity/details, action menus, selection feedback and table styling. Do not create another table engine or a separate ERP visual system.
- Give operational lists an explicit capability contract covering sorting, filters, paging, row details, hierarchy, selection and meaningful authorized bulk actions. Preserve existing portfolio snapshots, Work eligible-leaf selection and monitoring search semantics.
- Add validated, deterministic server-side sorting to the paginated catalog, office-commercial and administrative resource lists identified in the design. Preserve current default order, filters, authority checks and response envelopes.
- Extend same-authority list navigation to retain supported sort criteria, with truthful loading/error states and bounded request ownership.
- Use native table detail expansion, grouping or parent/child tree data according to the actual domain structure. Preserve Work grouping; do not introduce artificial hierarchy into flat financial records.
- Prefer modal forms for bounded creation/editing, migrating the commercial plan editor, administrative plan/subscription editors and shared company-catalog editor. Preserve long workflows as pages and treat notification, inbox, fiscal inspection and filter drawers separately.
- Migrate consumers in bounded waves and validate reference similarity, complete mobile operation, accessibility, state transitions and regression behavior before consolidating global guidance.

Non-goals: new business modules; new financial/fiscal/licence/admin mass-mutation powers; changed authentication, tenancy or commercial eligibility; changed demo semantics; blanket replacement of cards or all drawers; global theme rollback; new dependencies, production rollout or fiscal/provider changes. No database migration is anticipated.

The existing accepted product decisions, including D009 real-membership isolation, remain authoritative. No accepted product decision is superseded. This proposal replaces the earlier commercial-only composition as a future visual target, not its delivered behavior or historical acceptance evidence. Visual width presets and the administrative plan-card composition are reviewable design choices; they do not authorize new business actions.

## Capabilities

### New Capabilities

- `frontend-ui-patterns`: Shared page/card/form compositions, explicit operational-table capabilities, safe selection/bulk presentation, correct expansion/hierarchy, modal-editor defaults and reference-based acceptance across the system.

### Modified Capabilities

- `frontend-navigation-lifecycle`: Supported sorting participates in remembered list state, URL precedence, pagination reset and authority invalidation.
- `erp-core-catalogs`: Company-scoped catalog lists expose validated global sorting without changing precision, archived references or pagination contracts.
- `office-erp-commercial-management`: The commercial workspace follows common system compositions and modal editors, and its resource lists support validated server-side ordering.
- `platform-administration`: Sortable administrative resource lists preserve global read-only boundaries, fixed audit/summary semantics and existing response envelopes.

## Impact

- Frontend: shared `components/data-table`, `utils/pageShell.ts`, domain presentation utilities, navigation state, existing modal/form hosts, and page families under portfolio, Work, monitoring, fiscal, team, settings, administration, office ERP and company ERP. Home/inbox receive only applicable presentation changes; no real functionality is inferred from template examples.
- Laravel: bounded list validation, authorized query ordering and query-focused tests for company catalogs, office-commercial lists and administrative resource lists. Existing bulk operations keep their current backend contracts; additional bulk products require separate scope.
- Documentation: the system-wide UI map, component inventory and reference-divergence reports supply the baseline. The accepted implementation will reconcile global design guidance and surface-specific exceptions.
- Verification: focused behavior tests, frontend lint/typecheck/build, relevant backend tests/Pint, isolated PostgreSQL checks where database semantics require them, and paired light/dark desktop/mobile captures with keyboard/modal/selection checks. Static inventory and prior screenshots do not establish acceptance of the proposed result.
- Compatibility: additive sort criteria with unchanged omitted-query behavior; no response-envelope, session, database, secret-storage or production-gate change. Interrupted worktree drafts and unrelated untracked files remain preserved.
