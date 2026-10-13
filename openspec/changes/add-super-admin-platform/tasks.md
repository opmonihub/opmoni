## 1. Contract baseline and regression coverage

- [ ] 1.1 Inspect applicable rules, installed Nuxt/UI/Laravel/HTTP-client versions, runtime OpenSSL/cURL support, and existing dirty changes; record the implementation baseline and verify no unrelated files are overwritten.
- [ ] 1.2 Define `frontend/shared/types/admin.ts` and additive support/Serpro identity types from the design route matrix, keeping existing issuance and identity envelopes; verify type fixtures for all seven pages and existing `tests-access.test.ts` pass.
- [ ] 1.3 Add backend contract/authorization tests for the new platform route family, including unauthenticated 401, non-Super Admin 403, no active office, CSRF/origin rejection, pagination shape, field validation, and non-cacheable output; verify the tests fail for missing implementations before proceeding.
- [ ] 1.4 Add regression assertions for owner-link issuance/reissue, exact existing identity/credential preservation, default-office login, suspension, session expiry, and no fake membership; verify the existing access and tenancy suites pass before schema/context changes.

## 2. Catalog, subscriptions, and additive Account schema

- [ ] 2.1 Generate additive Account contact, Plan, and Subscription migrations/models/factories with unique slug/Account subscription, FK restrictions, and valid status constraints; verify migration tests enforce constraints and preserve existing Account/member/activation data.
- [ ] 2.2 Implement insert-only `PlanCatalogSeeder` with the documented Básico/Profissional/Empresarial limits; verify repeat seeding preserves edited names/limits and creates only missing defaults.
- [ ] 2.3 Implement repeatable `platform:initialize` subscription backfill with bounded chunks; verify two runs create one active Básico subscription per missing Account without resetting existing subscriptions or creating users/Accounts.
- [ ] 2.4 Implement `EnsureAccountSubscription` inside existing onboarding and Account-issuance transactions, including safe lazy catalog initialization; verify fresh installation succeeds without prior seed execution and injected provisioning failure leaves no orphan Account/subscription/activation.

## 3. Platform Account backend

- [ ] 3.1 Add a consistent extended Account Resource and GET `/api/v1/admin/accounts/{id}`, preserving existing row fields and activation secrecy; verify list/detail envelopes, nested plan data, unknown-ID 404, and omission of hashes/URLs/secrets.
- [ ] 3.2 Extend creation/PATCH validation and actions for phone/WhatsApp/UF/city with null-clearing and omission semantics; verify clearing phone resets WhatsApp, invalid UF/flag combinations return field errors, and multi-field updates are atomic.
- [ ] 3.3 Preserve existing create and first-access reissue paths/route names/outputs while incorporating subscription/contact data; verify email conflicts, active zero-member reissue, seven-day expiry, prior-link invalidation, and temporary-copy-only behavior through existing access tests.
- [ ] 3.4 Extend Account list pagination with `last_page`, literal name/ID search, bounded query validation, and eager-loaded plan/member/owner metadata; verify filters, deterministic ordering, invalid pages/search, and response compatibility.

## 4. Plans and subscriptions backend

- [ ] 4.1 Implement Super Admin GET/PUT Plan APIs with immutable slug and complete nullable limits replacement; verify name bounds, negative/fractional/extra-key rejection, finite-to-unlimited clearing, and unchanged values after failed validation.
- [ ] 4.2 Implement paginated Subscription GET and atomic PUT with real plan validation, Account/plan search, and status filtering; verify all status labels/contracts, invalid plan 422, unknown subscription 404, and page metadata.
- [ ] 4.3 Keep commercial status independent from operational Account suspension and quotas; verify canceled/past_due subscriptions do not change membership, activation eligibility, or tenant access for an active Account.

## 5. Support persistence and session transitions

- [ ] 5.1 Generate SupportContext and SupportAccessLog migrations/models/factories with lifecycle UUIDs, actor/Account references, safe metadata, and query indexes; verify constraints and that audit references cannot be cascade-erased.
- [ ] 5.2 Implement transactional support entry with active-target/privilege/membership checks, serialized session ownership, deterministic Account locking, and persisted open-context reconciliation; verify foreign entry, own-membership switching, same-target idempotency, target change exit/enter, no membership creation, and unchanged deadline.
- [ ] 5.3 Implement idempotent support exit restoring prior active membership/default/null; verify successful exit with no own Account, no-op exit without support, suspended prior Account fallback, rejection of forged/closed support privilege, and rollback when audit insertion fails.
- [ ] 5.4 Reconcile support in `/me` and clear it on new login, logout, ordinary switching, suspension, or privilege loss; verify invalid context never produces effective admin, logout remains revocation-safe, and a new login cannot resume support.
- [ ] 5.5 Extend IdentityResource with real-membership `is_member`, a support current-Account projection, and `support` metadata while keeping the top-level envelope; verify foreign Account remains absent from `accounts` and membership/default-office outputs remain compatible.

## 6. Support-aware tenant isolation and audit

- [ ] 6.1 Extend CurrentAccount and ResolveAccount with explicit effective role/nullable real membership, actor, and support state; inventory existing membership dereferences and verify own-member roles, support admin role, missing-context failure, suspended target denial, body Account rejection, and stale `X-Account-Id` 409.
- [ ] 6.2 Add registered support-write resource/action descriptors and transactional allowlisted audit integration inside the tenant transaction; verify test-only tenant create/update/delete audit, reads without write events, unsupported-write denial, and both mutation/audit rollback on injected failure.
- [ ] 6.3 Add GET `/api/v1/admin/support/logs` with safe resources, Account-ID validation, deterministic newest ordering, and pagination; verify actor/Account metadata and that passwords/tokens/containers/session identifiers never appear.
- [ ] 6.4 Add independent-process PostgreSQL/Redis support integration cases for duplicate entry, concurrent target change/exit, suspension versus write, expired session lock ownership, and failed Redis/session persistence reconciliation; verify no successful unaudited access or cross-Account write occurs.

## 7. Global users and summary backend

- [ ] 7.1 Implement read-only GET `/api/v1/admin/users` with `page,q,type`, eager-loaded real Account-role links, and allowlisted identity fields; verify multi-membership badges data, global type filtering, literal search, and no user mutation endpoints or password/session disclosures.
- [ ] 7.2 Implement GET `/api/v1/admin/summary` with real four-entity totals and five safe recent support events; verify populated/empty counts, newest ordering, audit shape reuse, and Super Admin access without tenant selection.

## 8. Serpro certificate and connection lifecycle

- [ ] 8.1 Generate AccountContractingCertificate and singleton SerproConnection migrations/models/factories with encrypted/hidden material, source FKs, safe metadata, and revisions; verify encryption at rest, singleton/source constraints, and no raw secret fields in model serialization.
- [ ] 8.2 Implement real PKCS#12 validation for 2 MiB `.pfx/.p12`, supplied empty/missing password distinctions, key/certificate match, validity, and unambiguous supported CNPJ; verify generated real UploadedFile cases including wrong password, malformed, oversized, expired, not-yet-valid, conflicting identity, and runtime compatibility failures.
- [ ] 8.3 Implement Super Admin Account contracting-certificate metadata/PUT APIs with active-source checks and no download/delete routes; verify safe responses, non-first Account support, unauthorized/suspended source rejection, and atomic attached-connection metadata/revision updates on replacement.
- [ ] 8.4 Implement safe connection GET with explicit `configured=false` and atomic initial/partial/source-switch PUT; verify all initial requirements, omitted/blank key/secret preservation, failed replacement rollback, certificate-source validation, and no duplicate singleton under competing writes.
- [ ] 8.5 Implement private certificate materialization with restrictive permissions and guaranteed cleanup; verify success, HTTP failure, parse/decrypt failure, and exception paths leave no temporary material or sensitive output.
- [ ] 8.6 Add credential/source-rotation PostgreSQL concurrency cases with fixed lock ordering; verify coherent final source/metadata/revision, singleton uniqueness, no mixed old/new credential snapshot, and connectivity 409 `connection_changed` when rotation occurs during HTTP.

## 9. Serpro connectivity and ingress

- [ ] 9.1 Implement server-controlled mutual-TLS authentication per the linked official Serpro guide using installed-client-compatible options, Basic credentials, form body, no redirects, and TLS verification; verify HTTP fakes assert required auth request and require both token fields without exposing or caching them.
- [ ] 9.2 Implement named connectivity verdicts for unconfigured/certificate/credential/provider failures plus six/min actor and trusted-IP budgets; verify no network call for local failure, upstream 429/5xx/timeout classification, application 429 Retry-After, and no fiscal/service/sync calls.
- [ ] 9.3 Extend the frontend API transport for FormData POST with `_method=PUT` and proxy binary forwarding while preserving all origin/CSRF/cookie/ingress protections; verify real file-byte equality and forbidden-header behavior through frontend ingress and Laravel.
- [ ] 9.4 Enforce 2 MiB file/4 MiB request limits and align provider connect/total timeouts (3s/8s), selected proxy timeout (15s), and existing session-lock fencing; verify 413, timed provider failure, ownership loss rejection, and unchanged 10s behavior for unrelated access routes.
- [ ] 9.5 Add non-secret `.env.example` configuration/runtime prerequisites for authentication URL, timeouts, private temp storage, and PHP OpenSSL/cURL support; verify container capability checks and that no real credentials or provider payloads enter tracked files/logs.

## 10. Shared Admin frontend

- [ ] 10.1 Port compatible table panel/tokens, column menu/SSR-safe media query, empty state, row action menu, metric cards, and retry handling from the mapped source inventory; verify responsive component rendering and lint/typecheck without importing unrelated legacy modules.
- [ ] 10.2 Add tested adminNav route helpers and the seven-page sidebar/search catalog; verify exact summary matching, active child/parent, Super Admin-only destinations, collapsed popover, and mobile-close callbacks.
- [ ] 10.3 Add the shared `pages/admin.vue` shell and remove the Account-only panel wrapper; verify every direct Admin route displays one panel/navbar with matching title/tabs.
- [ ] 10.4 Generalize route/focus guards and accessDestination to administrative routes and explicit support identity; verify ordinary-user denial, Super Admin with no office, suspension/privilege refresh, and no redirect loops through existing/new frontend access tests.
- [ ] 10.5 Add reusable typed list-query/pagination/stale-response handling and adapt the frontend test command to include the new test files; verify debounce/filter reset, latest-response retention, invalid/out-of-range page recovery, and initial-error versus stale-success states.

## 11. Seven pages connected to backend APIs

- [ ] 11.1 Implement Resumo using the real summary endpoint, linked metric cards, five-event audit table, column menu, retry/empty states, and refresh preservation; verify API failure never renders false zero metrics.
- [ ] 11.2 Adapt Contas to the shared table with plan/member/contact data, create/edit/reissue/status-confirmation modals, and safe activation copying; verify current first-access behavior and all field/loading/clipboard states against the working Account APIs.
- [ ] 11.3 Implement Planos local search/table/column controls and edit-limits slideover; verify finite-to-null serialization survives save/reload and no creation/deletion action is introduced.
- [ ] 11.4 Implement Assinaturas filters/pagination/status badges and plan/status edit slideover; verify field errors preserve inputs, plan choices are real, catalog failures offer retry, and status edits leave Account access unchanged.
- [ ] 11.5 Implement Usuários with name/email/type filtering, real Account-role badges, column controls, pagination/count, and no write actions; verify empty, multi-membership, and unauthorized states.
- [ ] 11.6 Implement Suporte Entrar/Auditoria tabs using server-side Account search/detail and real audit pagination; verify consistent detail envelopes, suspended entry feedback, positive Account filtering, idempotent entry, and no full-platform client-side scan.
- [ ] 11.7 Integrate enter/exit/member-switch auth actions and persistent SupportBanner; verify full client tenant-state reset, correct restored navigation, no-own-membership exit to Admin, logout, and cross-tab focus reconciliation.
- [ ] 11.8 Implement Serpro metadata/source selector, explicit Account source search/inline certificate upload, secret-safe rotation form, and connectivity card; verify both certificate sources, real multipart save, cleared secret fields/results, retry, named failures, and duplicate-submit prevention.

## 12. Complete-change verification and handoff

- [ ] 12.1 Run independent PostgreSQL/Redis provisioning/backfill races in addition to support and credential races; verify duplicate prevention and preservation of existing identities/activations, with actual process-level evidence recorded separately from feature tests.
- [ ] 12.2 Run backend feature/regression suites and PostgreSQL/Redis integration suite in isolated test resources (`php artisan test --compact` and `vendor/bin/phpunit -c phpunit.pgsql.xml` in the backend environment); verify passing output without touching development data.
- [ ] 12.3 Run `pnpm --dir frontend lint`, `typecheck`, `test`, and `build`, plus `vendor/bin/pint --dirty --format agent` for changed PHP; verify final command output and fix relevant failures before marking tasks complete.
- [ ] 12.4 Exercise seven direct routes in the browser at 1366×639 and a mobile viewport, expanded/collapsed/sidebar/tab/search navigation, ordinary-user denial, no-office Super Admin, every form/error/empty state, support entry/exit/focus, and real file upload; verify screenshots/observations against the legacy inventory and current activation contracts.
- [ ] 12.5 Review the whole change against all five specs, API/types compatibility, dirty-file preservation, secret redaction, transaction/session failure paths, and migration rollback plan; resolve actionable findings and verify required checks again only when fixes justify it.
- [ ] 12.6 Update architecture/API/runtime instructions and product decision records with the implemented scope, idempotent initialization command, supported Admin behavior, and known verification limits; verify no unsupported operational/billing functionality is claimed and only completed evidence-backed tasks are checked off.
