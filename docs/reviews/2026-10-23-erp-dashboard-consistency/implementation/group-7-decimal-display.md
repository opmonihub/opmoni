# Group 7 — Exact read-only decimal localization (D09)

Implemented against `ee236b935d264c66f9b794a87730b8ddd9fccf88` on `codex/standardize-erp-dashboard-ui` in `.worktrees/erp-ui-audit-shell`.

## Change

Added `formatErpDecimalDisplay(value, options?)` as a display-only string formatter. Canonical fixed-point values use grouped integer dots and a decimal comma. Only insignificant trailing fractional zeros beyond the requested minimum disappear; all meaningful precision and the negative sign remain. The default minimum is zero; Products prices request two digits. Null, undefined and empty strings produce an em dash. Unexpected/noncanonical strings are returned verbatim. No currency symbol is inferred.

Only existing read-only column functions changed in Products, fiscal references, units, pricing and customer groups. The existing `formatErpDecimal`, `erpCatalogs.ts`, all form components and serializers are unchanged. No UI component API, native markup, layout or theme changed; existing `UTable` column conventions remain in use. Nuxt UI skill and table/conventions references were consulted. No new custom primitive or dependency was introduced.

The presentation harness now invokes actual page column renderers rather than serializing each record as JSON. VNode results still render as nodes. Existing presentation assertions remain intact. Five new real-page cases verify localized cells plus unchanged source records.

## RED / GREEN evidence

1. Before the helper existed:

   `node --experimental-strip-types --test frontend/tests/erpDecimalDisplay.test.ts`

   RED: exit 1, `ERR_MODULE_NOT_FOUND` for `frontend/shared/utils/erpDecimalDisplay.ts`. The required 27 formatting tests were already present before implementation.

2. After the minimal string-only helper:

   Same command: exit 0, 27 tests passed. No decimal string conversion through `Number`, `parseFloat` or `Intl.NumberFormat` occurs.

3. Before replacing the existing read-only cells:

   `node --experimental-strip-types --test frontend/tests/erpCatalogListPresentation.test.ts`

   RED: all five new localization cases failed on the existing canonical output. Examples: fiscal expected `-10,125`, received `-10.1250`; units expected `#2 · 12.345.678.901.234.567.890,1234`, received `#2 · 12345678901234567890.1234`; pricing expected `-2,5`, received `-2.5000`.

4. After integration, including two additional whitespace/newline noncanonical boundary cases:

   From `frontend/`:

   `node --experimental-strip-types --test --test-concurrency=1 tests/erpDecimalDisplay.test.ts tests/erpCoreCatalogs.test.ts tests/erpCatalogForms.test.ts tests/erpCatalogListPresentation.test.ts`

   GREEN: exit 0, 106 tests across 5 suites passed; 0 failures, cancellations or skips. This includes 29 decimal formatter cases and all five page localization cases, existing pricing states/pagination, and the actual-form payload tests below. The final small presentation harness cleanup was rechecked separately with all 8 presentation tests passing.

5. Changed-path ESLint:

   `pnpm --dir frontend exec eslint shared/utils/erpDecimalDisplay.ts tests/erpDecimalDisplay.test.ts tests/erpCatalogListPresentation.test.ts app/pages/erp/cadastros/produtos.vue app/pages/erp/cadastros/fiscal.vue app/pages/erp/cadastros/unidades.vue app/pages/erp/cadastros/precos.vue app/pages/erp/cadastros/grupos-clientes.vue`

   GREEN: exit 0, no diagnostics. `git diff --check` also passed.

## Actual emitted form payloads

`erpCatalogForms.test.ts` compiles/mounts the real form SFCs, edits a named field, triggers actual form submission, captures `save` emissions, and compares entire emitted payloads with expected records. This is existing behavioral coverage, executed unchanged for this group; it does not merely assert that a formatting utility stayed unchanged.

Verified canonical strings in emitted payloads include:

| Real form | Preserved fields / values |
| --- | --- |
| Product and variant | `sale_price: '19.2500'`; `icms_rate: '0.0001'`; `cofins_rate: '1.2300'`; `ipi_rate: '0.5000'` |
| Unit | `conversion_factor: '1000.00000000'` |
| Customer group | `percentage_adjustment: '-2.5000'` |
| Price group | `percentage_adjustment: '-2.5000'` |
| Discount rule | `value: '15.7500'` |
| Tax rate | `rate: '18.0000'` |

The real page tests separately verify Products `12.5000 → 12,50`, Products `0 → 0,00`, fiscal `-10.1250 → -10,125`, units beyond-safe-integer precision, price-group `-2.5000 → -2,5`, discount `0.0001 → 0,0001`, and customer-group `12.5000 → 12,5`. Input records remain identical after rendering.

## Limits and runtime handoff

No browser action, authenticated business save, backend mutation, fiscal/SERPRO provider call, database reset, environment edit or service restart was performed. Page/form checks use the existing synthetic Vue host/API harness, not a browser or production backend. Browser display acceptance is not claimed. No known functional regression in the focused checks. Full frontend suite, typecheck and build remain controller-owned final acceptance, as directed by the task brief.

The existing localhost:3107 frontend and named `erp-ui-group1` browser session remain untouched; their prior desktop Taxas state remains available for sequential controller reuse. Dirty root/frontend instruction files remain preserved and excluded. OpenSpec checkboxes and release gates remain unchanged.
