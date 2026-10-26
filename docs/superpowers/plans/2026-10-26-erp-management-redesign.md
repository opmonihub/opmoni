# ERP Management Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Deliver the user-selected operational center with prominent client registration and complete mobile operation.

**Architecture:** Keep the persistent commercial shell and all existing reads, commands and route identities. Compose ERP-scoped presentation components around existing Nuxt UI tables and forms; preserve shared app-wide panel styles.

**Tech Stack:** Nuxt 4, Vue 3, Nuxt UI 4.11.3, existing TanStack table dependency.

**Spec:** .impeccable/surfaces/frontend-app-pages-gestao-erp-vue.md; canonical commercial behavior in openspec/specs.

## Global Constraints

- Brazilian Portuguese copy; no new dependencies or API/schema changes.
- Preserve exact monetary strings, authorization, operation recovery and print routes.
- Work in .worktrees/erp-management-redesign; preserve unrelated reference/sistema.

## Review Focus

- Nested routes keep the shell mounted and the correct destination active.
- Mobile navigation, filters, table actions and pagination fit narrow widths.
- Large monetary values retain exact formatting.
- Empty, loading, stale, denied and unknown-operation states survive composition changes.
- Registration and editing remain usable without altering commercial policy.

### Task 1: Shell and shared presentation

- [x] Add focused behavior checks for route membership and column controls; preserve existing navigation checks.
- [x] Add ERP-scoped heading, panel, table and pagination components; preserve persistent shell and print exclusion.
- [x] Run focused navigation/component checks.

### Task 2: Operating surfaces

- [x] Recompose overview around registration and actual commercial summary.
- [x] Apply shared list/table presentation to clients, subscriptions, invoices, receipts and plans.
- [x] Reuse DataTableFilterPanel for invoice and receipt criteria, including open-ended dates.
- [x] Refine registration, details and configuration layouts for mobile; preserve existing dialogs.
- [x] Run commercial UI and navigation regression tests, lint and typecheck.

### Task 3: Validation and finish

- [x] Inspect desktop and mobile in an isolated frontend preview; preserve development data.
- [x] Run build and relevant regression suite; record validation boundaries.
- [x] Run the design detector once (no findings) and independent code review; correct both code findings.
- [x] Obtain the visual finish review from validated desktop/mobile captures and act on findings.
- [x] Document the inherited design and final review evidence; leave a reviewable diff.

## Execution checkpoint

Implementation, independent code review, one batched visual correction, final desktop/mobile confirmation, visual finish review (`ship`) and documentation are complete. Final lint, typecheck, production build and all 2,250 frontend tests pass. Focused regressions pass 87/87. No backend or development-database changes occurred.

Browser acceptance uses real compiled frontend and an isolated synthetic API. Generic cold-SSR hydration warnings and icon-loading timeouts remain a documented runtime limit. The review report records the exact exercised flows, capture evidence and limits: docs/reviews/2026-10-26-erp-management-redesign.md.
