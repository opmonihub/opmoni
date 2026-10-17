> Reconciliation status (2026-10-13): this artifact records the earlier platform target, not the delivered system baseline. See [system reconciliation](../../../docs/architecture/system-spec-reconciliation.md) and `reconcile-system-specs`. D009 supersedes foreign support entry/elevation. Other stronger API, catalog, certificate-source, and concurrency guarantees remain pending where absent or unverified. Reconcile this artifact against the canonical specs before implementation, sync, or archive; do not mark its unchecked tasks complete from the import or documentation sync.

## 1. Contract baseline and regression coverage

- [x] 1.1 Inspect applicable rules, installed Nuxt/UI/Laravel/HTTP-client versions, runtime OpenSSL/cURL support, and existing dirty changes; record the implementation baseline and verify no unrelated files are overwritten.
- [ ] 1.2 Define `frontend/shared/types/admin.ts` and additive support/Serpro identity types from the design route matrix, keeping existing issuance and identity envelopes; verify type fixtures for all seven pages and existing `tests-access.test.ts` pass.
- [ ] 1.3 Add backend contract/authorization tests for the new platform route family, including unauthenticated 401, non-Super Admin 403, no active office, CSRF/origin rejection, pagination shape, field validation, and non-cacheable output; verify the tests fail for missing implementations before proceeding.
- [x] 1.4 Add regression assertions for owner-link issuance/reissue, exact existing identity/credential preservation, default-office login, suspension, session expiry, and no fake membership; verify the existing access and tenancy suites pass before schema/context changes.

## 2. Catalog, subscriptions, and additive Account schema

- [ ] 2.1 Generate additive Account contact, Plan, and Subscription migrations/models/factories with unique slug/Account subscription, FK restrictions, and valid status constraints; verify migration tests enforce constraints and preserve existing Account/member/activation data.
- [x] 2.2 Implement insert-only `PlanCatalogSeeder` with the documented Básico/Profissional/Empresarial limits; verify repeat seeding preserves edited names/limits and creates only missing defaults.
- [x] 2.3 Implement repeatable `platform:initialize` subscription backfill with bounded chunks; verify two runs create one active Básico subscription per missing Account without resetting existing subscriptions or creating users/Accounts.
- [x] 2.4 Implement `EnsureAccountSubscription` inside existing onboarding and Account-issuance transactions, including safe lazy catalog initialization; verify fresh installation succeeds without prior seed execution and injected provisioning failure leaves no orphan Account/subscription/activation.

## 3. Platform Account backend

- [ ] 3.1 Add a consistent extended Account Resource and GET `/api/v1/admin/accounts/{id}`, preserving existing row fields and activation secrecy; verify list/detail envelopes, nested plan data, unknown-ID 404, and omission of hashes/URLs/secrets.
- [ ] 3.2 Extend creation/PATCH validation and actions for phone/WhatsApp/UF/city with null-clearing and omission semantics; verify clearing phone resets WhatsApp, invalid UF/flag combinations return field errors, and multi-field updates are atomic.
- [ ] 3.3 Preserve existing create and first-access reissue paths/route names/outputs while incorporating subscription/contact data; verify email conflicts, active zero-member reissue, seven-day expiry, prior-link invalidation, and temporary-copy-only behavior through existing access tests.
- [ ] 3.4 Extend Account list pagination with `last_page`, literal name/ID search, bounded query validation, and eager-loaded plan/member/owner metadata; verify filters, deterministic ordering, invalid pages/search, and response compatibility.

## 4. Plans and subscriptions backend

- [ ] 4.1 Implement Super Admin GET/PUT Plan APIs with immutable slug and complete nullable limits replacement; verify name bounds, negative/fractional/extra-key rejection, finite-to-unlimited clearing, and unchanged values after failed validation.
- [x] 4.2 Implement paginated Subscription GET and atomic PUT with real plan validation, Account/plan search, and status filtering; verify all status labels/contracts, invalid plan 422, unknown subscription 404, and page metadata.
- [ ] 4.3 Keep commercial status independent from operational Account suspension and quotas; verify canceled/past_due subscriptions do not change membership, activation eligibility, or tenant access for an active Account. *(Superseded by D009 — status comercial afeta writes/limites via `ResolveAccount`/`PlanLimits`.)*

## 5. Support persistence and session transitions

> **D009 — superseded (não implementar):** 5.1–5.5 descrevem persistência de contexto de suporte estrangeiro, entrada/saída e projeção de identidade. O baseline aceito exige **membership real**; não restaurar rotas enter/exit nem elevação implícita. Ver [system-spec-reconciliation](../../../docs/architecture/system-spec-reconciliation.md).

- [ ] 5.1 Generate SupportContext and SupportAccessLog migrations/models/factories with lifecycle UUIDs, actor/Account references, safe metadata, and query indexes; verify constraints and that audit references cannot be cascade-erased. *(Superseded by D009 for foreign context; `SupportAccessLog` existente cobre auditoria de escrita legada.)*
- [ ] 5.2 Implement transactional support entry with active-target/privilege/membership checks, serialized session ownership, deterministic Account locking, and persisted open-context reconciliation; verify foreign entry, own-membership switching, same-target idempotency, target change exit/enter, no membership creation, and unchanged deadline. *(Superseded by D009 — foreign support entry.)*
- [ ] 5.3 Implement idempotent support exit restoring prior active membership/default/null; verify successful exit with no own Account, no-op exit without support, suspended prior Account fallback, rejection of forged/closed support privilege, and rollback when audit insertion fails. *(Superseded by D009 — foreign support exit.)*
- [ ] 5.4 Reconcile support in `/me` and clear it on new login, logout, ordinary switching, suspension, or privilege loss; verify invalid context never produces effective admin, logout remains revocation-safe, and a new login cannot resume support. *(Superseded by D009 — persisted foreign support state.)*
- [ ] 5.5 Extend IdentityResource with real-membership `is_member`, a support current-Account projection, and `support` metadata while keeping the top-level envelope; verify foreign Account remains absent from `accounts` and membership/default-office outputs remain compatible. *(Superseded by D009 — support identity projection.)*

## 6. Support-aware tenant isolation and audit

> **D009 — superseded (não implementar):** 6.1, 6.2 e 6.4 dependem do ciclo de suporte estrangeiro. **6.3** permanece relevante apenas como **leitura de auditoria** (`GET /admin/support/logs`) alinhada ao [platform-support-access](../../specs/platform-support-access/spec.md) canônico.

- [ ] 6.1 Extend CurrentAccount and ResolveAccount with explicit effective role/nullable real membership, actor, and support state; inventory existing membership dereferences and verify own-member roles, support admin role, missing-context failure, suspended target denial, body Account rejection, and stale `X-Account-Id` 409. *(Superseded by D009 — effective admin / foreign support role.)*
- [ ] 6.2 Add registered support-write resource/action descriptors and transactional allowlisted audit integration inside the tenant transaction; verify test-only tenant create/update/delete audit, reads without write events, unsupported-write denial, and both mutation/audit rollback on injected failure. *(Superseded by D009 — audited foreign-write lifecycle.)*
- [ ] 6.3 Add GET `/api/v1/admin/support/logs` with safe resources, Account-ID validation, deterministic newest ordering, and pagination; verify actor/Account metadata and that passwords/tokens/containers/session identifiers never appear.
- [ ] 6.4 Add independent-process PostgreSQL/Redis support integration cases for duplicate entry, concurrent target change/exit, suspension versus write, expired session lock ownership, and failed Redis/session persistence reconciliation; verify no successful unaudited access or cross-Account write occurs. *(Superseded by D009 — foreign-support process races.)*

## 7. Global users and summary backend

- [x] 7.1 Implement read-only GET `/api/v1/admin/users` with `page,q,type`, eager-loaded real Account-role links, and allowlisted identity fields; verify multi-membership badges data, global type filtering, literal search, and no user mutation endpoints or password/session disclosures.
- [ ] 7.2 Implement GET `/api/v1/admin/summary` with real four-entity totals and five safe recent support events; verify populated/empty counts, newest ordering, audit shape reuse, and Super Admin access without tenant selection.

## 8. Serpro certificate and connection lifecycle

- [ ] 8.1 Generate AccountContractingCertificate and singleton SerproConnection migrations/models/factories with encrypted/hidden material, source FKs, safe metadata, and revisions; verify encryption at rest, singleton/source constraints, and no raw secret fields in model serialization.
- [ ] 8.2 Implement real PKCS#12 validation for 2 MiB `.pfx/.p12`, supplied empty/missing password distinctions, key/certificate match, validity, and unambiguous supported CNPJ; verify generated real UploadedFile cases including wrong password, malformed, oversized, expired, not-yet-valid, conflicting identity, and runtime compatibility failures.
- [ ] 8.3 Implement Super Admin Account contracting-certificate metadata/PUT APIs with active-source checks and no download/delete routes; verify safe responses, non-first Account support, unauthorized/suspended source rejection, and atomic attached-connection metadata/revision updates on replacement.
- [ ] 8.4 Implement safe connection GET with explicit `configured=false` and atomic initial/partial/source-switch PUT; verify all initial requirements, omitted/blank key/secret preservation, failed replacement rollback, certificate-source validation, and no duplicate singleton under competing writes.
- [x] 8.5 Implement private certificate materialization with restrictive permissions and guaranteed cleanup; verify success, HTTP failure, parse/decrypt failure, and exception paths leave no temporary material or sensitive output.
- [ ] 8.6 Add credential/source-rotation PostgreSQL concurrency cases with fixed lock ordering; verify coherent final source/metadata/revision, singleton uniqueness, no mixed old/new credential snapshot, and connectivity 409 `connection_changed` when rotation occurs during HTTP.

## 9. Serpro connectivity and ingress

- [ ] 9.1 Implement server-controlled mutual-TLS authentication per the linked official Serpro guide using installed-client-compatible options, Basic credentials, form body, no redirects, and TLS verification; verify HTTP fakes assert required auth request and require both token fields without exposing or caching them.
- [ ] 9.2 Implement named connectivity verdicts for unconfigured/certificate/credential/provider failures plus six/min actor and trusted-IP budgets; verify no network call for local failure, upstream 429/5xx/timeout classification, application 429 Retry-After, and no fiscal/service/sync calls.
- [ ] 9.3 Extend the frontend API transport for FormData POST with `_method=PUT` and proxy binary forwarding while preserving all origin/CSRF/cookie/ingress protections; verify real file-byte equality and forbidden-header behavior through frontend ingress and Laravel.
- [ ] 9.4 Enforce 2 MiB file/4 MiB request limits and align provider connect/total timeouts (3s/8s), selected proxy timeout (15s), and existing session-lock fencing; verify 413, timed provider failure, ownership loss rejection, and unchanged 10s behavior for unrelated access routes.
- [ ] 9.5 Add non-secret `.env.example` configuration/runtime prerequisites for authentication URL, timeouts, private temp storage, and PHP OpenSSL/cURL support; verify container capability checks and that no real credentials or provider payloads enter tracked files/logs.

## 10. Shared Admin frontend

- [ ] 10.1 Port compatible table panel/tokens, column menu/SSR-safe media query, empty state, row action menu, metric cards, and retry handling from the mapped source inventory; verify responsive component rendering and lint/typecheck without importing unrelated legacy modules.
- [x] 10.2 Add tested adminNav route helpers and the seven-page sidebar/search catalog; verify exact summary matching, active child/parent, Super Admin-only destinations, collapsed popover, and mobile-close callbacks.
- [ ] 10.3 Add the shared `pages/admin.vue` shell and remove the Account-only panel wrapper; verify every direct Admin route displays one panel/navbar with matching title/tabs.
- [ ] 10.4 Generalize route/focus guards and accessDestination to administrative routes and explicit support identity; verify ordinary-user denial, Super Admin with no office, suspension/privilege refresh, and no redirect loops through existing/new frontend access tests.
- [ ] 10.5 Add reusable typed list-query/pagination/stale-response handling and adapt the frontend test command to include the new test files; verify debounce/filter reset, latest-response retention, invalid/out-of-range page recovery, and initial-error versus stale-success states.

## 11. Seven pages connected to backend APIs

- [ ] 11.1 Implement Resumo using the real summary endpoint, linked metric cards, five-event audit table, column menu, retry/empty states, and refresh preservation; verify API failure never renders false zero metrics.
- [ ] 11.2 Adapt Contas to the shared table with plan/member/contact data, create/edit/reissue/status-confirmation modals, and safe activation copying; verify current first-access behavior and all field/loading/clipboard states against the working Account APIs.
- [x] 11.3 Implement Planos local search/table/column controls and edit-limits slideover; verify finite-to-null serialization survives save/reload and no creation/deletion action is introduced.
- [ ] 11.4 Implement Assinaturas filters/pagination/status badges and plan/status edit slideover; verify field errors preserve inputs, plan choices are real, catalog failures offer retry, and status edits leave Account access unchanged.
- [x] 11.5 Implement Usuários with name/email/type filtering, real Account-role badges, column controls, pagination/count, and no write actions; verify empty, multi-membership, and unauthorized states.
- [ ] 11.6 Implement Suporte Entrar/Auditoria tabs using server-side Account search/detail and real audit pagination; verify consistent detail envelopes, suspended entry feedback, positive Account filtering, idempotent entry, and no full-platform client-side scan.
- [ ] 11.7 Integrate enter/exit/member-switch auth actions and persistent SupportBanner; verify full client tenant-state reset, correct restored navigation, no-own-membership exit to Admin, logout, and cross-tab focus reconciliation. *(Superseded by D009 — enter/exit/banner de suporte estrangeiro.)*
- [ ] 11.8 Implement Serpro metadata/source selector, explicit Account source search/inline certificate upload, secret-safe rotation form, and connectivity card; verify both certificate sources, real multipart save, cleared secret fields/results, retry, named failures, and duplicate-submit prevention.

## 12. Complete-change verification and handoff

- [ ] 12.1 Run independent PostgreSQL/Redis provisioning/backfill races in addition to support and credential races; verify duplicate prevention and preservation of existing identities/activations, with actual process-level evidence recorded separately from feature tests.
- [ ] 12.2 Run backend feature/regression suites and PostgreSQL/Redis integration suite in isolated test resources (`php artisan test --compact` and `vendor/bin/phpunit -c phpunit.pgsql.xml` in the backend environment); verify passing output without touching development data.
- [x] 12.3 Run `pnpm --dir frontend lint`, `typecheck`, `test`, and `build`, plus `vendor/bin/pint --dirty --format agent` for changed PHP; verify final command output and fix relevant failures before marking tasks complete.

## Evidence audit — 2026-10-13

This audit checks the implementation in the root `main` checkout against each original task. Checked items have implementation and current verification evidence; unchecked items can already contain delivered behavior. They are not a count of missing features. Original acceptance criteria are preserved: a partial implementation or a superseded contract is not silently converted into a completed task. No application source was modified. Re-run verification after subsequent implementation changes.

The separate `.worktrees/add-super-admin-platform` checkout contains reviewed contract/provisioning commits `ec30e40` and `bd35298`, plus unfinished Account API edits. Those changes are not integrated into this `main` baseline and do not complete tasks here. Foreign support elevation is superseded by D009; its unchecked tasks must not be interpreted as authorization to restore it.

### Current verification evidence

- Baseline: Nuxt 4.6.0, Nuxt UI 4.11.3, Laravel 13.35.0, Guzzle 8.2.0; backend container PHP 8.4.26 with OpenSSL and cURL. Applicable task rules and existing dirty files were inspected. SHA-256 comparison of 953 implementation/test sources found no changes during the audit.
- Backend: `FISCAL_ENVIRONMENT=producao vendor/bin/phpunit --filter 'AccessFoundationTest|BusinessAccessBoundaryTest|BusinessIntegrationTest|AdminListFiltersTest|SerproCertificateMaterializerTest|SerproConnectionApiTest|SerproConnectivityTest|SerproConnectionTest|SubscriptionsTest|RolesTest|SecurityRefactorTest|ConsistencyRefactorTest' --log-junit /tmp/opmoni-platform-task-audit-backend.xml` passed 114 tests and 1,112 assertions in the host repository layout, with the existing bootstrap forcing SQLite in memory and array sessions/cache.
- Additional in-memory HTTP/materialization checks passed 55 assertions: two actual membership roles in `/admin/users`, no-office Super Admin access, ordinary-user denial, no global user mutation routes or identity secrets; all three subscription statuses, plan selection, invalid-plan/status rollback, missing-ID 404, pagination; preservation of the complete existing user row on owner-email conflict; 0600 certificate permissions, byte equality, successful cleanup and corrupt-encryption cleanup. These were transient audit checks; no test or application file was added.
- Frontend: `pnpm --dir frontend test` passed all 598 tests with no failures or skips. `pnpm --dir frontend lint`, `pnpm --dir frontend typecheck`, and `pnpm --dir frontend build` completed successfully. Logs are local execution evidence under `/tmp/opmoni-platform-task-audit-{frontend,lint,typecheck,build}.log`.
- Compiled actual Vue setup/template checks verified the Plan form's finite-to-unlimited save/reload and edit-only actions, Users multi-office badges/empty state/filtering/count, and the actual layout's seven Admin sidebar/search destinations, Super Admin visibility, active parent/child, collapsed-popover wiring and all mobile-close callbacks. Existing route-guard tests verify ordinary-user denial. These component checks do not claim browser/viewport acceptance.
- Plan UI null fields intentionally omit keys from the complete replacement `limits` object; the existing controller replaces that object, and missing keys render as unlimited after reload. `AdminListFiltersTest` verifies empty-object clearing persists in the real backend. This completes the UI behavior in 11.3, not the stricter API validation in 4.1.
- No PHP source changed during this audit and the root checkout has no pending PHP edits; the changed-PHP Pint step in 12.3 is not applicable. PostgreSQL/Redis process races and live providers were not rerun. Whole-suite and browser acceptance tasks remain unchecked.

### Task-by-task disposition

| Task | Disposition | Implementation evidence or remaining criterion |
| --- | --- | --- |
| 1.1 | Verified | Installed versions, container extensions, applicable rules, dirty baseline and unchanged implementation hashes recorded above. |
| 1.2 | Partial | Pages have local types; the planned shared `frontend/shared/types/admin.ts`, seven-page fixtures and support identity contract are absent from main. |
| 1.3 | Partial | Existing access, role, filter and provider tests cover delivered APIs; the complete original route matrix and historical failing-test baseline are not established. |
| 1.4 | Verified | AccessFoundationTest covers issuance/reissue, exact passwords, default login, suspension and expiry; boundary/role tests deny foreign membership access; the additional owner-conflict check preserved the complete existing identity row. |
| 2.1 | Partial | Plan/subscription migrations and models exist; the proposed root Account contact schema and complete constraint/migration acceptance are not delivered as specified. |
| 2.2 | Missing | `PlanSeeder` uses upsert; the insert-only `PlanCatalogSeeder` is not in main. |
| 2.3 | Missing | The bounded, repeatable `platform:initialize` command is not in main. |
| 2.4 | Partial | Account observer initializes a default subscription; the planned EnsureAccountSubscription action and full injected-failure acceptance are absent. |
| 3.1 | Partial | Separate `/admin/accounts/{account}/commercial` metadata exists; the proposed generic Account detail API/resource is absent. |
| 3.2 | Partial | Commercial editor stores contacts in settings; creation/root PATCH do not implement the proposed contact/null-clearing contract. |
| 3.3 | Partial | Original issuance/reissue routes, secrecy and subscription defaults work; incorporation of the proposed contact fields into issuance remains absent. |
| 3.4 | Partial | Account name/ID search and page validation exist; the response lacks the planned `last_page` and eager-loaded combined plan/member/owner projection. |
| 4.1 | Partial | Plan GET/PUT and replacement limits exist; exact nullable-key validation, negative/fractional/extra-key rejection and minimum API name length differ. |
| 4.2 | Verified | SubscriptionController, IndexSubscriptionRequest, SubscriptionResource and current HTTP checks cover plan/status updates, filters, pagination and atomic validation rejection. |
| 4.3 | Superseded by D009 | Current subscription status gates tenant writes and limits govern creation; the original metadata-only contract conflicts with the accepted baseline. |
| 5.1 | Superseded by D009 | Existing SupportAccessLog is delivered; the proposed foreign-support context/lifecycle persistence is outside current accepted authorization. |
| 5.2 | Superseded by D009 | Switching requires real membership; foreign support entry/elevation is not an implementation backlog item. |
| 5.3 | Superseded by D009 | No foreign support exit/restoration lifecycle is authorized. |
| 5.4 | Superseded by D009 | Identity/session handling uses real membership, without persisted foreign support state. |
| 5.5 | Superseded by D009 | Identity projects real memberships; the proposed support/is_member elevation contract is obsolete. |
| 6.1 | Superseded by D009 | CurrentAccount/ResolveAccount enforce real roles and Account assertions; the proposed foreign-support effective admin role is obsolete. |
| 6.2 | Superseded by D009 | SupportAudit helper exists but does not establish the proposed active audited foreign-write lifecycle. |
| 6.3 | Partial | SupportLogController provides filtering/newest pagination; raw metadata passes through and the stronger allowlisted/redacted lifecycle contract is not proven. |
| 6.4 | Superseded by D009 | The foreign-support process-race scenarios depend on the superseded context. This does not waive current membership/session concurrency verification. |
| 7.1 | Verified | UserController, IndexUserRequest and UserResource provide read-only, paginated, literal-search/type-filtered global users with real role links and safe identity fields. |
| 7.2 | Different delivery | Admin summary composes existing APIs; `/api/v1/admin/summary` does not exist. |
| 8.1 | Partial | Encrypted singleton SerproConnection and AccountCertificate exist; the proposed separate AccountContractingCertificate/revision/source schema is absent. |
| 8.2 | Partial | Real P12 upload, CNPJ, password and expiry checks exist; exact 2 MiB/413 handling and the complete future-validity/key-match/runtime matrix are not established. |
| 8.3 | Different delivery | Office certificate lives in tenant settings; the proposed arbitrary-Account contracting-certificate API is absent. |
| 8.4 | Partial | Unconfigured metadata, rotation, secret preservation and singleton enforcement exist; arbitrary source selection/metadata and independent competing-write acceptance differ. |
| 8.5 | Verified | SerproCertificateMaterializer uses private temporary paths, 0600 and finally cleanup; focused materializer/connectivity tests and direct permission/decryption checks pass. |
| 8.6 | Verification missing | No original credential/source-rotation PostgreSQL process suite or `connection_changed` revision fence is established. |
| 9.1 | Partial | Server-side Basic/form/P12 authentication exists; connectivity refresh deliberately caches tokens and the complete no-redirect/TLS acceptance is not established for the original contract. |
| 9.2 | Partial | Named safe verdicts and six-per-minute route throttle exist; independent actor plus trusted-IP budgets are not implemented as specified. |
| 9.3 | Partial | FormData method override and private proxy forwarding exist; end-to-end ingress/Laravel file-byte equality and all original header cases were not established. |
| 9.4 | Different delivery | Current provider timeout is 25s and proxy timeout 120s, rather than 3s/8s/15s; original upload/request/status and session-fencing acceptance is incomplete. |
| 9.5 | Partial | Runtime/config defaults exist and container OpenSSL/cURL work; examples omit the requested SERPRO URL/timeouts/private-temp configuration. |
| 10.1 | Partial | Shared data-table/column/empty/retry components and tokens exist; full responsive component acceptance against the source inventory was not repeated. |
| 10.2 | Verified | adminNav/sidebar tests and actual layout checks cover all seven destinations, search, exact summary match, active states, visibility, collapsed wiring and close callbacks. |
| 10.3 | Partial | `pages/admin.vue` exists; Accounts still use a dedicated layout wrapper and bypass the shared title/tabs. |
| 10.4 | Partial; support superseded | Global Admin/focus guards are delivered and access tests pass; the original explicit foreign-support identity branch is superseded. |
| 10.5 | Partial | Typed query/meta/latest-request utilities, debounce, tests and retry states exist; out-of-range page recovery is not applied consistently across the original lists. |
| 11.1 | Different delivery | Resumo composes APIs with retry/preservation; the task explicitly requires the absent dedicated summary endpoint. |
| 11.2 | Partial | Account activation/create/status and separate commercial editing exist; the proposed combined shared table/contact/edit workflow is not complete. |
| 11.3 | Verified | Actual Planos form, local filtering, columns and slideover implement edit-only finite-to-unlimited replacement; compiled save/reload checks and backend clearing regression pass. |
| 11.4 | Partial; status contract superseded | Subscription filters, badges and editor exist; field-error/catalog acceptance was not repeated and unchanged tenant write access conflicts with D009. |
| 11.5 | Verified | Actual Users page shows multi-office role badges, filters, columns, pagination/count and empty state; it issues read-only requests and uses tested Super Admin guards. |
| 11.6 | Different delivery | Suporte has membership-based entry and audit browsing; it scans matching Account pages instead of the proposed detail lookup and has no foreign-entry lifecycle. |
| 11.7 | Superseded by D009 | Real-member switching/logout exists; foreign support enter/exit and SupportBanner are obsolete. |
| 11.8 | Partial | Serpro metadata, two current source modes, rotation and connectivity UI exist; arbitrary Account source search/upload and stronger source metadata are absent. |
| 12.1 | Verification missing | Original platform provisioning/backfill and credential process races are not established; obsolete support races do not count as completion. |
| 12.2 | Verification incomplete | This audit passed 114 focused tests; the full backend plus PostgreSQL/Redis suite was not rerun here. Historical reconciliation results remain separately documented. |
| 12.3 | Verified | Current frontend test/lint/typecheck/build pass; no changed PHP requires Pint in this audit. |
| 12.4 | Verification missing | Full seven-route desktop/mobile browser and every form/error/upload acceptance were not repeated; foreign-support cases require scope reconciliation. |
| 12.5 | Incomplete | Task audit found actionable contract differences; this is not a clean whole-change acceptance or migration rollback review. |
| 12.6 | Partial | Architecture, runtime, canonical specs and D009 limits are documented; the planned initialization command is absent and the original proposal still needs reconciliation. |

Archive readiness: **not complete**. Nine of 56 original tasks are checked, with explicit dispositions for the other 47. Reconcile superseded/differently delivered requirements before a future archive; do not sync the original foreign-support deltas over the canonical D009 baseline.

## Evidence audit — 2026-10-17

Re-audit focado em admin de plataforma (escopo D009, sem ERP/fiscal). Código alterado nesta sessão: sanitização de `metadata` em `SupportAccessLogResource` via `SupportAccessLogMetadata`, mais `tests/Feature/Admin/SupportLogApiTest.php`.

### Verificação executada

- Backend (SQLite in-memory, `FISCAL_ENVIRONMENT=producao`): `vendor/bin/phpunit --filter 'SupportLogApiTest|AccessFoundationTest|BusinessAccessBoundaryTest|BusinessIntegrationTest|AdminListFiltersTest|SubscriptionsTest|RolesTest'` — **49 testes, 329 asserções, zero falhas**.
- Frontend admin/acesso: `node --test tests/adminNav.test.ts tests-access.test.ts` — **26 testes, zero falhas**.
- `vendor/bin/pint --dirty --format agent` nos PHP alterados — OK.

### Disposição atualizada

| Task | Disposição | Notas |
| --- | --- | --- |
| 6.3 | Partial (melhorado) | Endpoint e paginação já existiam; agora há filtro `account_id` testado, ordenação descendente, acesso Super Admin sem escritório, e redação recursiva de chaves sensíveis em `metadata`. Contrato histórico de allowlist completa / lifecycle auditado continua fora de escopo D009. |
| 5.1–5.5, 6.1–6.2, 6.4, 11.7 | Superseded by D009 | Anotações explícitas adicionadas nas seções 5–6; não marcar como concluídas. |
| 4.3 | Superseded by D009 | Anotação na linha da task; comportamento importado (gate de subscription) permanece canônico. |
| 1.1, 1.4, 4.2, 7.1, 8.5, 10.2, 11.3, 11.5, 12.3 | Verified | Reconfirmados pelos testes focados acima (sem alteração de critério). |
| Demais 47 | Inalterado | Permanecem unchecked conforme auditoria 2026-10-13; targets pendentes ou entrega diferente documentada em [system-spec-reconciliation](../../../docs/architecture/system-spec-reconciliation.md). |

Progresso OpenSpec: **9/56** tasks marcadas `[x]` (sem novas marcações — nenhuma task atingiu critério completo adicional nesta sessão).
- [ ] 12.4 Exercise seven direct routes in the browser at 1366×639 and a mobile viewport, expanded/collapsed/sidebar/tab/search navigation, ordinary-user denial, no-office Super Admin, every form/error/empty state, support entry/exit/focus, and real file upload; verify screenshots/observations against the legacy inventory and current activation contracts.
- [ ] 12.5 Review the whole change against all five specs, API/types compatibility, dirty-file preservation, secret redaction, transaction/session failure paths, and migration rollback plan; resolve actionable findings and verify required checks again only when fixes justify it.
- [ ] 12.6 Update architecture/API/runtime instructions and product decision records with the implemented scope, idempotent initialization command, supported Admin behavior, and known verification limits; verify no unsupported operational/billing functionality is claimed and only completed evidence-backed tasks are checked off.


## Compatible provisioning implementation — 2026-10-17

Worktree: `.worktrees/platform-pending`, branch `codex/platform-pending`. Source baseline `d4b65ea`; implementation `92bfd8f`. Tasks 2.2, 2.3 and 2.4 are implemented and independently reviewed against the canonical D009 contracts. Existing Account contacts, response envelopes, membership authorization, subscription write gates and department defaults are preserved.

- Insert-only `PlanCatalogSeeder` and the existing `PlanSeeder` entry point preserve edited catalog rows.
- `platform:initialize` backfills only missing active Básico subscriptions with `chunkById(200)`, preserving existing subscriptions, identities, memberships and activations.
- `EnsureAccountSubscription` runs within onboarding and issuance transactions; injected-failure tests verify rollback without orphan records.
- Focused checks: 59 tests / 431 assertions passed. After correcting the related monitoring-null default test, the affected set passed 24 tests / 129 assertions. Pint and task diff whitespace checks passed.
- The initial complete backend run executed 2,102 tests: 2,097 passed, two failures and three optional external-provider skips. The task-related monitoring-key assertion was corrected and retested. The remaining fiscal deadline test failure (`ManifestacaoCienciaPrazoTest::test_documento_dentro_do_prazo_de_noventa_dias_passa_na_guarda`, expected Pending, actual DeadlineMissed) was independently reproduced on the untouched main baseline and remains unresolved. This is not a passing whole-suite claim.
- Independent task review: spec PASS, quality APPROVED, no findings. PostgreSQL/Redis races, browser acceptance and live-provider acceptance remain unverified by this slice.

Progress in this worktree: 12/56. The original main checkout and older implementation worktree are unchanged. Older contradictory planning targets still require reconciliation before the remaining change can be completed or archived.
