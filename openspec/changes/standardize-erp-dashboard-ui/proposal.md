## Why

The current ERP uses the established Nuxt UI dashboard foundation, but the 2026-10-23 audit found a clipped/overlapping workspace selector, accumulated spacing, inconsistent active list styling, and incomplete settings feedback. The refactor should make existing ERP workflows follow the local `.dashboard` reference while preserving Company/Account boundaries and lossless business data.

## What Changes

- Fix the sidebar context block and the mobile finance empty state before changing general styling (audit D01, D08).
- Give each page one owner for its 16px/mobile and 24px/`sm+` outer padding; align catalog search with its table (D02-D03).
- Reuse existing table styling for active consumers and introduce a small controlled list footer; preserve server pagination and specialized report/import/print structures (D04).
- Replace internal migration descriptions with business guidance and connect settings validation errors to named fields (D05, D07).
- Format read-only catalog decimals in Brazilian Portuguese without changing decimal strings, precision, form state, or API payloads (D09).
- Classify each legacy settings identifier before offering labeled selectors. Integrate a selector only when its identifier namespace and source are verified; retain existing editing otherwise and record the exact follow-up dependency (D06).
- Add focused behavioral checks and desktop/mobile browser acceptance against the audit's measured baseline.

### Scope and non-goals

Scope covers the 40 audited ERP page files and their 57 component files, with changes concentrated in their existing shared shells and actual consumers. Preserve routes, supported actions, field meaning, draft/recovery behavior, keyboard/focus behavior, authentication, permissions, and existing reference values.

Do not redesign the office/admin areas, replace native Nuxt UI components with a universal CRUD framework, reactivate the unreferenced `ErpModuleHub`, remove it as cleanup, implement new ERP operations, add dependencies, change persistence/schema, rewrite legacy identifiers, or alter production/fiscal/SERPRO/commercial gates. The reference `.dashboard` and the earlier completed standardization plan remain unchanged.

### Product decisions and compatibility dependency

No accepted product decision is superseded. Existing decisions and canonical access/isolation contracts remain authoritative.

The current settings endpoint persists `currency_id`, `purchase_currency_id`, `cidade_id`, `default_unit`, and `default_sales_tax` as legacy scalars. Its metadata does not provide their labels or a verified mapping to the native catalogs. Full removal of manual identifier entry depends on that contract. This frontend change must not guess the mapping, fabricate choices, disable supported editing, or claim D06 fully resolved without evidence. A separately scoped backend/reference-contract follow-up is required for any unverified field.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `erp-company-foundation`: refine the existing ERP presentation requirement with observable geometry, list-state, settings-feedback, reference-compatibility, and exact-decimal display acceptance. Existing tenant, authentication, transport, and mutation requirements remain unchanged.

## Impact

- Frontend: ERP layout/context presentation, shared page shell and catalog renderer, active workspace tables/footers, settings renderer/email form/copy, catalog display helpers, and focused tests.
- Shared office context menu: its selection behavior and markup contract remain unchanged; the ERP sidebar host supplies enough space. Any unexpected office-shell geometry regression must be detected before acceptance.
- Backend: inspection of reference/settings contracts only in this change. Any needed additive API or identifier mapping is a separate follow-up; no implied migration is authorized by this proposal.
- Verification: focused Node/Vue tests, frontend lint/typecheck/build, and representative browser checks. Browser mutations or invalid-submit checks use isolated test stores; existing development fixtures and credentials remain preserved.
- Planning/evidence: the source audit is `docs/reviews/2026-10-23-erp-dashboard-consistency/README.md`; implementation will add its own result/evidence without replacing the baseline. This proposal changes no application code.
