# Integrated frontend validation

## Final acceptance — 2026-10-24

All four complete package scripts passed serially at `7d9b6bf1d2866cc8dc4969a721b7a0c52d3f3e95`, including the shared warning contrast correction and the final legacy-harness/authority coverage. All **559 tracked frontend file hashes** were identical before and after the checks. The task Nuxt development process was verified and stopped before compilation to avoid concurrent generated-file writes.

| Native package script | Final result |
| --- | --- |
| `pnpm --dir frontend lint` | exit 0; one existing `vue/attributes-order` warning at `app/pages/admin/usuarios.vue:134`, zero errors |
| `pnpm --dir frontend typecheck` | exit 0; Nuxt and contract TypeScript checks |
| `pnpm --dir frontend test` | exit 0; **1,817 tests, 247 suites, 1,817 passed; zero failures, cancellations, skips or todos** |
| `pnpm --dir frontend build` | exit 0; completed Nuxt/Nitro production build |

Native logs and exit files are retained in the ignored canonical `.superpowers/sdd/align-erp-template-and-product-setup/frontend-7d9b6bf-{lint,typecheck,test,build}.{log,exit}` and the before/source and summary JSON manifests. Test duration was 325,644.299789 ms. This is one complete run, not an aggregate of focused selections.

The preceding full run at `e6e7916` failed 13 of 1,809 tests; its native exit 1 and logs remain preserved. Six stale standalone harnesses were corrected in `d2be311`: actual Portuguese labels and Cancel action, actual catalog parent composition, Vue host style support, real helper import maps and explicit component boundaries. Behavioral counts, grants, recovery and source assertions were preserved. Separately, `dc723db` adds eight direct real Form → preview/adoption → editor PATCH tests across Company, delegated Account, session and update-grant changes, each with late success and 403. These test-only changes are included in the complete 1,817-case result. No application guard was weakened to make tests pass.

The browser acceptance in [final-local-acceptance.md](final-local-acceptance.md) supplements these checks. A successful build/test does not establish live fiscal/SERPRO/provider acceptance or a complete accessibility certification. The lint warning above remains disclosed.

## Earlier integrated checkpoint — historical, superseded where covered above

Status: code checks passed; browser acceptance remains in progress. Task 3.1 remains pending until the missing computed state/transition measurements are complete.

Canonical worktree: `/home/obsidian/dev/opmoni/.worktrees/align-erp-template-and-product-setup`. Compilation began at `f1eb923`; actual HEAD advanced through backend-only `b37efbb` to `293f623`. The tested frontend tree remained unchanged. No full suite or production build was run. Checks ran serially in the reserved heavy slot, with independently copied dependencies.

| Check | Result | Evidence |
| --- | --- | --- |
| Actual package typecheck (Nuxt and contract TypeScript) | exit 0, no diagnostics | ignored `integrated-typecheck.log` |
| Nine named frontend test files | exit 0; 172 tests across 15 suites; no failure, skip or cancellation | ignored `integrated-tests.log` |
| Changed frontend TypeScript/Vue ESLint | exit 0, no diagnostics | ignored `integrated-eslint.log` |

Commands run from the canonical worktree:

```sh
PNPM_CONFIG_VERIFY_DEPS_BEFORE_RUN=false NODE_PATH="$PWD/frontend/node_modules/.pnpm/node_modules" pnpm --dir frontend typecheck

PNPM_CONFIG_VERIFY_DEPS_BEFORE_RUN=false pnpm --dir frontend exec node --experimental-strip-types --test --test-concurrency=1 \
  tests/erpCompanySettingsFields.test.ts tests/erpCompanySettingsApi.test.ts \
  tests/erpProductProgressiveSections.test.ts tests/erpCatalogForms.test.ts \
  tests/erpContactGeography.test.ts tests/settingsNav.test.ts tests/settingsDemo.test.ts \
  tests/clientsCollapsedDisclosure.test.ts tests/sharedSemanticTheme.test.ts

PNPM_CONFIG_VERIFY_DEPS_BEFORE_RUN=false pnpm --dir frontend exec eslint \
  app/app.config.ts app/components/erp/ErpSettingsSectionForm.vue \
  app/components/erp/catalogs/ErpContactCatalogForm.vue app/components/erp/catalogs/ErpProductCatalogForm.vue \
  app/layouts/default.vue app/pages/erp/configuracoes/sistema.vue app/pages/settings.vue \
  app/utils/erpCompanySettingsFields.ts app/utils/settingsNav.ts \
  tests/clientsCollapsedDisclosure.test.ts tests/erpCatalogForms.test.ts \
  tests/erpCompanySettingsApi.test.ts tests/erpCompanySettingsFields.test.ts \
  tests/erpContactGeography.test.ts tests/erpProductProgressiveSections.test.ts \
  tests/settingsNav.test.ts tests/sharedSemanticTheme.test.ts
```

The pnpm wrapper avoids dependency-relocation installation; `NODE_PATH` resolves the installed transitive Vue Router compiler plugin. No dependency/configuration edit was made. Generated native Nuxt UI themes were current before tests.

After checks, one canonical frontend was launched with `python3 scripts/erp-template-runtime/start-frontend.py`: PID 136574, canonical `frontend` working directory, loopback port 3340. The launcher configured private ingress in memory without printing it. Browser and API requests were held pending controller verification and additive isolated-store migrations. No compiler, full suite, build, provider request or additional runtime was started concurrently.

Runtime ownership and final browser measurements are appended after execution. Private credentials and cookies remain ignored mode-0600 files; reports contain no values. Shared gates stay disabled. Existing historical semantic evidence is retained under `task-10-semantics/`; it does not establish acceptance of unmeasured integrated states.


## Browser checkpoint and ownership

Controller confirmed the task-owned store verifier passed and additive PostgreSQL migrations completed before browser/API requests. Registration, operating-write and provider/release gates remained disabled. Authentication used normal same-origin CSRF/login/identity/Company switch endpoints (200); the helper discovered the unique dedicated synthetic Company in the current `/me` response. No business mutation or provider action was attempted.

Accepted records (actual HEAD `293f623`, hydrated light mode, 1366×900):

- `task-10-semantics/integrated-office-light.json`: 16 measured roles; active navigation contrast7.010–7.192, secondary text7.023–7.720, neutral filled actions/input text17.717.
- `task-10-semantics/integrated-office-search-light.json`: 18 roles and actual visible Search dialog; placeholder7.720. No input values, private cookies or credentials are stored.

Rejected observations: first cookie import hit20s during browser startup; verified/reused the already-created named session without restarting it. First navigation hit25s during46.1s SSR; a failed Nuxt entry dynamic import prevented hydration. SSR-only captures failed the collector assertion and were not saved. One warmed navigation to the same page succeeded, then hydration was confirmed. After the two accepted records, Escape, viewport390 and snapshot each hit15s (exit124), at load55.42/35.13/43.34,81 runnable tasks and full swap. These timeouts establish no mobile/ERP outcome. The controller stopped collection; no repeat timeout/restart loop followed.

`agent-browser --session opmoni-task10 close` completed exit0 within the15s bound. Only that named browser was closed. Nuxt PID136574 was verified alive with canonical `frontend` cwd and exact `nuxt.mjs dev --host 127.0.0.1 --port 3340` arguments and retained warm as instructed. Backend/stores and unrelated runtime processes were untouched. Canonical source ownership is available to the controller.

## Exact remaining acceptance sequence

Do not repeat green frontend checks unless its source changes. Check capacity and existing Nuxt identity first; reuse `scripts/erp-template-runtime/start-frontend.py` only after verifying its recorded process, never launch a duplicate. Use one named task browser, bounded commands (15–25s) and ignored mode0600 synthetic authentication helpers. Stop on renewed host pressure rather than replaying navigation loops. The current ignored helper is `.superpowers/sdd/align-erp-template-and-product-setup/task-10-browser.py`; every `capture STEM MODE` asserts actual hydration/mode and records viewport/source.

1. At desktop1366×900, light `/settings` → `/erp/cadastros/produtos` via normal native links/context. Capture `integrated-erp-light.json` after list and updated tokens settle. Open the real Product editor without saving; capture `integrated-overlay-light.json`, assert dialog outside ERP and matching semantic root roles. Close normally and return `/settings`; capture `integrated-office-return-light.json`. Do not substitute current Office start record for the missing return.
2. Measure the actual primary action at rest, settled hover, keyboard focus-visible and mouse-down active. Capture `integrated-erp-hover-light.json`, `integrated-erp-focus-light.json`, `integrated-erp-active-light.json`; verify actual pseudo states, effective foreground/background and focus-outline contrast, not only resolved classes. Release mouse outside the button to avoid triggering create/save. Obtain an actual disabled control in the native editor/read-only settings and record `integrated-disabled-light.json`; report inactive opacity separately, without claiming WCAG normal-text requirements apply to disabled controls.
3. Switch native color mode to dark; repeat actual `/settings` → ERP → teleported editor → `/settings`, capturing `integrated-office-dark.json`, `integrated-erp-dark.json`, `integrated-overlay-dark.json`, `integrated-office-return-dark.json`. Verify mode/token readiness each time and equivalent roles; include actual hovered/active/focus/disabled dark observations as applicable. Existing historical dark records do not establish this integrated transition.
4. At native390×844, capture Office Settings and visible Search dialog geometry in `integrated-office-mobile-light.json` / `integrated-office-search-mobile-light.json` (plus sanitized screenshots if useful). Check title, direct tabs, focus reachability, horizontal/vertical scrolling and visible dialog bounds. The attempted viewport command timed out, so no mobile state was accepted.
5. In the task synthetic Company, inspect actual typed setting groups and native Product basic/advanced disclosure rendering. Discover current Company/product/reference identifiers through normal authorized APIs, not historical IDs. If current server grants/gates permit, use a clearly synthetic minimal product/settings roundtrip and verify normal save/reload; preserve unknown fiscal facts/missing-fact labels. If server denies/gates writes, record the actual denial/read-only state and report the specific acceptance profile needed to the controller. Never enable a gate to force a save or implement pending fiscal-profile/native consumers here.

Keep the semantic task/checklist pending until those states are actually measured. No global visual or accessibility acceptance is claimed from the current records.
