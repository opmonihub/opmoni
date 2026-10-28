# System UI implementation evidence

Change: `standardize-system-ui-patterns`  
Baseline: `dac7293cb2887914e41db95f531c991935a6611e`  
Status: implementation in progress; no final acceptance or integration claimed.

## Ownership and preservation

The coordinator owns the root planning artifacts and this evidence. Root application sources remain unchanged during initial isolated implementation. Existing interrupted `erp-settings-redesign` and `erp-settings-shell` drafts, unrelated `reference/sistema/` and existing environment files are preserved. No automatic merge, push or cleanup is authorized by applying the change.

| Execution checkout | Exclusive writer | First scope | Integration rule |
| --- | --- | --- | --- |
| `.worktrees/ui-catalog-ordering` | Catalog worker | Laravel catalog ordering/tests | Review scoped diff before copying into integration checkout |
| `.worktrees/erp-settings-forms` | Commercial/Admin worker | Laravel commercial/platform ordering/tests | Review scoped diff before copying into integration checkout |
| `.worktrees/erp-management-density` | Shared frontend worker | Shared components, geometry and navigation criteria/tests | Integration checkout; no concurrent consumer writer |

Additional worktree creation was rejected; existing clean checkouts are reused. Consumer waves wait for shared interfaces and paired pilot acceptance. Each checkout has one implementation owner at a time. Workers must not delegate. Worker dispatch requested `gpt-6-luna`, medium; runtime routing telemetry is not confirmed.

## Capability ledger

The [list ledger](list-capability-ledger.csv) enumerates literal table/host/directory consumers, including separate tab-table declarations and all shared catalog hosts. The source binding and path make each row traceable. Shared host rows and their consumers are intentionally both present. It complements the prior declaration inventory rather than changing its counting method.

Baseline-source entries distinguish current server/local/preview/review modes, supported controls, missing useful ordering, selection scope and operations that are not applicable or separately scoped. They are not browser acceptance. Each implementation wave must replace its pending status with exact changed behavior and evidence; controls cannot imply a capability absent from its query owner.

## Component compatibility evidence

Installed `@nuxt/ui`: **4.11.3**, verified from the resolved package directory. Official Nuxt UI MCP metadata read for Table, Modal, PageCard, Form and FormField. Example source read for `TableRowExpandableExample`, `TableTreeDataExample`, `TableGroupedRowsExample`, `ModalFooterSlotExample` and `FormExampleOnError`.

| Contract | Official source | Installed integration boundary |
| --- | --- | --- |
| Record expansion and stable identities | [Table](https://ui.nuxt.com/docs/components/table#examples), `TableRowExpandableExample` | `expanded` state / expanded slot, `getRowId`; retain domain IDs |
| Grouping versus genuine tree data | [Table](https://ui.nuxt.com/docs/components/table#examples), grouped/tree examples | Existing Work grouping remains; `getSubRows` only for true children |
| Modal editor and footer | [Modal](https://ui.nuxt.com/docs/components/modal), `ModalFooterSlotExample` | Native dialog focus/dismissal, existing external form ID and busy guards |
| Field errors and focus | [Form](https://ui.nuxt.com/docs/components/form), `FormExampleOnError` | Existing schema/state/server errors; focus first invalid control |
| Subtle Settings composition | [PageCard](https://ui.nuxt.com/docs/components/page-card), local `.dashboard/app/pages/settings` | Native variants/slots; task-specific content measure and existing semantics |

Examples demonstrate APIs only. Their mock data, floating-point formatting and index-based example expansion do not replace product precision, authorization or stable identity contracts.

## Reference acceptance matrix

Each pair uses the same theme, sidebar state, zoom and font readiness. Initial screenshots are pending. Capture batches cover desktop and mobile together; permit one material correction batch and one confirmation under the bounded Impeccable process.

| Surface | Reference | Widths / themes | Required behaviors |
| --- | --- | --- | --- |
| Shared list and commercial clients/subscriptions | Settings Members plus official expanded Table | 1440px desktop, 1046px content panel, 768px intermediate, 390/375/320px phones; light/dark | Primary action, filters, expansion, stable row identity, reachable displaced facts/actions |
| Settings and commercial configuration | Settings Profile | Same matrix | Aligned field rows, stacked mobile fields, short useful copy, errors and save feedback |
| Plan/catalog modal | Official Modal/Form and existing product modal | Desktop and phones; light/dark | Dirty discard, nested dialogs, keyboard containment/return, invalid submission, pending dismissal and authority loss |
| Dense Work/fiscal/finance | Corresponding table examples plus existing domain workspace | Desktop, intermediate, phones; light/dark | Scope-aware selection, grouping/partial results, intentional contained scrolling, exact facts and recovery |

Simulated API layout captures must be labeled separately from real API/PostgreSQL acceptance. No production provider or fiscal rollout is implied.

## Verification log

- OpenSpec apply reports schema `spec-driven`, ready, initially 0/53 tasks complete.
- Full frontend baseline launched using the exact Node test-runner command from `package.json`; result pending. The host pnpm binary reports 12.4.2 while the project declares 12.11.2, so package-manager execution must be recorded accurately.
- Query defaults/envelopes and focused baseline results are being checked independently by each backend owner before mutation.
- Design clarification: units expose `actual_name` and `short_name`, so they use their existing visible identity fields rather than an invented `name` column. This implements the specified resource-identity rule without changing CFOP or other reference defaults.

- Frontend baseline: 163/164 test files passed in the sandbox; `erpReportExports.test.ts` failed because two real HTTP-listener tests received `listen EPERM`. Its isolated rerun outside the sandbox passed 20/20. No source fix was necessary; baseline result is composed, not a clean unrestricted full-suite run.
- Reviewed backend catalog and commercial/platform diffs copied into the integration checkout; independent authority/query review remains pending. Shared foundation has 24/24 focused tests and scoped ESLint reported passing; visual acceptance remains pending.
