# ERP template and product setup — final local acceptance

All **58 original tasks** are accepted within the selected implementation/local scope; the 129-row ledger preserves one explicit external-provider dependency rather than claiming live charge acceptance.

Recorded 2026-10-24 in isolated `codex/align-erp-template-and-product-setup`. This is implementation and local acceptance of the selected change. Main, production configuration, release/registration/provider gates, existing identities and development stores are preserved. Sync, archive, merge, push and production rollout are separate workflows.

## Complete checks and source identities

| Check | Actual final result | Source / retained evidence |
| --- | --- | --- |
| Complete frontend lint / typecheck / test / build | all native exits 0; **1,817/1,817 tests**, zero failed/cancelled/skipped; lint has one existing attribute-order warning | `7d9b6bf`; 559 tracked frontend hashes unchanged; [integrated validation](integrated-validation.md) |
| Complete regular backend `FISCAL_ENVIRONMENT=producao composer test` | native and outer exits 0; **3,470 total, 3,467 passed, 3 skipped**, **32,656 assertions**, zero errors/failures | `0c29afc`; 1,405 tracked backend hashes unchanged; whole checkout mounted in supported PHP 8.4, network disabled |
| Changed PHP Pint / canonical dirty Pint | actual scoped dirty nine-file Pint exit 0 with no formatter diff; subsequent canonical `vendor/bin/pint --dirty --format agent` exit 0 | backend fixture package `c4e64e6` integrated as `0c29afc`; canonical log records the separate no-dirty-file verification |
| Complete PostgreSQL/Redis integration profile | **229 tests / 26,884 assertions**, exit 0, zero errors/failures/skips | backend `53ed7e5`, [full profile and isolated cleanup](full-postgresql-acceptance.md); later backend changes were test fixtures only |

The regular backend's optional skipped cases are the live PGDAS homologation probe and two live SERPRO trial contracts (ordinary query and throttling). They are not local failures or live-provider passes. Native JUnit SHA-256 is `56abbe5148ac416f90b079ec374bffe83f52c630b2c14005f8f31cb67e90b707`; native log SHA-256 is `e8ea2467535f77d5cbb91695ec79b745694a93673d8c153c72a07779c51059ea`. Private artifacts are retained under canonical `.superpowers/sdd/align-erp-template-and-product-setup/backend-0c29afc-*`.

Earlier failing runs remain evidence: the regular backend had 170 fixture failures and the frontend had 13 stale-harness failures. Corrections use fixture-local release modes and native reviewed fiscal sale preparation, preserve all 179 public methods in the nine affected backend classes, and leave the generic fiscal helper unchanged. They do not alter persistent gates or manufacture accepted snapshots. The complete passing runs above supersede those failures only for their tested source.

## Browser and visual acceptance

The initial 32 matched template/Opmoni samples, integrated semantic transitions, navigation/editor captures and final native browser checks form a representative page-family matrix. Settings, catalog/product basic and advanced editors, Finance, Sales, Fiscal, Reports and Office were inspected across desktop 1366px, mobile 390px, intermediate widths and native Chrome 200% zoom, in light/dark samples. This is not every page in every combination and is not a full WCAG certification.

- Shared Public Sans typography, single shell/gutter and reference geometry remain in live consumers. Settings use the ordinary 2xl body and focused reconciliation/review uses a justified wider body. Native catalog parent tests retain the one-shell assertion. Teleported overlays, Office → ERP → Office root role parity, keyboard/focus and internal operational scrolling have recorded measurements.
- The final light Fiscal warning exposed a real 1.78:1 text contrast defect. `7d9b6bf` adds only the shared light `--ui-warning: var(--ui-color-warning-800)` semantic role. Actual served CSS, without injected styles, then measured **5.84:1 title / 4.79:1 description** in light and **9.11:1 / 7.73:1** in dark native 200% zoom. Native focused CFOP inset rings measured **7.19:1 / 9.75:1**; the separate 25% outer halo measured 1.51:1 / 1.71:1 and is not the sole focus indicator. The Office root token remained equal. There is no equivalent Office Fiscal alert in that sample, so component parity there is not invented.
- Sales shows 15 of 16 synthetic records on page one, one on page two, and restores page one. Desktop uses native table/navigation/footer; mobile uses compact records, reachable footer and internal vertical scrolling without page horizontal overflow. Real representation returned 200, preserved historical layout/localized values and displayed nonfiscal/nonpayment warnings. Legacy representation returned 409; Retry retained the explanation with another 409; explicitly selecting a compatible sale recovered with 200. Print was invoked, but a native OS print dialog/physical printing was not observable.
- Fiscal is browse-first. CT-e normal/complement and MDF-e retain separate unsent fields/keys when switching model/kind. Continue retains the draft; explicit discard permits navigation and a fresh blank editor. Company → Office → Company clears the private editor. Mounted delayed-route, pending operation, nested error-focus and late source-request tests complement the real browser route checks. This adds component-local draft retention, not reload autosave.
- Product basic/advanced presentation was inspected on an actually hydrated editor. Finance's actual empty response was 200. Reports' commercial-release denial was a visible 403, not presented as successful empty data. Demonstration notices and model/source-readiness caveats remain factual Portuguese copy.

Final browser artifacts are retained under canonical ignored `task-9.3-final-browser-e6e7916/{report.md,browser-evidence.json,...png}` and `task-3.4-5.6-browser-e173151/{report.md,browser-evidence.json,...png}`. Names identify collection history; manifests pin actual UI sources and hashes. The first image labelled dark 200% was actually light and remains explicitly rejected; later confirmed dark 200% and post-contrast-fix captures replace that claim. Browser sessions were closed and native zoom restored. Populated Office Fiscal wheel delivery remains unverified; its keyboard/scroll geometry and pagination are documented in [concurrency and scrolling](concurrency-and-scroll-acceptance.md).

## Real sequential HTTP API acceptance

A separate network-disabled PHP 8.4 container served the native application on its own `127.0.0.1:18764`; a distinct cURL process used actual sockets, cookie jars, CSRF, private ingress and Company middleware. UID 1001, read-only root/app/vendor, no published ports and exactly one new private UUID directory mounted read-write were verified before entry. Only that new SQLite database and file session/cache/locks were used. Normal additive migrations initialized the empty new file. Existing APP_KEY and all persistent configuration/stores remained unchanged.

The single run passed **96 HTTP steps**, native/container/outer exits 0, client PHP PID 1 and owned server PID 9: 66×200, 10×201, 1×204, 8×403, 1×404, 5×409 and 5×422, all expected, with zero transport errors.

| Native flow | Observed contract |
| --- | --- |
| Fresh Company | actual authenticated create, UN/BRL/default customer and locale; default customer has no invented recipient facts; subscription stays pending and operating write is denied |
| Existing Company | mutation-free preview, stale-settings conflict, missing key rejection, missing-only apply, exact same-key replay/lookup; explicit custom preferences survive |
| Product profile | native product and explicit variant; deliberate profile adoption, exact `00`/zero, override/unset/readoption; invalid numeric code and foreign unit rejected without changes |
| Nature/CFOP | explicit choice/default independent of list order, product exception, stale aggregate revision and invalid membership rejected |
| Reviewed commercial refresh | ordinary edit retains frozen facts, stale proof rejects before effects, fresh proof apply and same-key replay preserve original line identity, unit, quantities and money |
| Fiscal readiness correction | intended 55 incomplete POST returns actionable 422 without effects; native catalog correction does not repair the saved source; explicit preview/apply adopts facts, local POST/retry/lookup yield one receivable with unchanged money |
| Historical and authority boundaries | real previously posted v2 source stays equal after profile/nature edits; viewer writes and revoked membership fail; foreign/default and stale Company assertions retain their own rejection |

Only owner/viewer, an existing active prerequisite Company, membership/establishment/subscription/plan were seed prerequisites. Target setup/catalog/configuration/sales operations used native HTTP. The separately API-created fresh Company was never activated to force a pass. Two local posted sales produced exactly two obligations; fiscal documents, attempts, queue jobs, stock movements and payments stayed zero. No queue worker or external provider ran. This sequential SQLite walkthrough is separate from PostgreSQL concurrency acceptance and browser presentation; old source was actually posted v2, not fabricated v1.

Source was independently reviewed before runtime, including correction of explicit variant preparation and a pre-refresh money baseline. The run is retained at `erp-backend-acceptance-fixtures/.superpowers/specialist/task-11.4-api/runs/0425d81b-1c4d-4d20-9e13-77ac2675422c`. Canonical ignored launch/summary JSON records topology and wrapper/source hashes. Steps SHA-256: `98cec45a0f294ea7f7a6726a3399b23587bf56b2222280e88e0205b866943dbd`. Existing failed evidence cannot be overwritten by the empty-run guard. Independent same-reviewer runtime SPEC/QUALITY PASS confirmed all 96 steps and all 812 source-manifest hashes. After preserving evidence, root removed only that exact stopped owned container; the private SQLite/run directory remains retained. Root owns final acceptance; provider rollout remains outside scope.

## Evidence limits and delivery

[The coverage ledger](acceptance-coverage.csv) and [evidence map](evidence-map.md) map all 38 A/F/V/adopted-B items and all 91 delta scenarios. They distinguish exact tests, representative browser checks, real network API checks, source-only observations and remaining external dependencies. Local manual-payment recovery is not evidence of an external payment adapter or a real charge. Opaque legacy currency/municipality IDs are not reinterpreted as native IDs. Unsupported fiscal contexts stay unresolved rather than receiving guessed classification.

Live fiscal authorization, SERPRO credentialed acceptance, per-model production canaries, physical printing and production rollout are not claimed. Strict selected-change validation and the final checklist disposition are recorded in the execution report. Sync/archive remain separate and were not performed.
