## 1. Establish the implementation baseline

- [x] 1.1 Reuse or prepare an isolated `codex/` worktree under the repository's ignored `.worktrees/` directory and bind a task-specific runtime; verify source mounts, `opmoni_test`, isolated Redis stores and preserved dirty/untracked work in a delivered execution manifest.
- [x] 1.2 Recheck installed Nuxt UI APIs/examples through the required skill/MCP and record the matching `.dashboard`/Opmoni page-family references, resolved tokens, actual font and geometry; verify matched light/dark baseline samples for settings, catalog, operational list and editor before restyling.
- [x] 1.3 Map each A01–A13, F1–F9, V01–V09 and adopted MarketUP B01–B04/B06–B08 functional item to its native consumers and planned acceptance evidence; verify research hypotheses are not reported as delivered guarantees and no finding is represented only by an unused component or unimplemented lookup dependency.
- [x] 1.4 Record authoritative CFOP reference provenance/version, supported goods Sales/NF-e/NFC-e applicability checks and legacy default/snapshot shapes; verify canonical code examples against those sources and explicitly retain unsupported/unresolved cases without importing the inspected tenant's mappings, fiscal-nature presets or client flags.

## 2. Correct navigation and sidebar state

- [x] 2.1 Extend the existing destination registry with route/hash, grants and Fiscal area/model metadata, including stock cost layers and Fiscal configuration; verify sidebar/search/body agreement with `erpWorkspaceNav`, `erpModulesNav` and `erpFiscalWorkflows` tests plus restricted-grant cases.
- [x] 2.2 Derive Finance/Cash/Sales visible areas and section-root fallbacks from the permitted registry; verify payments-only, sessions-only, establishments-only and explicitly denied deep-link cases without weakening backend authorization.
- [x] 2.3 Give settings/catalog child pages one persistent shell and direct-link toolbar without nested panels; verify reload/history, active-link reachability and native horizontal navigation at 390px and desktop.
- [x] 2.4 Replace accumulated ERP expansion with one controlled group shared by both menu regions and reuse the office folding pattern where appropriate; verify sequential opens, manual fold/same-module hash, pinned child, leaf route, permission/context changes and collapsed/mobile keyboard/focus behavior in the existing sidebar suites.
- [ ] 2.5 Reuse the office Settings registry for consistent Portuguese labels/order while preserving demonstration state; verify `settingsNav` behavior and office/company context navigation in desktop/mobile browser samples.

## 3. Align shared visual roles and copy

- [ ] 3.1 Reconcile ERP-only semantic overrides with the shared green/zinc theme and native action/status variants; verify computed role parity, measured foreground/background contrast, teleported overlays and office → ERP → office transitions in light/dark mode.
- [ ] 3.2 Apply shared typography, gutters, task-specific widths and native control/card/table proportions through live shells and tokens; verify aligned settings/catalog edges, one outer gutter and purposeful operational column scrolling with matched reference screenshots.
- [ ] 3.3 Remove redundant framing and rewrite technical/generic copy at shared consumers and affected pages; verify the audit's visible examples are addressed while distinct task headings, warnings and demonstration notices remain understandable in Brazilian Portuguese.
- [ ] 3.4 Align Sales navigation/list/footer composition with existing table/pagination patterns; verify server totals/page transitions, mobile records, existing detail actions and pending-operation recovery through `erpSalesWorkflows` plus browser evidence.

## 4. Simplify products and typed settings

- [ ] 4.1 Add grouped settings metadata and string-code/exact-decimal semantics across frontend/API validation; verify save/reload of `00`, `01`, `101`, zero rates, invalid-code feedback and visible unresolved legacy numeric values using settings/catalog tests.
- [ ] 4.2 Refactor the product editor into a short basic section and optional Commercial details/Fiscal/Variants sections; verify name/unit-only save, service stock rules, unchanged advanced values, existing variant IDs/archive state and nullable price inheritance.
- [ ] 4.3 Move catalog primary/cancel actions to the reachable shared footer with native form association and consistent dirty-close behavior; verify pending/duplicate prevention, error focus, close/backdrop/Escape, dirty-input preservation and return focus at desktop/mobile.
- [ ] 4.4 Group company settings into meaningful tasks without losing the current save/error/authority contract; verify field-key preservation, read-only consultation, named 422 feedback and late responses after Company/Account/session changes.

## 5. Implement native initialization and default consumers

- [x] 5.1 Add the setup-state/native-reference migration and `br-basic-v1` manifest with explicit missing-value semantics; verify additive upgrade preserves existing settings, same-company constraints reject foreign references and the manifest contains no identities, tax assumptions or secrets.
- [x] 5.2 Implement the idempotent native initializer inside provisioning for locale/time/BRL preferences, whole-unit UN and default customer; verify successful basic setup, fault-injected atomic rollback and unchanged owner/Matriz/subscription/access behavior in backend feature tests.
- [ ] 5.3 Expose typed `native_defaults` alongside preserved legacy settings and connect default unit/currency to their product/commercial consumers; verify first-product unit resolution, unavailable-reference recovery, BRL propagation and no same-number mapping of opaque legacy IDs.
- [ ] 5.4 Connect the native default customer to eligible counter sales without inventing recipient facts; verify same-company selection, authorized override, existing explicit customer preservation and model-specific fiscal incompleteness feedback.
- [x] 5.5 Connect existing invoice schemes to the authoritative commercial allocator; verify new-company blank-prefix/four-digit sales, unchanged quote/order and existing-company numbering, collision skipping, immutable draft numbers and no sequence reset on rerun/default change.
- [ ] 5.6 Connect native commercial layouts to an authorized sale representation/print action with frozen relevant labels/settings; verify localized output, retained historical layout, company access, retry/failure behavior and clear separation from payment receipts and fiscal DANFE artifacts.

## 6. Reconcile existing-company setup safely

- [ ] 6.1 Add direct-owner initialization preview with sanitized create/reuse/skip/conflict results and actor/company/revision binding; verify customized UN, unresolved currency namespace, nested settings and effective-fallback differences are explicit without mutation.
- [ ] 6.2 Add locked missing-only apply using existing operation-identity/recovery conventions; verify preservation of null/false/zero/empty/nested values, stale-preview 409, equal retry reuse and complete rollback on required failure.
- [ ] 6.3 Add the settings preview/apply interface with current-authority fencing; verify direct-owner access, delegated/platform denial, changed-company conflict, lost-response recovery and no false completed-setup claim for unresolved conflicts.

## 7. Add profile and operation-nature configuration

- [ ] 7.1 Add company-scoped profile/immutable-revision tables, product profile/intent metadata and explicit company default-profile storage; verify additive migration leaves existing explicit/null product values and old snapshot versions unchanged, with no adoption/backfill.
- [ ] 7.2 Implement paginated profile CRUD/archive and granular grants using current company policies/audits; verify viewer/revoked/delegated access, foreign read 404/relationship 422, exact code/rate validation and retained historical revisions.
- [ ] 7.3 Implement explicit `value`/`inherit`/`unset` payload semantics and deliberate adoption; verify omission remains unchanged, null clears, zero is a value, conflicts return 422 and unrelated legacy-product edits never opt into inheritance.
- [ ] 7.4 Implement the authoritative resolver and preview with sources, applicable profile/company fallback and revision metadata; verify two shared products, PIS-only override, unresolved fields and invalid/archived/unavailable selected profile errors without silent fallback or guessed combined CST/CSOSN conversion.
- [ ] 7.5 Add the product profile selector, effective summary and optional per-field customization plus default-profile settings; verify visible company-default inheritance on new products, explicit basic profile-free save, existing-product adoption review, source visibility, exact values and stale/failed lookup recovery under current authority.
- [ ] 7.6 Add verified versioned read-only CFOP reference lookup through native company authorization and bounded catalog search; verify code/description/version metadata, supported context filtering, selected-record hydration, pagination and unavailable-reference recovery without inferred tax treatment.
- [ ] 7.7 Extend native operation natures with a configuration revision and same-company allowed CFOP choices while retaining `cfop` as the default; verify old single-CFOP payloads/IDs, implicit legacy allowed sets, one explicit default independent of ordering, duplicate/invalid choice rollback and preservation of unverified historical values.
- [ ] 7.8 Add paginated nature/product exception APIs with native grants, attribution, uniqueness and archive behavior; verify same-company nature/product/choice constraints, inherited establishment scope, revoked/delegated authority, foreign read 404/relationship 422, stale-revision 409 and absence of stock/finance/provider effects from configuration writes.
- [ ] 7.9 Implement the focused server CFOP resolver and authorized operational preview alongside product-tax resolution; verify explicit choice → active applicable product exception → nature default → unresolved precedence, invalid-target errors without fallback, exact line omission/null semantics, required-context feedback and separate tax/CFOP provenance.
- [ ] 7.10 Expose allowed/default CFOP configuration and product exceptions through existing catalog editors/reference selectors, retaining the current template; verify explicit reviewed revision submission, bounded product/CFOP selection, atomic error recovery and preserved company/authority fencing without copying MarketUP UI.

## 8. Integrate reviewed snapshots and fiscal readiness

- [ ] 8.1 Add coherent profile/default and nature/CFOP provenance to versioned commercial snapshots with old-shape readers; verify effective facts freeze before effects, `operation_nature.cfop` remains the effective readable line code, catalog default/provenance remain distinguishable and standalone taxes, pricing/discount authority and exact totals are not double-applied or changed.
- [ ] 8.2 Add explicit reviewed tax/CFOP refresh for eligible drafts and preserve unchanged-line facts on ordinary edits; verify profile/nature/default/exception and product/context changes, line override clearing, added/replaced lines, stale reviewed fingerprints and quote/order conversion cannot silently replace reviewed classifications or totals.
- [ ] 8.3 Add nullable explicit NF-e/NFC-e intent to eligible sale drafts and model-specific pre-post readiness; verify missing applicable nature/CFOP/context or other mandatory fiscal facts produce field errors before stock/finance effects, while existing/basic non-fiscal operations retain their contract without profile-driven fiscal requirements or fabricated readiness.
- [ ] 8.4 Make linked fiscal preparation consume frozen effective tax/CFOP facts and provenance while preserving all source-equality checks; verify per-product exception codes differing from the default, changed current issuer nature, later catalog/profile/archive changes and legacy incomplete snapshots never trigger re-resolution, source substitution, historical repair or provider dispatch.
- [ ] 8.5 Expose draft tax/CFOP refresh, resolution source, readiness and historical-source limitations in existing commercial/fiscal surfaces without changing operation recovery identities; verify field correction, explicit review/post separation, lost responses, authority changes and actual fiscal status labels through the workflow suites.
- [ ] 8.6 Preserve purchase-line and return-source compatibility when extending operation-nature readers; verify existing per-line purchase references, previous single-CFOP snapshots, supported return codes and typed CT-e/MDF-e sources retain their checks without automatic purchase resolution, fabricated provenance or new operation kinds.

## 9. Correct remaining operational forms

- [ ] 9.1 Replace whole-catalog commercial/purchase reference loading with bounded search and selected-record hydration using the existing picker plus a focused variant adapter; verify multi-page SKU/name search, identical variant labels, unavailable historical selection, lookup failures and rapid context switches.
- [ ] 9.2 Reconcile contact municipality/state/code editing with fiscal-readiness projection while preserving unrelated address keys; verify minimal contact save, city/UF changes with old hidden codes, explicit clearing and failed lookup recovery through catalog/fiscal contract tests.
- [ ] 9.3 Align typed CT-e/MDF-e field descriptors, required conditions and backend/frontend error paths, and make Fiscal creation action-triggered; verify nested party/tax/vehicle/route errors focus the real control, edits persist, browsing stays primary and builders/gates remain strict.
- [ ] 9.4 Validate retained financial account/session choices against current currency/establishment/method before submit; verify incompatible visible selections, failed refresh, open-session requirements and unchanged backend rejections in finance/cash tests.
- [ ] 9.5 Convert company administration to existing native form/editor patterns and disclose detailed grants on demand; verify protected owner behavior, direct-owner restrictions, identity lookup, establishment scope, stale mutation blocking and focus return.
- [ ] 9.6 Reproduce the office-Fiscal populated scrolling risk using isolated representative data; verify and document either no defect or a focused correction with scroll/focus evidence, without reporting the original unconfirmed risk as already reproduced.

## 10. Verify concurrency and cross-layer compatibility

- [ ] 10.1 Add independent-process PostgreSQL/Redis initialization races and stale-preview/owner-revocation races; verify one baseline/default set, no overwritten choice, rollback and preserved development stores using the task's isolated integration runtime.
- [ ] 10.2 Add independent-process profile/default revision races against draft save, explicit refresh and fiscal-intended posting; verify one coherent revision set or recoverable pre-effect conflict, no mixed facts and no duplicate stock/finance effects.
- [ ] 10.3 Add cross-layer regressions for historical snapshot readers, single-CFOP payloads, profile/rule archive/adoption, tenant assertions, delegated grants and idempotent recovery; verify old/new consumers preserve effective classification and posted/fiscal/financial meaning without widened access or current-default repair.
- [ ] 10.4 Add independent-process nature/default/choice/exception and legacy-writer races against configuration updates, draft refresh and posting; verify aggregate revision advancement, one active exception per product/nature, valid default membership, shared lock order, coherent joint profile/CFOP fingerprints, stale 409 and no partial or duplicate business effects.
- [ ] 10.5 Run the full PostgreSQL integration profile with `vendor/bin/phpunit -c phpunit.pgsql.xml` in the verified isolated runtime; attach exit status/results and confirm `opmoni_test`, Redis 13–15 and independent processes were used, with development stores untouched.

## 11. Complete integrated acceptance and evidence

- [ ] 11.1 Run affected focused frontend/backend suites during each phase, then the integrated frontend scripts `pnpm --dir frontend lint`, `typecheck`, `test` and `build`; verify passing exit statuses and retain output tied to the implementation source identity.
- [ ] 11.2 Run the complete regular backend checks from the host repository layout with `FISCAL_ENVIRONMENT=producao composer test` and changed-PHP formatting with `vendor/bin/pint --dirty --format agent` from `backend/`; verify results distinguish optional live-provider skips from local contract acceptance.
- [ ] 11.3 Record matched template/Opmoni/ERP browser evidence at desktop 1366px, mobile 390px, an intermediate viewport and 200% zoom in light/dark; verify settings, product/basic/advanced editors, Finance, Sales, Fiscal, Reports and office regressions for geometry, contrast, hierarchy, scroll, keyboard/focus and overlay behavior.
- [ ] 11.4 Exercise isolated fresh-company defaults, existing-company preview/apply, product profile adoption/overrides, nature choice/default/exception setup, reviewed commercial tax/CFOP refresh and fiscal-readiness correction as real browser/API flows; verify denied/stale/failure/retry states, old-source consultation and retained operation identities without live dispatch or changed production gates.
- [ ] 11.5 Deliver an implementation evidence ledger mapping A/F/V and adopted B items plus spec scenarios to results, remaining dependencies and live-provider limits, update affected architecture/reconciliation documentation truthfully, and verify `openspec validate align-erp-template-and-product-setup --strict --no-interactive`; leave sync/archive and production rollout as separate workflows.
