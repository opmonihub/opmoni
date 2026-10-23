# Group 6 — legacy reference compatibility register and preservation

Status: group implementation ready for controller acceptance; **D06 partially addressed / compatibility work pending** for all five fields. No full reference migration or removal of manual-ID editing is claimed.

Worktree: `/home/obsidian/dev/opmoni/.worktrees/erp-ui-audit-shell`; branch `codex/standardize-erp-dashboard-ui`; base `f2c89b7`.

## Delivered scope

`reference-contracts.md` records every field's stored type, unverified identifier namespace, historical provenance, absent current settings labels, validation, permissions, active/archive/unavailable handling and exact separately scoped backend/API/mapping dependencies. Relevant current sources were verified against source-only research at `5ce7d51`; tracked backend/reference source remains identical. Current source excerpts are retained in `group-6-source-evidence.txt`. Legacy City PK is distinct from `codigo`; legacy units were base-only. Equal numeric IDs and matching labels do not establish native mappings.

Extended only `frontend/tests/erpCompanySettingsApi.test.ts`: existing real composable/compiled consumer harness accepts actual section field metadata. Business fields are imported; product/sales/purchase arrays are read from their actual page literals. Existing Group 5 tests, including the numeric business-reference metadata coverage in the field suite and all authority/feedback tests, are reused unchanged.

Nine added characterization cases cover all five opaque preexisting values, including zero-prefixed strings, unknown numeric values and a beyond-safe-integer string. The synthetic API supports settings only; every request is checked, and no native list/detail fetch or auto-choice is permitted. Unrelated saves omit the five keys; untouched same-section saves preserve exact raw identifiers/types. Existing actual text/number/checkbox input events retain established conversions. Failed edited saves retain same-context drafts and the fake confirmed store. Company switches synchronously clear old private UI, send no destructive PATCH and rehydrate the new Company's distinct raw identifiers. Persisted-value preservation does not mean retaining private data across authority contexts.

No runtime, backend, schema, dependency, environment, gate, instruction, OpenSpec checkbox or audit-baseline changes were made. Dirty root AGENTS.md and untracked frontend/AGENTS.md remain excluded. No subagents, browser work, service access, main-store mutation, merge, push or archive.

## Verification

```bash
pnpm --dir frontend exec node --experimental-strip-types --test tests/erpCompanySettingsApi.test.ts tests/erpCompanySettingsFields.test.ts
pnpm --dir frontend exec eslint tests/erpCompanySettingsApi.test.ts
git diff --check
git diff f2c89b7 -- backend frontend/app frontend/shared frontend/package.json pnpm-lock.yaml
```

Focused suites: **61 tests, 7 suites, 61 pass, 0 fail, exit 0** (`group-6-settings-tests.txt`). Changed-test ESLint: **exit 0, no output** (`group-6-eslint.txt`). Diff check: exit 0; no backend/application/schema diff (`group-6-diff-check.txt`).

These are preservation characterization tests, not a production RED/GREEN fix. The first local run exposed incomplete metadata dictionaries in the new fake response; supplying the API-shaped dictionary fixed that harness setup. Initial changed-test lint reported one extra blank line, corrected before final validation. No runtime change was needed.

## Material limits and handoff

Backend merge behavior is source evidence; fake-store tests are not real backend persistence acceptance. No live labels, mapping, option availability, archive classification, permissions, browser flow, fiscal/SERPRO canary or data migration was verified. Existing number editors still convert edited input through Number; raw untouched strings are preserved, but arbitrary edited malformed/large identifiers are not promised lossless semantics. Null/empty UI behavior is retained and remains a later contract dependency. Full suites, typecheck and build belong to the final task group and were not run here. D06 remains pending independently for default_unit, default_sales_tax, currency_id, purchase_currency_id and cidade_id.
