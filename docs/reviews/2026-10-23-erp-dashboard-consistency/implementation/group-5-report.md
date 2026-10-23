# Group 5 — shared settings metadata and rejected-save feedback

Status: implemented, focused verification passed; final full-suite/typecheck/build acceptance remains with the controller.

Worktree: `/home/obsidian/dev/opmoni/.worktrees/erp-ui-audit-shell`.
Branch: `codex/standardize-erp-dashboard-ui`; starting HEAD `66cbfa8`.

## Scope and architecture

Read the complete group brief, global constraints, change proposal/design/delta spec, root/frontend instructions, Nuxt UI skill/forms/conventions references, cached official examples and runtime handoff. Queried official Nuxt UI MCP Form and FormField documentation directly. Inspected installed Nuxt UI 4.11.3 Form/FormField implementations: `state`, named `UFormField`, explicit `error`, `description`, `hint`, native control error association, and emitted `submit` are supported.

The existing composable remains the owner of transport, authority, context sequence, state and duplicate suppression. Its public API and implementation are unchanged. Both actual consumers now bind UForm to their existing reactive form and use the existing `apiFailure` convention from shared/utils/access.ts for backend field errors and visible form/transport feedback. No alternate error-shape parser was introduced.

The renderer imports the existing exported ErpSettingsField with options/description/hint added to that shared type. First named messages are forwarded to FormField; optional metadata is forwarded. Email supports `email_settings.<key>` plus direct field paths while preserving exactly `{ email_settings: { ...form } }`. Failed saves preserve edits. New attempts, context-key changes and effective canRead/canWrite changes clear feedback, and existing current-authority checks still gate success/failure. Read-only roles cannot submit. The section's load/retry guard now follows the existing email guard.

Descriptions in Empresa, Taxas, Produto, Vendas, Compras, Sistema, Prefixos, E-mail and Pontos explain the business settings. Rótulos honestly says its editor is unavailable. Prefixos describes only the existing SKU editor and says other prefixes lack an editor. No supported editor, key, conversion, opaque reference value, permission, backend, dependency, environment, gate or theme changed. D06 remains the separate group-6 compatibility dependency.

The administration test harness required a minimal real erpPresentation module binding after a prior group imported that module in configuracoes.vue. Its authority assertions are unchanged. Controller explicitly accepted this focused integration support.

## RED / GREEN

Initial RED command (before production edits):

```bash
pnpm --dir frontend exec node --experimental-strip-types --test tests/erpCompanySettingsApi.test.ts tests/erpCompanySettingsFields.test.ts
```

Result: **43 tests, 13 pass, 30 fail**. Failures show missing bound form state/metadata, missing current named/nested 422 feedback and missing visible form/network messages; the 13 pre-existing cases remained green. Raw output (console trailing whitespace normalized): `group-5-red-tests.txt`.

First GREEN with implementation: **43/43 pass**, retained in `group-5-green-settings-tests.txt`. Then added direct authority-change clearing and opaque business-reference editing preservation coverage.

Final focused command:

```bash
pnpm --dir frontend exec node --experimental-strip-types --test tests/erpCompanySettingsApi.test.ts tests/erpCompanySettingsFields.test.ts tests/erpAdministrationWorkflows.test.ts tests/erpCatalogReferenceSelect.test.ts tests/erpCatalogForms.test.ts
```

Result: **143 tests / 11 suites, 143 pass, 0 fail, exit 0**. Raw output: `group-5-green-focused-tests.txt`.

The first combined run exposed two existing navigation harness import failures after prior shared-token adoption: `shows owner controls only for a direct owner company context` and `does not infer owner-only controls from a wildcard grant or a delegated office admin`, both `Unexpected import: ~/components/erp/erpPresentation`. The real module binding described above resolves them. Initial changed-path ESLint found quote-props and extra blank-line formatting in the new harness; corrected before final lint.

Final lint:

```bash
pnpm --dir frontend exec eslint app/utils/erpCompanySettingsFields.ts app/components/erp/ErpSettingsSectionForm.vue app/pages/erp/configuracoes/{email,compras,empresa,pontos,prefixos,produto,rotulos,sistema,taxas,vendas}.vue tests/erpCompanySettingsApi.test.ts tests/erpCompanySettingsFields.test.ts tests/erpAdministrationWorkflows.test.ts
```

Result: **exit 0, no output**, retained in `group-5-eslint.txt`. `git diff --check` and the staged diff check also exit 0 after normalizing console-log trailing whitespace.

Coverage executes the actual settings composable and compiled consumers through the existing Vue renderer with only API/component environment boundaries adapted. The FormField adapter retains names, metadata and error text instead of swallowing them. Cases cover first named/nested error, retained edits, unchanged PATCH shape, duplicate suppression, visible nonfield409/422 and network failure, clearing on retry/current-authority changes, late success/failure after Company, Account, session, write-permission and effective-role changes, read-only operator/viewer, and existing definitive refusal behavior. Browser verification below checks native Nuxt UI behavior beyond the adapter.

## Browser evidence and isolation

Applied agent-browser skill and reused the owned `erp-ui-group1` session sequentially on the existing worktree Nuxt frontend `http://localhost:3107`. Normal authenticated Company selection and existing settings **GETs only** use existing development stores. No credentials, context grants, session state or error refs were fabricated.

The rejected saves use an isolated synthetic transport: existing Node built-in WebSocket/CDP attached to the same owned tab, and `Fetch.enable` paused only exact `http://localhost:3107/api/v1/erp/company/settings` requests at **Request** stage. Safe GET/HEAD/OPTIONS were continued. Exact PATCH requests were fulfilled locally with HTTP422 and named synthetic JSON errors, before network dispatch; other writes in that exact scope were aborted. No settings PATCH was dispatched to the development backend. The actual native Save button, UForm submit handler, save() composable, useAccessApi and rejection catch all executed. No window.fetch replacement or error-ref assignment was used.

Retained reproducible sanitized script: `group-5-browser-interceptor.mjs`; sanitized transport log: `group-5-browser-transport.txt`. The first non-TTY start closed stdin and removed interception before any save; the next TTY start remained active for both synthetic saves. Log records exactly two PATCH422 fulfillments (`tax_label_1`, `email_settings.mail_host`) with upstreamDispatched=false. After both, `Fetch.disable`, Target detach and WebSocket close completed. All browser endpoint/auth details remained runtime-only and are not in evidence files.

| Actual loaded page | Viewport | Observations |
| --- | --- | --- |
| Taxas, synthetic rejected save | 1366x900 | Business guidance; visible form error and first field error; edit `Teste de validação preservado` remains; native input aria-invalid=true and aria-describedby points to native error; no document horizontal overflow. |
| Taxas, same rejected state | 390x844 | Form-level and named error readable; edited input retained; Save reachable; no horizontal overflow. |
| E-mail, nested synthetic rejected save | 1366x900 | SMTP business guidance; nested email_settings.mail_host error reaches Host SMTP; `smtp.synthetic.example.test` retained; aria-invalid/error association present; no horizontal overflow. |
| E-mail, same rejected state | 390x844 | Form and field feedback readable; edited host retained; remaining fields/Save reachable; no horizontal overflow. |

All four screenshots were opened and visually inspected. Screenshot/DOM evidence is prefixed `group-5-tax-*` and `group-5-email-*`; DOM JSON records actual values, native error associations and overflow. Root default size checked at16px on desktop. These are synthetic HTTP422 acceptance checks, not claims that a real backend rejected or accepted these edits. No successful business save was attempted.

Source inspection `rg -n 'blade\.php|partials/|change [0-9]|openspec' frontend/app/pages/erp/configuracoes` found no internal migration descriptions; result retained in `group-5-copy-scan.txt`. Browser scope is Taxas/E-mail only; remaining copy pages were source-inspected, not independently browsed. Unsupported editors remain honest source placeholders.

## Runtime handoff and limits

Browser returned to **normal loaded Taxas at1366x900** by a safe GET reload after interception was removed; synthetic form feedback is absent. `group-5-runtime-handoff.json` records loaded=true and formHasSyntheticFeedback=false. The browser is released for sequential parent reuse. Existing worktree frontend runtime remains running on port3107, process/environment unchanged. Do not submit a settings write to that normal backend; future synthetic saves require re-enabled verified interception or separate stores.

No backend acceptance, real save, concurrent independent-store session test, production fiscal/SERPRO canary, full frontend suite, typecheck, build, release or full D06 completion is claimed. Full-suite/typecheck/build belong to parent final acceptance by explicit task instruction. Root dirty AGENTS.md and untracked frontend/AGENTS.md preserved and excluded. No ledger/checklist change, merge, push or archive performed.

## Review correction — effective authority loss with the same key

Parent review identified that composable blockedKey can revoke effective access after a definitive server refusal without changing authorityKey. Verified through the actual composable: a current422 followed by GET401, GET403 or context409 left old form/field feedback visible in both consumers after state was cleared.

Added six focused regressions first; **6 tests / 0 pass / 6 fail**, retained in `group-5-review-red-tests.txt`. Minimal correction: each existing clear/sync watcher now observes `[authorityKey, canRead, canWrite]`. Composable API/transport/authority behavior remain unchanged. Focused GREEN: **6/6 pass**, retained in `group-5-review-green-tests.txt`.

```bash
pnpm --dir frontend exec node --experimental-strip-types --test --test-name-pattern='same-context' tests/erpCompanySettingsApi.test.ts
```

Reran the complete five-suite focused command and changed-path ESLint after that correction: **143/143 pass, 11 suites**, lint exit0/no output, diff check clean. Browser evidence predates only this watcher-source correction; parent explicitly requested no additional browser pass, and its refusal behavior is exercised through the actual composable/consumers in those regressions. Existing synthetic422 rendering remains covered by the repeated focused suite.
