> Reconciled 2026-10-17. D009 and canonical access/session contracts remain authoritative. Retained stronger requirements are pending until evidence-backed completion; no canonical spec is synchronized by this revision.

## Context

The baseline is Nuxt 4/Vue 3/Nuxt UI 4 plus Laravel 13/PHP 8.4, PostgreSQL and Redis. All seven original Admin pages exist, alongside two ERP entries; integrated operational business APIs already exist. Accounts use real membership and request-local context, with session selection plus `X-Account-Id`, trusted ingress, CSRF/origin checks, serialized session ownership and an absolute deadline. Super Admin is a platform role, not a membership bypass.

Existing catalog/subscription provisioning tasks 2.2–2.4 were independently reviewed in `92bfd8f`. Baseline `5dffa2b` has parent-reported full backend evidence of 2,099 passed plus three optional external-provider skips (2,102 total, 11,980 assertions), and frontend 685 passed. Those results are baseline evidence, not execution of the remaining guarantees. Independent PostgreSQL/Redis races, full browser acceptance and final-head suites remain mandatory.

## Goals / Non-Goals

**Goals:** Complete the retained original Account/summary/catalog/certificate/diagnostic/frontend guarantees while preserving current API shapes, contact storage, owner identities, activation, membership, subscription gates, operational cache and unrelated business routes.

**Non-Goals:** Foreign SupportContext persistence/entry/exit/banner, nullable-membership admin elevation, historical foreign-write generation, user CRUD/promotion, payment processing, replacement of existing operational modules, fiscal-service extraction, deployment or real provider credential enrollment. The separate platform certificate vault authorizes platform credentials only, not foreign tenant data.

## Decisions

### Shared frontend boundary and navigation

Use existing `useAccessApi` and private `/api/v1` ingress, shared `frontend/shared/types/admin.ts`, canonical top-level Identity and separate pagination types/adapters. One `pages/admin.vue` shell renders one title/tabs/panel for every original direct route; remove the Account-only wrapper. Existing responsive table/metric/retry components are verified and extended only where gaps exist. Lists use bounded normalized queries, debounce/reset, latest-response protection, out-of-range recovery and retained success on refresh errors; mutations retain field input and prevent duplicate submission. Initial failures never become successful empty/zero data.

Preserve navigation order: Resumo, Contas, Planos, Assinaturas, Planos ERP, Assinaturas ERP, Usuários, Suporte, Serpro. Original seven-page acceptance remains required; do not delete/reorder the two ERP destinations. Exact summary matching, active parent/child, collapsed popover, search and mobile-close behavior remain binding. All Admin guards permit Super Admin without an office or with suspended own office and revalidate privilege on focus without loops. Ordinary switching resets Account-bound client data; it retains the real membership role and never widens tenant destinations.

### Authoritative route, type and authorization matrix

Every path below is under `/api/v1`, through the existing protected ingress/session/no-store contract. Writes retain origin/CSRF validation. Authentication failures are 401, forbidden role is 403, invalid input is 422 and unknown authorized IDs are 404. All routes require Super Admin except the expressly retained metadata read exception. No platform route grants tenant operations.

| Method | Path | Response / input compatibility | Authorization and work |
| --- | --- | --- | --- |
| GET | `/admin/summary` | `{data:{totals:{accounts,plans,subscriptions,users},recent_support_logs}}` | New Super Admin read; four real counts, at most five safe logs timestamp DESC, ID DESC |
| GET | `/admin/accounts` | `{data:PlatformAccount[],meta:{page,per_page,total,last_page}}` | Existing Super Admin read; additive last_page, literal bounded q/status/page; 15 rows, deterministic ID order |
| POST | `/admin/accounts` | `{data,first_access:{url,expires_at}}` | Existing issuance; optional contact fields, same owner validation and transactions |
| GET | `/admin/accounts/{id}` | `{data:PlatformAccount}` | Additive Super Admin safe detail; never private activation/settings |
| PATCH | `/admin/accounts/{id}` | `{data:PlatformAccount}` | Existing name/status plus contacts; atomic shared contact action |
| POST | `/admin/accounts/{id}/first-access` | `{first_access:{url,expires_at}}` | Existing reissue; no invented data wrapper |
| GET/PATCH | `/admin/accounts/{id}/commercial` | Existing `{data:Account}` and safe commercial fields | Preserve separate Super Admin editor and envelope; same contact validation/persistence action |
| GET/POST/GET by ID/PUT | `/admin/plans[/{id}]` | Raw Plan array/list and raw Plan detail/mutation; no data wrapper | Existing Super Admin APIs; preserve existing creation; UI remains edit-only; partial top-level update, immutable slug |
| GET/GET by ID/PUT | `/admin/subscriptions[/{id}]` | Existing resource envelopes; list `meta.current_page` | Super Admin; q/status/page, real plan/status validation |
| GET | `/admin/users` | Resource collection with `meta.current_page` | Existing Super Admin read-only global users/real role links |
| GET | `/admin/support/logs` | Resource collection with `meta.current_page` | Existing Super Admin stored-event reader; canonical ID DESC |
| POST | Existing ordinary Account-switch route | Existing top-level Identity | Active real membership required; no foreign enter/exit route |
| GET/PUT | `/admin/accounts/{id}/contracting-certificate` | Safe configured/document/subject/serial/validity/revision metadata; multipart replacement | New Super Admin-only platform vault; active source required, no download/delete or tenant side effects |
| GET | `/serpro/connection` | Existing `{data:{configured,...}}`, additive certificate_source/contracting_account_id | Super Admin OR member of currently selected active Account; successful unconfigured is 200, 404 is failure |
| PUT | `/serpro/connection` | Existing safe resource, initial/update 200; legacy selector retained, new explicit upload/account selector | Super Admin; atomic rotation, field-error rollback |
| POST | `/serpro/connectivity` | Existing named safe verdicts; 409 connection_changed, local 429 Retry-After | Super Admin; independent six/minute actor AND trusted-IP budgets |
| Existing | `/serpro/account-certificate` | Existing legacy contract | Preserve existing authorization, tenant certificate ownership and existing links |

`PlatformAccount` retains `id,name,status,members_count,pending_owner` and adds safe nullable phone/state/city, boolean phone_whatsapp and nullable `subscription:{id,status,plan:{id,slug,name}}`. Pending owner exposes only name/email/expiry. Eager-load combined relation/projection data; avoid list N+1. List/search remains bounded/literal, not an all-page download. Numeric support search uses the new detail exactly once; names use paginated server search. Summary counts are read-time counts, not a transaction-wide simultaneous snapshot guarantee.

### Authoritative storage and ownership matrix

| Entity | Retained storage and required additive work |
| --- | --- |
| Account contacts | Existing settings.platform_billing_contact; root resource is a projection, no conflicting phone/state/city columns. Shared root/commercial action preserves private activation/unrelated settings and owner identity. Phone <=20, city <=255, nullable valid Brazilian UF normalized uppercase; WhatsApp requires phone. Omission preserves, explicit null clears, cleared phone forces false. |
| Plan | Existing unique immutable slug, name, JSON limits; supplied replacement limits normalize to exactly users/clients/monitorings with null unlimited. Insert-only defaults remain basico 5/50, profissional 20/500, empresarial unlimited and monitorings unlimited. |
| Subscription | Existing unique account_id and restricted plan_id FK; retain Account cascade compatibility. Add safe active/past_due/canceled database constraint and factories with preservation/rollback tests, not duplicate tables. Missing subscription backfill stays bounded and idempotent. |
| SupportAccessLog | Retain stored rows; safe read allowlist and minimal nested resources. No new foreign lifecycle persistence or claimed writers. |
| AccountCertificate | Existing private tenant vault, tenant-admin authorization, term issuance and legacy source compatibility unchanged. |
| AccountContractingCertificate | Separate platform-owned vault, unique restricted Account FK, encrypted/hidden PKCS12/password, safe metadata and monotonic revision; no tenant certificate replacement or IssueSerproTermJob. |
| SerproConnection | Existing singleton; preserving encryption migration for plaintext consumer_key plus hidden serialization. Keep existing tenant-source attachment and contracting_account_id; add explicit dedicated-vault FK, source identity/revisions and coherent snapshots. No blind encrypted cast over historical plaintext. |

The six cross-contract decisions below are authoritative completion requirements. They adapt incompatible earlier wording explicitly rather than waive stronger pending acceptance.

### R1: API shapes and added reads

Do not replace raw Plan `[]` with `{data:[]}`, resource `meta.current_page` with `meta.page`, top-level Identity, create `{data,first_access}` or reissue `{first_access}`. Account `last_page` is additive. Generic Account detail and summary are additive APIs explicitly retained as pending in the accepted decision record. The old canonical statement 'must not depend on absent summary' becomes 'uses implemented summary', not removal of the summary requirement. Keep `/commercial` and settings-backed contact storage; expose the stronger root resource fields as a safe projection. Root and commercial mutations share one validation/persistence action, preserving private activation/unrelated settings.

### R2: Plan input normalization

Strict validation and deterministic unlimited clearing survive. Existing empty/sparse limits objects are legitimate complete replacements: normalize missing known keys to null. Stored/editor output has exactly `users,clients,monitorings`; unknown keys rejected; values must be null or nonnegative integral values, not coercible floats. A supplied name is 2–255 characters. Partial top-level updates remain; slug edits rejected. Record this input-compatibility adaptation explicitly in the delta before implementing; it is not D009 retirement and does not waive validation.

### R3: Two distinct certificate ownership domains

`AccountCertificate` belongs to tenant operations with tenant-admin permissions and term issuance side effects. The original `AccountContractingCertificate` is a platform credential vault named by an Account, not a tenant-data authorization bypass. Implement the planned separate vault (unique account_id, restricted FK, encrypted/hidden PKCS12/password, safe metadata, revision). Platform source metadata/upload routes operate solely on this vault and have no term/sync side effects.

Keep existing `/serpro/account-certificate` and `use_account_certificate=true` behavior for current clients/links; do not migrate private tenant certificates into the platform vault automatically. Add an explicit new-source FK such as `account_contracting_certificate_id` on SerproConnection. Existing `contracting_account_id` continues representing the chosen office and legacy attachment; internal provenance distinguishes legacy tenant reuse from new platform vault. Dedicated upload clears both attachment FKs. New `certificate_source=account` resolves only the dedicated platform vault, with `contracting_account_id` required. New `certificate_source=upload` uses saved/new dedicated upload. Reject simultaneous legacy/new selector ambiguity. Existing unchanged legacy connections remain usable and display a safe legacy-source hint; a new explicit Account choice requires a platform vault certificate. The new resource adds certificate_source and contracting_account_id without exposing any credential material.

Consumer key is currently plaintext and not hidden on SerproConnection. The stronger requirement explicitly covers the key too: encrypt it and hide it from generic serialization with a preserving data migration, while retaining the resource's masked hint and internal existing reads. Never blindly add an encrypted cast over plaintext historical values. No destructive resets or bulk replacement of certificates.

### R4: Rotation and diagnostic isolation

Use a single explicit credential lifecycle coordinator. For new platform vault operations lock Account first (existing tenant resolver uses Account-first), then a fixed PostgreSQL advisory transaction lock for the platform credential lifecycle, then connection/source rows; session lock remains the outer HTTP lock. Operations needing no Account may take only the credential lock and connection, and must never later request an Account lock. If old/new source Accounts must both be locked, discover IDs, lock ascending, acquire credential lock and recheck source identity; retry if the discovery changed. Every path touching both domains follows this order; tests include tenant certificate replacement while diagnostic resolves legacy source.

Read one immutable snapshot of encrypted values/source identity/revisions under locks, release all DB locks before provider HTTP, and compare identity/revision/status immediately before verdict. No independent certificate/password queries after releasing snapshot locks. A changed source, removed source, suspension or rotated credentials yields 409 `connection_changed`; current unusable source fails locally as certificado. Revisions advance on actual credential/source mutation. An attached platform certificate replacement updates metadata and connection revision in the same transaction. Existing tenant source changes require equivalent generation detection (current certificate row ID plus lifecycle timestamps/version), without granting new tenant write permission.

Use a diagnostic method separate from operational `pair()`/`verify()` semantics: fresh auth with saved coherent snapshot, 3s/8s budgets, TLS verification, no redirects, Basic key/secret, form grant_type=client_credentials, Role-Type=TERCEIROS, both token fields required. Discard diagnostic tokens; do not remove or overwrite a usable operational token cache. Credential changes still invalidate/version operational cache so an in-flight old auth cannot repopulate the new credential generation. Diagnostic result endpoint calls no fiscal/monitoring/job/term paths.

### R5: Audit secrecy survives foreign-support retirement

Foreign lifecycle writes are retired; secrecy of stored log reads is not. Existing key-name blacklist cannot guarantee that an arbitrary benign field does not contain a token. Restrict metadata to explicit safe descriptors, initially `resource` (known short identifier), `resource_id` (positive integer), and any additional inventoried scalar action descriptors justified by current writers; drop all arbitrary nested payloads. Project nested actor as id/name/email and Account as id/name/status (retain only demonstrably safe compatibility fields if required), never tenant settings/certificates/activation data. Keep stored rows untouched. List order remains canonical descending ID; summary's five rows follow accepted timestamp DESC, ID DESC. Record the distinction explicitly rather than silently change legacy pagination ordering.

### R6: Timeout/request scopes

The historical design's `/serpro/*` wildcard predates operational monitoring. Limit 15s to connectivity and certificate/configuration writes; keep operational long-route budgets. Access auth/identity/switch/owner/ordinary Admin paths get 10s unless a specifically bounded certificate endpoint. Provider diagnostics use 3s connect/8s total under 30s session ownership. Total applicable multipart request is at most 4MiB; certificate at most 2MiB; overflow 413 even when PHP rejects before validation. Test real ingress preservation of binary data and trusted headers, not an HTTP fake claiming proxy acceptance.

### Verification and implementation sequencing

Freeze the reconciled contracts and shared types first, then schema/contact/catalog/summary/audit, platform vault/lifecycle, diagnostic/ingress, and frontend completion. Existing verified tasks are reused, rerun when affected and at the final gate. New behavior gets meaningful failing cases; existing passing coverage is not relabeled a historical red test. No new implemented task is checked by this reconciliation.

Use independent PHP processes with real isolated PostgreSQL/Redis for provisioning/backfill, first-write singleton, source/credential rotation, legacy tenant certificate replacement versus diagnostic snapshot, membership/suspension and session-lock ownership races. Release DB locks for provider HTTP; deterministic fake provider synchronization proves the revision fence. SQLite or sync-run tests do not establish these guarantees. Browser verification covers all seven original direct routes at 1366×639 and mobile, expanded/collapsed/search/tabs, ordinary-user denial, no-office Admin, real member switching, foreign denial, focus reset, forms/errors/empty states and actual binary multipart through Nuxt+Laravel. Keep both ERP destinations intact.

Run isolated backend `php artisan test --compact` and `vendor/bin/phpunit -c phpunit.pgsql.xml`, affected focused tests, frontend `pnpm --dir frontend lint`, `typecheck`, `test`, `build`, changed-PHP `vendor/bin/pint --dirty --format agent`, OpenSpec strict validation, whole-change spec/quality review and final traceability audit. Deterministic provider fakes are accepted; real authorized SERPRO tests remain explicit optional live evidence, never inferred from mocks or local parsing. During implementation verify the official [SERPRO authentication guide](https://apicenter.estaleiro.serpro.gov.br/documentacao/api-integra-contador/pt/quick_start/) and installed HTTP/OpenSSL capabilities; this planning revision makes no fresh web-verification claim.

## Risks / Trade-offs

- Legacy API variation is retained deliberately; typed adapters avoid a breaking uniform envelope rewrite.
- Two certificate domains require explicit source provenance and coherent versions. A dedicated platform upload must never mutate tenant vaults or dispatch term/sync jobs.
- DB and Redis/session ownership are separate commit domains. Existing fencing remains mandatory; no foreign support persistence is added as an attempted bridge.
- Encrypting consumer_key requires a reversible preserving data migration and negative serialization assertions; old plaintext cannot be fed into a newly encrypted cast blindly.
- Timeout changes can break existing fiscal/export/bulk work. Bound diagnostics/configuration specifically and preserve longer operational budgets.
- Historical audit blacklists are insufficient for arbitrary nested values; safe readers must use explicit descriptor allowlists without rewriting stored history.

## Migration Plan

1. Inventory current dirty work, runtime and versions. Preserve source data; prepare migration/rollback tests and relevant regression coverage.
2. Add status constraints/factories, separate encrypted platform vault, explicit source FK/revisions and preserving key encryption. Do not duplicate contacts, change subscription Account cascade or copy private tenant certificates.
3. Reuse reviewed insert-only initialization and bounded missing-subscription backfill. Verify repeat runs and transactional provisioning with existing identities/activations untouched.
4. Configure nonsecret auth URL/timeouts/private temporary storage. Users enter authorized platform secrets at runtime; never seed legacy provider credentials.
5. Enable additive APIs/lifecycle/diagnostic behavior, then UI, with process and browser gates before completion. Deployment remains separate from local completion.
6. Roll back routes/UI before schema if needed. Preserve encrypted credentials, audit rows and additive vault data; document how to reverse preserving key encryption under the compatible code version. Do not reset data or rotate the application key to roll back. Foreign support contexts do not exist in this design.

## Open Questions

None affecting scope or task breakdown. Runtime-specific certificate compatibility is tested during implementation. Live provider acceptance requires explicitly authorized credentials; optional skips stay visible. This change remains active until all 46 valid tasks have evidence and independent review, alongside the ten retired originals.
