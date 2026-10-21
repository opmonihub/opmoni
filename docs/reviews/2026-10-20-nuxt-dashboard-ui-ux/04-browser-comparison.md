# Dashboard template comparison: current browser evidence

Date: 2026-10-20. Coordinator-owned, bounded audit; application source was not changed.

## Implementation integrity verdict

**Fail for release-wide consistency; preserve the existing visual foundation.** The source maps establish a coherent Nuxt UI shell, product-specific Account/Company boundaries and real operational components. However, inherited simulated actions, inert security settings and incomplete ERP operating routes prevent a consistent operational product. The current Finance screen also departs from the reference's list-first/create-modal composition in a measurable way. This verdict requests targeted corrections, not a replacement design system.

The bundled Impeccable detector was invoked on `ErpFinanceWorkspace.vue` during this pass and emitted no finding text. Its exit code was not retained in the captured tool output; this is not a detector pass assertion. The verified findings below come from source inspection, opened screenshots, DOM measurements and axe results.

## Method and reproducibility

- Application: `erp-finalization`, HEAD `810506f518651f268ec67a8b451c2f832388b0bf`, compiled frontend tree `2e19de58d9abbeb33a7d081b1f62f0948b6ac8ed`, immutable output `frontend/.output-erp-task11-810506f`, manifest SHA-256 `2cf6d0127082bdb5a3f147661b10feac2d01f1b66c1d151153487cfab0bd8809`. Existing isolated browser backend and synthetic Company A data were reused.
- Reference: the actual repository-root `.dashboard`, not an upstream website. Presentation sources were copied without edits into ignored audit scratch; only an absolute Nuxt build directory was configured for isolation. Installed reference dependencies were reused without installation. A temporary development preview ran on `localhost:3335`; the application used its existing compiled runtime on `localhost:3334`.
- Browser: agent-browser, Chrome/CDP, separate owned reference/application sessions. Desktop 1366×768 and emulated mobile viewport 390×844; light and dark media preferences. All eight primary screenshots were opened and visually inspected by the coordinator. The reference create modal was also opened, inspected and cancelled without submitting.
- Measurements: computed semantic colors/font declarations, loaded font faces, element rectangles, viewport/document widths, form structure and table position. DOM rectangles include offscreen elements; an element in the metric file is not necessarily visible within the viewport. Coordinates are from each captured initial scroll position.
- Automated accessibility: axe 4.12.1, tags `wcag2a,wcag2aa`, desktop initial Finance/Customers state in both themes. No full-route, full-state or WCAG certification is implied. No new financial writes, provider operations, migrations or fixture changes were performed for this comparison.
- The reference is a development preview with development controls and a cookie toast; the application is a compiled bundle. Timing and resource counts below are descriptive, not a performance comparison.

## Health score: provisional, limited evidence

Scores use a 0–4 audit rubric. They are judgments over the inspected source and the stated browser sample, not a measured acceptance score for all 90 page files. The performance score has low confidence because profiling was outside this pass.

| Dimension | Score | Evidence and limit |
|---|---:|---|
| Accessibility | 3/4 | Sampled Finance has zero automated violations in either theme, but two incomplete rules remain. Whole-product keyboard, screen-reader, focus, zoom and other route states are unverified. |
| Performance | 2/4, provisional | Server pagination and bounded operational loads exist. A single local navigation sample and DOM/resource counts are insufficient to assess long lists, interaction responsiveness, production payload budgets or animation cost. No performance regression is established. |
| Responsive design | 2/4 | Sampled document width fits 390px and native shell adapts; persistent Finance entry dominates the initial mobile view. Many controls are 32px high. Touch gestures, zoom, physical devices and other route families remain unverified. |
| Theming | 3/4 | Public Sans loads; zinc/green identity and dark output are preserved. ERP light contrast adaptation is intentional and measured. Arbitrary user palette combinations and calendar-specific tokens remain unverified. |
| Implementation integrity | 1/4 | Source-confirmed false success/inert operational actions, stale Client record risk, unfinished ERP workflows and inconsistent error recovery are repeated product issues. |
| **Total** | **11/20, provisional** | **Significant work needed. Confidence differs by dimension; do not use this as release acceptance.** |

## Measured comparison

| Measure | Actual template Customers | Current ERP Finance / Obligations | Interpretation |
|---|---|---|---|
| Desktop navigation | 240px sidebar; navbar begins at x240 | Same 240px sidebar boundary | Core dashboard geometry is retained. |
| Typography | Public Sans computed, three Public Sans faces loaded | Public Sans computed, three faces loaded | No missing-font finding in these samples. Unloaded unused faces are normal, not failed font requests. |
| Desktop first data row | y194, height73 | y802, height56 | Finance's first row is below the 768px viewport. Different row heights reflect different record content; they are not a parity defect on their own. |
| Mobile first data row | y216 | y1386 | Finance requires substantial scrolling before records; the template exposes records immediately. |
| Initial entry form | None; explicit New customer opens a modal | Persistent Nova obrigação section above filters/totals/list | This is a composition difference, confirmed by screenshots and `ErpFinanceWorkspace.vue:275-304`. |
| Mobile form | Opened only on demand | Form itself y414, height528, width324 | Entry competes with consultation on every visit, including when no entry is intended. |
| Document width | 1366 / 390, matching viewports | 1366 / 390, matching viewports | No document-level horizontal overflow in these eight captures. Wide tables still scroll internally. |
| Nested forms | 0 | 0 | No nested-form defect in the sampled DOM. |
| Primary in light | `#00c16a`, white text | `#0a5331`, white text | ERP deliberately uses a darker semantic token. |
| Primary in dark | `#00dc82`, dark text | `#00dc82`, same computed dark text | Current dark primary treatment matches the reference. |

The source's native Company selector, permission-aware navigation and stale-state clearing are required product behavior. Replacing them with the reference's demo TeamsMenu would lose those boundaries. The template's persistent cookie toast, fake metrics, sample Inbox and placeholder actions are not product standards to replicate.

## Accessibility results and false-positive handling

| Captured desktop state | Violation rules | Affected nodes by rule | Incomplete rules |
|---|---:|---|---:|
| Template Customers, light | 3 | `button-name`:22; `image-alt`:10; `color-contrast`:10 | 3 |
| Template Customers, dark | 2 | `button-name`:22; `image-alt`:10 | 3 |
| ERP Finance, light | 0 | None reported | 2 |
| ERP Finance, dark | 0 | None reported | 2 |

Counts are per rule/state; repeated nodes across themes must not be added as unique defects. The source and snapshot support the template findings: icon row actions lack names, checkbox rendering contains `arialabel` instead of `aria-label`, the filter has no programmatic label, and customer avatar images lack alt attributes. The light New customer button was measured by axe at **2.37:1** white-on-green. Other template contrast findings include semantic badges and the delete control. These are reference defects to avoid importing.

Both products have incomplete contrast evaluation for the active sidebar label because of a pseudo-element background; this is a manual-review item, not a confirmed violation. Both also have incomplete table-header/data association evaluation, despite populated rows in the screenshot/DOM. The reference additionally has notification focus-guard incompletes. Do not report these incomplete rules as established failures or silently count them as passed. The supplied tags cover WCAG 2.0 A/AA rules only, not all WCAG 2.2 criteria.

The current Finance automated sample does not reproduce the historical Task 24 contrast failures, supporting retention of the scoped ERP light-token correction. It does not prove every ERP badge, hover state, modal or arbitrary palette passes. Prior native finance mutation/receipt/context acceptance is separately recorded in the Task 11 browser report; date/aging/filter acceptance remains incomplete.

## Prioritized browser findings

### V01 — P2: Finance entry displaces the primary record list

- Location: `frontend/app/components/erp/ErpFinanceWorkspace.vue:275-304`; screenshots and metric JSON linked below.
- Category/standard: implementation integrity, information hierarchy, requested local-template composition.
- Impact: on desktop the first record begins at y802, and on mobile at y1386. A user visiting to inspect or reconcile existing obligations must traverse an empty creation form and then filters. The “Ir para nova obrigação” button scrolls to a form already present rather than opening the focused create interaction demonstrated by the template.
- Recommendation: use the reference's navbar primary action plus an on-demand native `UModal` or appropriately sized `USlideover`; keep the list, filters and domain totals as the resting workspace. A larger domain form may use a dedicated editor surface, but should not consume the default list view. Preserve retained drafts across tab changes, field-level errors, exact decimal strings, authority generation clearing, operation recovery and every accepted finance permission/receipt behavior.
- Acceptance: initial records/empty/error state visible before creation; create opens only after explicit action; cancel returns focus; failed writes retain safe inputs; narrow screens can reach actions and validation; existing Task 11 regressions stay green.
- Suggested command: `$impeccable layout`, followed by the existing frontend workflow tests and direct browser acceptance.

### V02 — P2: Mobile density needs an explicit touch policy

- Location: measured native buttons in both sampled products; Finance metrics show 32px-high tabs, primary actions and selectors, with 24px-high detail action further down the table.
- Category/standard: responsive ergonomics. The 44×44px touch design target is not met by these rectangles. This is not an assertion that WCAG 2.2 target-size fails: its minimum, spacing and exceptions differ, and this pass did not audit them.
- Impact: small controls and internally scrolling wide tables demand precision on a narrow viewport; the full document fitting the viewport does not establish touch usability.
- Recommendation: define supported compact desktop versus mobile control sizes, preserve accessible labels, and verify row actions at the right edge of horizontally scrollable tables. Do not globally increase every control without checking density and pointer context.
- Acceptance: synthesized touch or device testing, reachable right-side actions, page/table scroll coexistence, no clipped focus, and text zoom verification. Current evidence is emulated viewport/DOM, not physical-device or gesture acceptance.
- Suggested command: `$impeccable adapt`.

### V03 — P2: Reference defects must stay excluded from parity work

- Location: actual `.dashboard/app/pages/customers.vue` and its captured accessibility reports.
- Category/standard: accessibility, WCAG name/role/value, non-text content and text contrast.
- Impact: literal reproduction would restore unnamed controls, missing image alternatives or low-contrast light accents even where Opmoni already improves them.
- Recommendation: preserve component/layout grammar while retaining programmatic names, localized labels, server-backed states and the ERP contrast correction. Treat fidelity as composition/tokens with documented accessibility adaptations.
- Acceptance: no regression in the existing ERP sample; inspect remaining route and modal states rather than treating a green token as proof of contrast.
- Suggested command: `$impeccable harden`.

## Performance observations, without comparative claims

One local navigation sample recorded the template at 3,156ms DOMContentLoaded / 3,161ms load, 250 resource entries and 1,031 DOM elements. The compiled application navigation recorded 1,513ms / 1,712ms, 135 resource entries and 557 elements. These are single observations, not repeated trials or cold-cache benchmarks; the app navigation began at authentication, and the two runtimes use different build modes. They do not establish that Finance is faster, meets a production budget, or has no expensive rendering. Production profiling with representative data, network conditions and interactions remains outstanding.

## Evidence files

| State | Template | ERP Finance |
|---|---|---|
| Light desktop | [Screenshot](evidence/template-light-1366.png) · [DOM metrics](evidence/template-light-1366-metrics.json) | [Screenshot](evidence/erp-finance-light-1366.png) · [DOM metrics](evidence/erp-finance-light-1366-metrics.json) |
| Dark desktop | [Screenshot](evidence/template-dark-1366.png) · [DOM metrics](evidence/template-dark-1366-metrics.json) | [Screenshot](evidence/erp-finance-dark-1366.png) · [DOM metrics](evidence/erp-finance-dark-1366-metrics.json) |
| Light mobile | [Screenshot](evidence/template-light-390.png) · [DOM metrics](evidence/template-light-390-metrics.json) | [Screenshot](evidence/erp-finance-light-390.png) · [DOM metrics](evidence/erp-finance-light-390-metrics.json) |
| Dark mobile | [Screenshot](evidence/template-dark-390.png) · [DOM metrics](evidence/template-dark-390-metrics.json) | [Screenshot](evidence/erp-finance-dark-390.png) · [DOM metrics](evidence/erp-finance-dark-390-metrics.json) |

Additional evidence: [reference create modal](evidence/template-create-modal-1366.png), [template light axe](evidence/template-light-a11y.json), [template dark axe](evidence/template-dark-a11y.json), [Finance light axe](evidence/erp-finance-light-a11y.json), [Finance dark axe](evidence/erp-finance-dark-a11y.json), [reference timing sample](evidence/template-runtime-sample.json), [application timing sample](evidence/erp-finance-runtime-sample.json).

## Remaining acceptance and cleanup

This pass maps the entire source route inventory but visually samples Customers in the reference and Obligations in Finance. Current catalog, office, platform, settings, failed/stale/empty states, all dialogs, keyboard/screen-reader paths, tablet, text zoom and touch gestures need their own representative acceptance. No SERPRO, WhatsApp, fiscal transmission, email delivery or printer acceptance is established. Existing Task 11 browser evidence remains scoped to that workflow.

Both owned audit browser sessions were closed. The owned template preview was stopped after verifying its process-group identity and scratch working directory. The existing compiled ERP frontend, backend, stores and posted synthetic history were retained. No app/template implementation, dependency, environment, schema or production gate was changed.
