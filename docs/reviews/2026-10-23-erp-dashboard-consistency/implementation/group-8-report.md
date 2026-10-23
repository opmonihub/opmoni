# Group 8 — Complete inventory coverage and residual error associations

Status: focused group complete for controller review. Full-project/browser/reference acceptance remains group 9-owned. Base `97e4ddf`, worktree `.worktrees/erp-ui-audit-shell`, branch `codex/standardize-erp-dashboard-ui`. Existing dirty root/frontend instructions preserved and excluded.

## Coverage and architecture

Read group8 brief including its closed-pagination UPDATE, global constraints, proposal/design/delta, frontend instructions, group1–7 reports, current inventory and actual source of every inventory file. Applied Nuxt UI/forms/overlays and Impeccable incumbent/craft guidance. Inspected installed Nuxt UI4.11.3, native slideover/modal structures and queried official Nuxt UI MCP Slideover, Modal and FormField usage/examples (https://ui.nuxt.com/docs/components/slideover, https://ui.nuxt.com/docs/components/modal, https://ui.nuxt.com/docs/components/form-field). Existing components and local dashboard reference remain authoritative; no UI primitive introduced.

`coverage.csv` contains exactly the original **97 unique rows: 40 pages +57 components**, with each original path/route/family, disposition, source line, shared owner, evidence and reason. Validation is in `group-8-coverage-validation.json`; per-file source hashes and actual form/table/overlay markers are in `group-8-source-review.json`. “Changed” means changed directly in accepted groups1–8 versus immutable source baseline5ce7d51, not29 edits by group8.

| Disposition | Files |
| --- | ---: |
| changed |29 |
| inherited shared correction |23 |
| already conforming |37 |
| justified exception |8 |

All catalog families (13 editors including products/variants, references and Contact notes/files), settings/email, company administration/delegation/accounting mapping, finance/cash forms, purchase/XML/returns/recovery, stock adjustment/transfer/reversal/details, commercial draft/sales/returns/details, fiscal configuration/document/certificate/event/invalidation/details, reports/exports, labels/import snapshots, registration and contract are represented. Shared shell/footer consumers inherit their correction; conforming forms require no bulk styling edits. Dormant ErpModuleHub is an explicit unreferenced exception, not active acceptance. Specialized semantic accounting table, snapshot/import/label/print/recovery structures remain specialized. Sales preserves selectable commercial card rows, not a forced operational UTable. Existing compact secondary controls and numeric start alignment remain intact.

## Demonstrated residual and correction

Catalog native controls already provide correct field/error links and editor lifecycle. Two existing HTML administration/accounting forms instead rendered visible errors without a control description association. This was reproduced through compiled SFCs before editing: **4 regressions,0 pass/4 fail** (`group-8-association-red.txt`). No spacing defect was demonstrated in inspected overlays, so no geometry/class/control-size rewrite was made.

Only runtime edits are in `ErpCompanyAdministration.vue` and `ErpAccountingMappingApproval.vue`: Vue `useId()` supplies instance-scoped error IDs; existing controls/fieldsets receive conditional `aria-describedby` to their actual rendered feedback and `aria-invalid` where absent. Establishment name/CNPJ, member identity email/user error, action-permission and establishment groups, owner account ID, office establishment/client/search fields are covered. Existing wrapping labels remain. No form, handler, permission, API shape, request, serializer, draft, recovery or role rule changed.

The existing administration harness now verifies description IDs resolve to one mounted error, actual failed-save/lookup edits remain, local invalid account-ID review sends no delegation POST, and healthy retry removes stale ARIA feedback. Office error rendering is explicitly isolated renderer feedback via the existing exposed fieldErrors, not a claim of a real approval rejection. Other new cases execute existing synthetic422 API or local validation paths. Existing tests retain read-only, duplicate-write, confirmation, stale-data, authority and protected-context clearing coverage.

## Verification

From worktree root:

```bash
pnpm --dir frontend exec node --experimental-strip-types --test --test-concurrency=1 tests/erpCatalogForms.test.ts tests/erpCatalogEditor.test.ts tests/erpCatalogReferenceSelect.test.ts tests/erpCatalogResourceAuthority.test.ts tests/erpAdministrationWorkflows.test.ts
pnpm --dir frontend exec eslint app/components/erp/ErpCompanyAdministration.vue app/components/erp/ErpAccountingMappingApproval.vue tests/erpAdministrationWorkflows.test.ts
git diff --check
```

Final focused run **124 tests,7 suites,124 pass,0 failures/skips,exit0** (`group-8-focused-green.txt`). Catalog-only run98/98 and administration26/26 are also retained as narrow evidence. Changed-path lint exit0/no diagnostics (`group-8-eslint.txt`); initial formatting/attribute-order errors were fixed before final verification. Final diff check clean after normalizing CSV CRLF and console trailing whitespace in retained evidence; audit baseline unchanged. No unrelated purchase/stock/sales/fiscal runtime file changed; their prior group4 focused evidence is reused, without claiming a new workflow rerun. Full suite/typecheck/build/detector and broader browser matrix remain parent group9-owned by the task boundary.

## Actual browser overlays

Sequential existing named session `erp-ui-group1`, worktree frontend localhost3107, normal fixture/company and installed native components,16px root; desktop1366x900/mobile390x844. Screenshots were visually inspected. Geometry/assertions are retained under `group-8-product-*`, `group-8-purchase-*` and `group-8-browser-assertions.txt`; reusable sanitized measurement source is `group-8-browser-metrics.js`.

| Actual surface | Observed result |
| --- | --- |
| Product create |768px/48rem desktop;390px full mobile; initial named input visible; body scroll reaches enabled Criar produto at desktop y779–811/mobile731–763; Fechar remains reachable. No submit occurred. |
| Product edit/detail | Record-specific native headings and descriptions; first named field visible. Edit enabled; detail visible-size controls disabled and no create/save action. Native close restores the actual row-action opener. |
| Product close | Create mobile closes to Adicionar produto; edit desktop/detail mobile close to Ações de Produto de auditoria01; settled dialog count0. |
| Purchase create | Actual Nova compra dialog1024px/64rem desktop; mobile settled frame x16,width358 within390. Initial reference controls visible; final Salvar rascunho visible desktop715–747/mobile780–812 through existing local body scroll. Close restores Nova compra. No purchase submitted. |
| Product synthetic rejected edit | Actual Salvar produto executes existing UForm/editor/API path. Exact numeric product PATCH intercepted at CDP Fetch Request stage and fulfilled422 before upstream dispatch. Edited name remains, native aria-invalid=true and describedby resolves named error on desktop/mobile; no success claimed. |

The purchase initial-mobile raw metric was captured during native open transition; its fractional width is not a settled width claim. Settled bottom capture proves frame358 and final action visibility. Existing group5 Taxas/E-mail synthetic422/native error evidence is reused rather than repeated. Existing catalog suites cover concurrent busy guards, read-only mode, lossless payloads, reference failures, synchronous protected-context clearing and late-response rejection; no browser authority fabrication was used.

The sanitized `group-8-browser-interceptor.mjs` derives from verified group5 pre-dispatch interception. Products prefix is intercepted; only PATCH to a numeric detail path is locally fulfilled422, other scoped writes aborted, GET/HEAD/OPTIONS continued. `group-8-browser-transport.txt` records exactly one synthetic PATCH422 with upstreamDispatched=false and successful removal. Initial Brands interceptor was enabled and removed with no write because no create action was present in that loaded view; no grant or fabricated permission was attempted. Credentials/CDP/auth data are runtime-only, absent from artifacts. No development business write, backend reset, provisioning, replay, fiscal/SERPRO request or service restart occurred.

## Runtime handoff and boundaries

Interceptor disabled and detached; owned frontend3107 remains running with unchanged process/environment. Session `erp-ui-group1` returned to **normal loaded Taxas,1366x900**, no overlay/synthetic feedback (`group-8-runtime-handoff.json`). Do not submit normal backend writes without renewed verified interception or isolated stores.

No individual browser exercise of every editor/detail, accounting/delegation approval, archive confirmation, provider operation, successful save, independent concurrency/SSR identity or printing/export is claimed. Those families have source dispositions and existing focused suite evidence, not universal browser acceptance. D06 remains compatibility work pending for the five legacy settings references. Existing fiscal/SERPRO/commercial gates, default disabled ERP registration, dependencies/backend/env/theme/global tokens/reference/audit and OpenSpec checkboxes unchanged. No subagents, merge, push or archive. Parent owns final acceptance and ledger.
