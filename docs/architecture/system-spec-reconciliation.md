# System specification reconciliation

Reconciled on 2026-10-13 from the current working tree, accepted product decisions, registered routes, page sources, and current test runs. This is a system-wide contract map and documentation audit, not a claim that every provider or deployment behavior has passed acceptance.

## Authority and scope

- [Product decisions](product-decisions.md) govern accepted scope. D009 activates Clients, Fiscal, Monitoring, Work, Team, Settings, and platform administration while preserving real membership and existing access contracts.
- [Reconciliation proposal](../../openspec/changes/archive/2026-10-13-reconcile-system-specs/proposal.md), [design](../../openspec/changes/archive/2026-10-13-reconcile-system-specs/design.md), and [tasks](../../openspec/changes/archive/2026-10-13-reconcile-system-specs/tasks.md) contain the reviewable delta history, archived on 2026-10-13 after completion and synchronization.
- [Canonical specs](../../openspec/specs/) describe observable contracts after synchronization. Stronger accepted guarantees are not weakened merely because runtime verification is incomplete.
- The archived [platform change](../../openspec/changes/archive/2026-10-20-add-super-admin-platform/proposal.md) has a reconciled 56-item disposition: 46 valid items and ten retired items. All 46 valid checklist tasks have local acceptance evidence; ten originals are retired, not implemented. The 2026-10-13 comparison below is historical; use the dated current-status section for today's evidence. Foreign support is superseded by D009.
- Historical archived access artifacts remain unchanged. Source implementation, dependencies, credentials, development stores, and existing work are outside this documentation change.

## Decision reconciliation

| Decision | Current disposition | Implementation/spec consequence |
| --- | --- | --- |
| D001 | Fiscal capture purpose remains accepted; separate NFePHP delivery remains pending | NF-e/CT-e integrations currently live inside Laravel; fiscal-documents covers current capture rather than claiming the planned service exists |
| D002 | Approved extraction layout remains a future target | No services/fiscal directory/container exists; current private storage/runtime boundaries are documented separately |
| D003 | Historical incremental-review policy | D007 authorized direct access implementation and D009 explicitly broadened integration scope |
| D004 | Business deferrals superseded by D009 | Clients, Fiscal, Monitoring, Work, Team, WhatsApp, and platform commercial surfaces now have deltas |
| D005 | Superseded by D006 | Nuxt does not own application sessions/data; private proxy delegates to Laravel |
| D006 | Implemented application boundary; initialization-only wording is historical | Nuxt frontend/Laravel backend, versioned API, PostgreSQL and Redis |
| D007 | Implemented access authorization; card-selection rule superseded by D008 | Keep fixed session deadline, owner activation and identity preservation |
| D008 | Current login/selection contract | Earliest active membership is default; optional session-local switching |
| D009 | Current integration/scope and tenant-role authority | Real membership even for Super Admin; no foreign support elevation; explicit Account context for background operations |

## Capability and evidence map

Source and test links identify where behavior was inspected; a test link by itself is not proof of current passing output.

| Capability | Covered behavior | Implementation evidence | Existing verification |
| --- | --- | --- | --- |
| [auth](../../openspec/specs/auth/spec.md) | Bootstrap, login, identity, logout, activation, CSRF and sessions | [AccessController.php](../../backend/app/Http/Controllers/Api/V1/AccessController.php) | [AccessFoundationTest.php](../../backend/tests/Feature/AccessFoundationTest.php) |
| [accounts](../../openspec/specs/accounts/spec.md) | Real membership, selection, provisioning and additive defaults | [AccessAccounts.php](../../backend/app/Actions/AccessAccounts.php) | [BusinessIntegrationTest.php](../../backend/tests/Feature/BusinessIntegrationTest.php) |
| [isolation](../../openspec/specs/isolation/spec.md) | Request context, roles, Account assertion, write subscription gate | [ResolveAccount.php](../../backend/app/Http/Middleware/ResolveAccount.php) | [BusinessAccessBoundaryTest.php](../../backend/tests/Feature/BusinessAccessBoundaryTest.php) |
| [access-pages](../../openspec/specs/access-pages/spec.md) | Access screens, business navigation, demo distinction and SSR | [auth.ts](../../frontend/app/middleware/auth.ts) | [tests-access.test.ts](../../frontend/tests-access.test.ts) |
| [client-portfolio](../../openspec/specs/client-portfolio/spec.md) | CRUD, filters, summary/analytics, tags, selections and CNPJ lookup | [business.php](../../backend/routes/business.php) | [ClientListingTest.php](../../backend/tests/Feature/Tenancy/ClientListingTest.php) |
| [client-certificates](../../openspec/specs/client-certificates/spec.md) | A1 inspection, encrypted vault, replacement and cleanup | [ClientCertificateVault.php](../../backend/app/Services/ClientCertificateVault.php) | [ClientCertificateTransactionTest.php](../../backend/tests/Feature/ClientCertificateTransactionTest.php) |
| [fiscal-documents](../../openspec/specs/fiscal-documents/spec.md) | Coverage, documents/XML, capture, gaps, recovery and source gates | [FiscalDocumentController.php](../../backend/app/Http/Controllers/Api/V1/Tenant/FiscalDocumentController.php) | [FiscalDocumentApiTest.php](../../backend/tests/Feature/Fiscal/FiscalDocumentApiTest.php) |
| [monitoring-serpro](../../openspec/specs/monitoring-serpro/spec.md) | Office certificate/term, monitoring, runs, searches and schedules | [business.php](../../backend/routes/business.php) | [SerproSyncJobsTest.php](../../backend/tests/Feature/SerproSyncJobsTest.php) |
| [work-management](../../openspec/specs/work-management/spec.md) | Templates, generation, processes, tasks, assignees and calendar | [ProcessGenerationService.php](../../backend/app/Services/ProcessGenerationService.php) | [WorkIsolationTest.php](../../backend/tests/Feature/Tenancy/WorkIsolationTest.php) |
| [team-management](../../openspec/specs/team-management/spec.md) | Member administration, last admin, directory and departments | [AccountMemberController.php](../../backend/app/Http/Controllers/Api/V1/Tenant/AccountMemberController.php) | [MemberDirectoryTest.php](../../backend/tests/Feature/Tenancy/MemberDirectoryTest.php) |
| [whatsapp-delivery](../../openspec/specs/whatsapp-delivery/spec.md) | Channel, contacts, explicit PGDAS send, receipts and webhook | [WzapWebhookController.php](../../backend/app/Http/Controllers/WzapWebhookController.php) | [whatsappSendGuides.test.ts](../../frontend/tests/whatsappSendGuides.test.ts) |
| [application-runtime](../../openspec/specs/application-runtime/spec.md) | Private proxy, JSON/multipart/binary, workers, schedules and stores | [API proxy](../../frontend/server/api/v1/[...path].ts) | [AccessConcurrencyTest.php](../../backend/tests/Integration/AccessConcurrencyTest.php) |
| [erp-company-foundation](../../openspec/specs/erp-company-foundation/spec.md) | Company tenant, session selection, delegation, ERP shell and operating-write gate | [erp.php](../../backend/routes/erp.php), [erp.vue](../../frontend/app/layouts/erp.vue) | [ErpCompanyFoundationTest.php](../../backend/tests/Feature/ErpCompanyFoundationTest.php), [ErpCompanyConcurrencyTest.php](../../backend/tests/Integration/ErpCompanyConcurrencyTest.php) |
| [platform-administration](../../openspec/specs/platform-administration/spec.md) | Seven pages, summary composition, global read-only users | [index.vue](../../frontend/app/pages/admin/index.vue) | [adminNav.test.ts](../../frontend/tests/adminNav.test.ts) |
| [platform-account-management](../../openspec/specs/platform-account-management/spec.md) | Access issuance plus separate commercial editor | [AccountCommercialController.php](../../backend/app/Http/Controllers/Api/V1/Admin/AccountCommercialController.php) | [BusinessIntegrationTest.php](../../backend/tests/Feature/BusinessIntegrationTest.php) |
| [platform-commercial-management](../../openspec/specs/platform-commercial-management/spec.md) | Catalog, subscriptions, operational gate and creation limits | [PlanLimits.php](../../backend/app/Services/PlanLimits.php) | [SecurityRefactorTest.php](../../backend/tests/Feature/Tenancy/SecurityRefactorTest.php) |
| [platform-support-access](../../openspec/specs/platform-support-access/spec.md) | Membership-only entry and existing audit browsing | [suporte.vue](../../frontend/app/pages/admin/suporte.vue) | [BusinessAccessBoundaryTest.php](../../backend/tests/Feature/BusinessAccessBoundaryTest.php) |
| [platform-serpro-connection](../../openspec/specs/platform-serpro-connection/spec.md) | Singleton, certificate sources, rotation and connectivity | [SerproConnectionManager.php](../../backend/app/Services/SerproConnectionManager.php) | [SerproConnectionApiTest.php](../../backend/tests/Feature/SerproConnectionApiTest.php) |

## Differences from the earlier Super Admin target

The following comparison preserves the 2026-10-13 reconciliation baseline. Its observations are dated evidence, not current behavior or current gate results. The 2026-10-18 implementation column records the code and API shape; acceptance status is in the next section and the archived [change evidence](../../openspec/changes/archive/2026-10-20-add-super-admin-platform/tasks.md#current-evidence-reconciliation--2026-10-17).

### Platform implementation update — 2026-10-18

- `GET /api/v1/admin/summary` returns aggregate metrics and at most five allowlisted recent audit rows. Account list/detail expose a bounded contact/subscription/pending-owner projection; the commercial endpoint and settings-backed contact storage remain compatible.
- `platform:initialize` inserts missing catalog slugs and backfills only missing subscriptions in bounded chunks. Shared provisioning is transactional with issuance and preserves edited catalogs, statuses and identities.
- Plan updates accept only `name` and the three nullable nonnegative integer limits; a supplied sparse limits object nulls omitted known keys. The API retains a plan-create endpoint, while the Admin page exposes editing only. Subscription statuses retain the tenant-write gate; foreign support elevation remains superseded by D009.
- The SERPRO UI explicitly chooses connection upload or an active Account's dedicated platform contracting vault. The platform vault has a safe metadata/revision resource and is separate from the tenant vault; both store certificate material encrypted. Legacy sources remain usable until explicitly replaced. Lifecycle snapshots capture encrypted values, source identity and revisions coherently; Account-first write locks plus a joined MVCC read avoid taking a global credential lock inside an outer tenant transaction. Database locks are released before provider HTTP; revision/source changes fence stale results.
- Authentication diagnostics have separate actor/trusted-ingress-IP budgets and bounded transport. Uploads use POST with `_method=PUT`, accept certificate files up to 2 MiB and multipart requests up to 4 MiB, and return 413 at ingress. Diagnostic defaults are 3-second connect, 8-second provider and 15-second proxy deadlines; ordinary Admin/access proxy routes use 10 seconds while longer operational routes retain their budgets. Process evidence uses real PostgreSQL/Redis and fake provider transport; it is not live SERPRO acceptance.

These implementation facts supersede the absent-feature statements in the historical table, not its dated audit evidence. The change still reports 56 original items as 46 valid and ten retired. As of the 2026-10-18 ledger snapshot, 46/46 valid tasks are checked; no executable checklist task remains open. The final complete backend/PostgreSQL suite and frontend checks passed; full browser acceptance passed; final independent source review is Spec PASS / Quality APPROVED with no findings. Canonical sync and archive have not occurred. See [operational initialization and rollback](../../README.md#platform-administration-and-initialization).

| Earlier target | Observation recorded on 2026-10-13 | Current implementation recorded on 2026-10-18 |
| --- | --- | --- |
| Foreign support enter/exit, effective admin role and banner | Membership-only Account switching; no foreign support projection | Superseded by D009; ordinary switching still requires actual membership |
| Audited support mutation lifecycle | Existing log reader; no ordinary membership enter/exit lifecycle | Existing rows are read through a safe projection; no foreign support lifecycle is claimed |
| Dedicated `GET /admin/summary` | Frontend composed Account/user/subscription/plan/log APIs | `GET /api/v1/admin/summary` returns counts and up to five sanitized audit rows |
| Generic Account detail and root contact fields | Separate `/commercial` route and settings-backed contact | Account list/detail return a bounded contact/subscription/pending-owner projection; `/commercial` remains compatible |
| Uniform pagination and data-wrapped plan list | Account `meta.page`, resource `meta.current_page`, raw Plan array | Those distinct envelopes remain the contract; Account list also returns `meta.last_page` |
| Commercial metadata without operational effect | Inactive/missing subscription blocked unsafe methods; PlanLimits enforced users/clients creation | The tenant write gate and creation limits remain active under D009 |
| Strict nullable Plan limit updates | Older controller/UI validation differed | Plan updates allow only the known keys and normalize omitted supplied limits to null; a separate API create route remains |
| Insert-only catalog initialization | Older observer/migration/seeder could not guarantee non-destructive full setup | `platform:initialize` inserts missing catalog slugs and only missing Account subscriptions in bounded chunks |
| Arbitrary contracting Account selection and platform vault | Reuse selected the earliest Account; tenant certificate API was separate | Admin can select an active Account with a separate platform vault certificate; no public download/delete endpoint exists |
| Safe source metadata and revision fencing | Older connection resource omitted source metadata | Current source and legacy-source hint are safe; lifecycle revisions fence rotation and stale diagnostics |
| Bounded diagnostic and upload ingress | Older route throttle/runtime budgets were not the reconciled target | Actor/IP budgets, 3s/8s provider limits, 15s diagnostic/write proxy limits, 10s ordinary Admin/access limits, 2 MiB file and 4 MiB multipart limits are implemented |
| Whole-change final acceptance | Checklist evidence was incomplete | 46/46 valid tasks are checked; local browser acceptance and documentation reconciliation are complete; source review is approved |

## Known limits and implementation follow-ups

1. **Fiscal extraction:** D001/D002's services/fiscal microservice and NFePHP package layout are not implemented. Laravel currently owns capture, reconciliation, manifestation, XML storage, and jobs.
2. **Private persistence:** disks are private, Account-separated paths under backend/storage. Local Compose preserves them through a source bind mount; no dedicated certificate/XML volume or deployed persistence policy was proven here. Keep the persistence contract and verify deployment separately.
3. **Fiscal rollout:** CT-e manual and scheduled flags are separate; the scheduled command does not universally inherit the manual gate. NFS-e and manifestation retain their own opt-in gates. Do not treat fake contracts or a single-client canary as fleet-wide production acceptance.
4. **Platform acceptance:** the 46 valid tasks have local acceptance evidence, including the complete route/browser matrix and documentation reconciliation. Ten original tasks remain retired by D009. Production rollout, canonical sync and archive remain separate actions.
5. **Provider acceptance:** optional local SERPRO probes are not live provider evidence. No live SERPRO acceptance is claimed.
6. **Demonstration surfaces:** home widgets, inbox, and remaining template settings/sample handlers are not complete business features. They remain separately identified in the frontend inventory below.
7. **Verification environment:** the development backend container mounts only backend at /app and sets fiscal homologation. Tests that inspect repository siblings/Compose or expect production defaults need the repository layout and explicit FISCAL_ENVIRONMENT=producao. The initial direct container run failed for these reasons; its affected test group was rerun successfully without source edits. This is environment history, not current platform-suite evidence.

## Historical verification — 2026-10-13 audit

| Check | Evidence recorded on 2026-10-13 |
| --- | --- |
| Frontend regular tests | 598 passed, zero failures/skips using pnpm test |
| Backend initial container run | 1,672 tests, 9,131 assertions; 5 missing-sibling/Compose errors, 7 fiscal environment expectation failures, 3 optional live probes skipped |
| Affected backend group in repository layout | 166 passed, 884 assertions with FISCAL_ENVIRONMENT=producao |
| Complete backend rerun in repository layout | 1,672 total: 1,669 passed, 9,206 assertions, zero failures/errors, 3 optional external probes skipped; repository-layout execution with FISCAL_ENVIRONMENT=producao |
| OpenSpec strict validation, route coverage, links, idempotence, source preservation | 17 canonical specs and both active changes passed strict validation (19 items total); 121/121 API entries and 45/45 page sources mapped; all modified access scenarios retained; final link/idempotence/source checks recorded below |
| Independent PostgreSQL/Redis processes | Not rerun in this documentation audit; prior results in the integration ledger are historical evidence only |
| Live SERPRO/fiscal/WhatsApp providers | Not exercised; optional credentialed probes remain explicit skips |
| Browser/lint/typecheck/build | Not rerun in that documentation-only audit; earlier runtime evidence was not treated as current acceptance |

Commands and runtime details from that audit: [README](../../README.md), [integration ledger](../superpowers/plans/2026-10-13-legacy-integration.md). Temporary logs were local execution artifacts, not tracked credentials or provider payloads.

## Current platform acceptance 2026-10-18

The active change retains all 56 original dispositions: 46 valid and ten retired by D009. Its executable checklist currently has 46/46 checked tasks. All executable tasks are complete. Local browser acceptance and documentation reconciliation passed; canonical sync and archive remain separate. The following checks cover source checkpoint `8bb5c6a`; subsequent documentation edits do not change that source. Browser evidence before and after isolated-store recovery is explicitly separated; the form/API implementations were unchanged across recovery. Earlier Account/contact/Plan/Subscription/Support observations preceded the final auth-context rebuild; the final build revalidated route access, focus refresh, source forms and error recovery. The browser did not simulate a provider-delay timeout; backend protocol/timeout regressions and named UI provider-failure verdicts cover the corresponding layers.

| Final source check | Observed result |
| --- | --- |
| Complete backend suite, isolated store with production fiscal defaults | 2,385 total: 2,382 passed, three optional live-provider skips; 14,445 assertions, zero failures/errors |
| Complete isolated PostgreSQL/Redis suite | 116 passed; 2,765 assertions, zero failures/errors/skips; independent-process contention and lifecycle checks included |
| Frontend suite | 796 passed, zero failures/skips |
| Frontend lint, typecheck and production build | Passed; build completed in 2m23s |
| Changed PHP formatter and whitespace check | Passed |
| Independent final source review | Spec PASS / Quality APPROVED; no confirmed findings |
| Desktop/mobile browser matrix and forms | Seven routes at 1366×639 and 390×844 passed; shell/search/columns, real-member switch, account/contact/plan/subscription/support flows, catalog retry, direct/platform-vault upload and legacy source verified |
| Post-fix route/focus guards | Ordinary Admin denial, no-office Admin access, selected-own suspended Account and live role revocation pass; focus refresh redirects without a hard reload |
| Anonymous production SSR after auth-context correction | `/login` returned 200 and protected `/admin` redirected with 302; no `NUXT_E1001` in the fresh server log |

The optional skips are `SerproPgdasHomologationProbeTest::test_probe_real_auto_center_quando_pre_requisitos_existem`, `SerproTrialContractTest::test_the_trial_answers_a_regime_consultation`, and `SerproTrialContractTest::test_a_throttled_trial_is_skipped_rather_than_failed`. They do not establish live SERPRO acceptance. Actual Nuxt-to-Laravel multipart acceptance and independent credential/source process races are recorded in the [archived task list](../../openspec/changes/archive/2026-10-20-add-super-admin-platform/tasks.md); the full browser matrix and final documentation reconciliation have passed. Independent final source review is Spec PASS / Quality APPROVED with no findings. Canonical synchronization and archive completed on 2026-10-20; production gates remain unchanged. During browser acceptance, the isolated test containers and stores disappeared. Source and evidence survived; the coordinator restored only owned test services on new named volumes, verified the isolated database and source mounts, and completed the remaining browser acceptance on a fresh synthetic baseline. Prior fixture mutations and sessions were lost, not restored. These recorded results do not authorize production rollout.

## Registered application API inventory

Every registered /api/v1 route maps to a primary capability below. Auth and isolation additionally apply across every protected tenant route. Inventory counts route entries; GET|HEAD and PUT|PATCH are combined entries, not separate rows. Framework /up, development Boost logging, and framework storage routes are outside business API scope.

Admin account, summary, catalog, subscription, user, and support-log routes require Super Admin. The platform contracting-certificate vault also requires Super Admin. `GET /api/v1/serpro/connection` retains its existing safe-read policy for Super Admin or a member of the selected active Account; connection mutation and connectivity diagnostics require Super Admin. A platform role never substitutes for real Account membership on tenant operations.

| Method | Registered path | Primary capability |
| --- | --- | --- |
| GET / HEAD | `api/v1/account/members` | team-management |
| POST | `api/v1/account/members` | team-management |
| GET / HEAD | `api/v1/account/members/directory` | team-management |
| GET / HEAD | `api/v1/account/members/{member}` | team-management |
| PUT / PATCH | `api/v1/account/members/{member}` | team-management |
| DELETE | `api/v1/account/members/{member}` | team-management |
| POST | `api/v1/account/switch` | accounts |
| GET / HEAD | `api/v1/admin/accounts` | platform-account-management |
| POST | `api/v1/admin/accounts` | platform-account-management |
| GET / HEAD | `api/v1/admin/accounts/{id}` | platform-account-management |
| GET / HEAD | `api/v1/admin/accounts/{account}/contracting-certificate` | platform-serpro-connection |
| PUT | `api/v1/admin/accounts/{account}/contracting-certificate` | platform-serpro-connection |
| GET / HEAD | `api/v1/admin/accounts/{account}/commercial` | platform-account-management |
| PATCH | `api/v1/admin/accounts/{account}/commercial` | platform-account-management |
| PATCH | `api/v1/admin/accounts/{id}` | platform-account-management |
| POST | `api/v1/admin/accounts/{id}/first-access` | platform-account-management |
| GET / HEAD | `api/v1/admin/summary` | platform-administration |
| GET / HEAD | `api/v1/admin/plans` | platform-commercial-management |
| POST | `api/v1/admin/plans` | platform-commercial-management |
| GET / HEAD | `api/v1/admin/plans/{plan}` | platform-commercial-management |
| PUT / PATCH | `api/v1/admin/plans/{plan}` | platform-commercial-management |
| GET / HEAD | `api/v1/admin/subscriptions` | platform-commercial-management |
| GET / HEAD | `api/v1/admin/subscriptions/{subscription}` | platform-commercial-management |
| PUT / PATCH | `api/v1/admin/subscriptions/{subscription}` | platform-commercial-management |
| GET / HEAD | `api/v1/admin/support/logs` | platform-support-access |
| GET / HEAD | `api/v1/admin/users` | platform-administration |
| GET / HEAD | `api/v1/clients` | client-portfolio |
| POST | `api/v1/clients` | client-portfolio |
| GET / HEAD | `api/v1/clients/analytics` | client-portfolio |
| POST | `api/v1/clients/bulk-deletions` | client-portfolio |
| GET / HEAD | `api/v1/clients/bulk-deletions/{bulkDeletion}` | client-portfolio |
| POST | `api/v1/clients/cnpj-lookup` | client-portfolio |
| GET / HEAD | `api/v1/clients/saved-filters` | client-portfolio |
| POST | `api/v1/clients/saved-filters` | client-portfolio |
| DELETE | `api/v1/clients/saved-filters/{savedFilter}` | client-portfolio |
| POST | `api/v1/clients/selections` | client-portfolio |
| POST | `api/v1/clients/selections/{selection}/presence` | client-portfolio |
| GET / HEAD | `api/v1/clients/summary` | client-portfolio |
| POST | `api/v1/clients/tags` | client-portfolio |
| GET / HEAD | `api/v1/clients/{client}` | client-portfolio |
| PUT / PATCH | `api/v1/clients/{client}` | client-portfolio |
| DELETE | `api/v1/clients/{client}` | client-portfolio |
| POST | `api/v1/clients/{client}/certificate` | client-certificates |
| DELETE | `api/v1/clients/{client}/certificate` | client-certificates |
| POST | `api/v1/clients/{client}/cnpj-refresh` | client-portfolio |
| POST | `api/v1/clients/{client}/cnpj-refresh-preview` | client-portfolio |
| GET / HEAD | `api/v1/clients/{client}/dispatch-contacts` | whatsapp-delivery |
| POST | `api/v1/clients/{client}/dispatch-contacts` | whatsapp-delivery |
| PUT / PATCH | `api/v1/clients/{client}/dispatch-contacts/{contact}` | whatsapp-delivery |
| DELETE | `api/v1/clients/{client}/dispatch-contacts/{contact}` | whatsapp-delivery |
| GET / HEAD | `api/v1/clients/{client}/monitoring-modules` | client-portfolio |
| POST | `api/v1/clients/{client}/monitoring-modules` | client-portfolio |
| GET / HEAD | `api/v1/csrf` | auth |
| GET / HEAD | `api/v1/departments` | team-management |
| POST | `api/v1/departments` | team-management |
| PUT / PATCH | `api/v1/departments/{department}` | team-management |
| DELETE | `api/v1/departments/{department}` | team-management |
| POST | `api/v1/first-access` | auth |
| GET / HEAD | `api/v1/first-access/status` | auth |
| GET / HEAD | `api/v1/fiscal/clients` | fiscal-documents |
| POST | `api/v1/fiscal/clients/{client}/capture` | fiscal-documents |
| GET / HEAD | `api/v1/fiscal/documents` | fiscal-documents |
| GET / HEAD | `api/v1/fiscal/documents/{fiscalDocument}` | fiscal-documents |
| GET / HEAD | `api/v1/fiscal/documents/{fiscalDocument}/xml` | fiscal-documents |
| GET / HEAD | `api/v1/fiscal/summary` | fiscal-documents |
| GET / HEAD | `api/v1/health` | application-runtime |
| POST | `api/v1/login` | auth |
| POST | `api/v1/logout` | auth |
| GET / HEAD | `api/v1/me` | auth |
| GET / HEAD | `api/v1/process-templates` | work-management |
| POST | `api/v1/process-templates` | work-management |
| GET / HEAD | `api/v1/process-templates/{process_template}` | work-management |
| PUT / PATCH | `api/v1/process-templates/{process_template}` | work-management |
| DELETE | `api/v1/process-templates/{process_template}` | work-management |
| POST | `api/v1/process-templates/{process_template}/generate` | work-management |
| GET / HEAD | `api/v1/process-templates/{process_template}/preview` | work-management |
| GET / HEAD | `api/v1/processes` | work-management |
| POST | `api/v1/processes` | work-management |
| GET / HEAD | `api/v1/processes/{process}` | work-management |
| PUT / PATCH | `api/v1/processes/{process}` | work-management |
| DELETE | `api/v1/processes/{process}` | work-management |
| POST | `api/v1/register` | auth |
| GET / HEAD | `api/v1/registration-status` | auth |
| GET / HEAD | `api/v1/serpro/account-certificate` | monitoring-serpro |
| POST | `api/v1/serpro/account-certificate` | monitoring-serpro |
| DELETE | `api/v1/serpro/account-certificate` | monitoring-serpro |
| GET / HEAD | `api/v1/serpro/authorization-terms` | monitoring-serpro |
| GET / HEAD | `api/v1/serpro/connection` | platform-serpro-connection |
| PUT | `api/v1/serpro/connection` | platform-serpro-connection |
| POST | `api/v1/serpro/connectivity` | platform-serpro-connection |
| GET / HEAD | `api/v1/serpro/enablement` | monitoring-serpro |
| PUT | `api/v1/serpro/enablement` | monitoring-serpro |
| GET / HEAD | `api/v1/serpro/monitoring/obligations/{obligation}` | monitoring-serpro |
| POST | `api/v1/serpro/monitoring/obligations/{obligation}/clients` | monitoring-serpro |
| POST | `api/v1/serpro/monitoring/obligations/{obligation}/clients/search` | monitoring-serpro |
| GET / HEAD | `api/v1/serpro/monitoring/obligations/{obligation}/clients/{client}/declaration-detail` | monitoring-serpro |
| POST | `api/v1/serpro/monitoring/obligations/{obligation}/clients/{client}/messages/{message}` | monitoring-serpro |
| GET / HEAD | `api/v1/serpro/monitoring/obligations/{obligation}/clients/{client}/periods/{period}/artifacts/{artifact}` | monitoring-serpro |
| POST | `api/v1/serpro/monitoring/obligations/{obligation}/clients/{client}/whatsapp/dispatch` | whatsapp-delivery |
| GET / HEAD | `api/v1/serpro/monitoring/obligations/{obligation}/clients/{client}/whatsapp/dispatches` | whatsapp-delivery |
| POST | `api/v1/serpro/monitoring/obligations/{obligation}/clients/{client}/whatsapp/send-guides` | whatsapp-delivery |
| GET / HEAD | `api/v1/serpro/monitoring/obligations/{obligation}/search-quota` | monitoring-serpro |
| GET / HEAD | `api/v1/serpro/monitoring/overview` | monitoring-serpro |
| GET / HEAD | `api/v1/serpro/obligation-schedules` | monitoring-serpro |
| PUT | `api/v1/serpro/obligation-schedules` | monitoring-serpro |
| GET / HEAD | `api/v1/serpro/sync-runs` | monitoring-serpro |
| POST | `api/v1/serpro/sync-runs` | monitoring-serpro |
| GET / HEAD | `api/v1/serpro/sync-runs/{run}` | monitoring-serpro |
| GET / HEAD | `api/v1/serpro/sync-runs/{run}/calls` | monitoring-serpro |
| POST | `api/v1/serpro/sync-runs/{run}/resync` | monitoring-serpro |
| GET / HEAD | `api/v1/tags` | client-portfolio |
| POST | `api/v1/tags` | client-portfolio |
| PUT / PATCH | `api/v1/tags/{tag}` | client-portfolio |
| DELETE | `api/v1/tags/{tag}` | client-portfolio |
| GET / HEAD | `api/v1/tasks` | work-management |
| GET / HEAD | `api/v1/tasks/{task}` | work-management |
| PUT / PATCH | `api/v1/tasks/{task}` | work-management |
| POST | `api/v1/webhooks/wzap` | whatsapp-delivery |
| GET / HEAD | `api/v1/whatsapp/channel` | whatsapp-delivery |
| DELETE | `api/v1/whatsapp/channel` | whatsapp-delivery |
| POST | `api/v1/whatsapp/channel/pairing` | whatsapp-delivery |
| GET / HEAD | `api/v1/whatsapp/channel/pairing` | whatsapp-delivery |
| GET / HEAD | `api/v1/work/calendar` | work-management |
| GET / HEAD | `api/v1/work/grouped` | work-management |
| GET / HEAD | `api/v1/work/tasks/unscoped` | work-management |

Coverage: 121 of 121 registered application route entries mapped.

## Frontend page inventory

Every page source is mapped below. Auth/access-pages and isolation are cross-cutting; dynamic paths use their Nuxt source notation.

| Page source | Primary capability / status |
| --- | --- |
| [acesso-indisponivel.vue](../../frontend/app/pages/acesso-indisponivel.vue) | access-pages / auth |
| [admin/assinaturas.vue](../../frontend/app/pages/admin/assinaturas.vue) | platform-administration |
| [admin/contas.vue](../../frontend/app/pages/admin/contas.vue) | platform-administration |
| [admin/index.vue](../../frontend/app/pages/admin/index.vue) | platform-administration |
| [admin/planos.vue](../../frontend/app/pages/admin/planos.vue) | platform-administration |
| [admin/serpro.vue](../../frontend/app/pages/admin/serpro.vue) | platform-administration |
| [admin/suporte.vue](../../frontend/app/pages/admin/suporte.vue) | platform-administration |
| [admin/usuarios.vue](../../frontend/app/pages/admin/usuarios.vue) | platform-administration |
| [admin.vue](../../frontend/app/pages/admin.vue) | platform-administration |
| [customers/[documento]/[[situacao]].vue](../../frontend/app/pages/customers/[documento]/[[situacao]].vue) | client-portfolio / client-certificates |
| [customers/empresa/[id].vue](../../frontend/app/pages/customers/empresa/[id].vue) | client-portfolio / client-certificates |
| [customers/index.vue](../../frontend/app/pages/customers/index.vue) | client-portfolio / client-certificates |
| [customers/painel.vue](../../frontend/app/pages/customers/painel.vue) | client-portfolio / client-certificates |
| [customers.vue](../../frontend/app/pages/customers.vue) | client-portfolio / client-certificates |
| [equipe/departamentos.vue](../../frontend/app/pages/equipe/departamentos.vue) | team-management |
| [equipe/index.vue](../../frontend/app/pages/equipe/index.vue) | team-management |
| [equipe.vue](../../frontend/app/pages/equipe.vue) | team-management |
| [fiscal/clientes.vue](../../frontend/app/pages/fiscal/clientes.vue) | fiscal-documents |
| [fiscal/documentos.vue](../../frontend/app/pages/fiscal/documentos.vue) | fiscal-documents |
| [fiscal/index.vue](../../frontend/app/pages/fiscal/index.vue) | fiscal-documents |
| [fiscal.vue](../../frontend/app/pages/fiscal.vue) | fiscal-documents |
| [inbox.vue](../../frontend/app/pages/inbox.vue) | access-pages / demonstration or template settings |
| [index.vue](../../frontend/app/pages/index.vue) | access-pages / demonstration or template settings |
| [login.vue](../../frontend/app/pages/login.vue) | access-pages / auth |
| [monitoring/[...slug].vue](../../frontend/app/pages/monitoring/[...slug].vue) | monitoring-serpro / whatsapp-delivery |
| [monitoring/index.vue](../../frontend/app/pages/monitoring/index.vue) | monitoring-serpro / whatsapp-delivery |
| [monitoring.vue](../../frontend/app/pages/monitoring.vue) | monitoring-serpro / whatsapp-delivery |
| [onboarding.vue](../../frontend/app/pages/onboarding.vue) | access-pages / auth |
| [primeiro-acesso.vue](../../frontend/app/pages/primeiro-acesso.vue) | access-pages / auth |
| [selecionar-escritorio.vue](../../frontend/app/pages/selecionar-escritorio.vue) | access-pages / auth |
| [settings/certificado.vue](../../frontend/app/pages/settings/certificado.vue) | monitoring-serpro |
| [settings/index.vue](../../frontend/app/pages/settings/index.vue) | access-pages / demonstration or template settings |
| [settings/members.vue](../../frontend/app/pages/settings/members.vue) | access-pages / demonstration or template settings |
| [settings/notifications.vue](../../frontend/app/pages/settings/notifications.vue) | access-pages / demonstration or template settings |
| [settings/security.vue](../../frontend/app/pages/settings/security.vue) | access-pages / demonstration or template settings |
| [settings/whatsapp.vue](../../frontend/app/pages/settings/whatsapp.vue) | whatsapp-delivery |
| [settings.vue](../../frontend/app/pages/settings.vue) | access-pages / demonstration or template settings |
| [work/calendario.vue](../../frontend/app/pages/work/calendario.vue) | work-management |
| [work/clientes.vue](../../frontend/app/pages/work/clientes.vue) | work-management |
| [work/modelos/[id].vue](../../frontend/app/pages/work/modelos/[id].vue) | work-management |
| [work/modelos/index.vue](../../frontend/app/pages/work/modelos/index.vue) | work-management |
| [work/processos/[id].vue](../../frontend/app/pages/work/processos/[id].vue) | work-management |
| [work/processos/index.vue](../../frontend/app/pages/work/processos/index.vue) | work-management |
| [work/tarefas.vue](../../frontend/app/pages/work/tarefas.vue) | work-management |
| [work.vue](../../frontend/app/pages/work.vue) | work-management |

Coverage: 45 of 45 frontend page sources mapped.

## Documentation integrity

All documentation links checked in this reconciliation resolve locally. The sync was repeated with zero canonical changes. Canonical specs have populated Purpose sections, one Requirements section, no operation headers, and no duplicate requirement names. Application source hashes remained unchanged during the audit. Existing archived changes and the earlier platform task checkbox states were preserved.

The apply review found that the surviving auth bootstrap requirement still prohibited subscriptions and default departments, contradicting Account/commercial defaults and the Account creation observer. With explicit user approval, the auth delta now describes those defaults in the creation transaction and preserves all three original bootstrap scenarios. No application behavior was changed.

The follow-up audit checked 126 local documentation links, all 121 registered application routes, all 45 frontend page sources, and 1,062 application/runtime/historical-archive file hashes. The regular frontend rerun (`docker compose exec -T frontend pnpm test`) passed all 598 tests with no skips. The backend rerun from `backend/` (`FISCAL_ENVIRONMENT=producao php artisan test --compact`) passed 1,669 tests with 9,206 assertions and three optional live-probe skips, out of 1,672 total tests. PostgreSQL/Redis races and live providers remain outside this audit's verification evidence.

## ERP and complete-XML archive — 2026-10-17

At the user's request, the ten changes with completed checklists were synchronized to canonical specs before archival. All use the `spec-driven` schema and have complete proposal, specs, design and tasks artifacts. The 113 checked tasks and their existing evidence remain in the archives below.

| Archived change | Checked tasks | Canonical capability |
| --- | --- | --- |
| [add-erp-company-foundation](../../openspec/changes/archive/2026-10-17-add-erp-company-foundation/tasks.md) | 10/10 | [erp-company-foundation](../../openspec/specs/erp-company-foundation/spec.md), auth, access-pages, isolation, application-runtime |
| [add-erp-commercial-access](../../openspec/changes/archive/2026-10-17-add-erp-commercial-access/tasks.md) | 7/7 | [erp-commercial-access](../../openspec/specs/erp-commercial-access/spec.md) |
| [add-erp-core-catalogs](../../openspec/changes/archive/2026-10-17-add-erp-core-catalogs/tasks.md) | 10/10 | [erp-core-catalogs](../../openspec/specs/erp-core-catalogs/spec.md) |
| [add-erp-finance-and-cash](../../openspec/changes/archive/2026-10-17-add-erp-finance-and-cash/tasks.md) | 10/10 | [erp-finance-and-cash](../../openspec/specs/erp-finance-and-cash/spec.md) |
| [add-erp-purchasing-and-stock](../../openspec/changes/archive/2026-10-17-add-erp-purchasing-and-stock/tasks.md) | 8/8 | [erp-purchasing](../../openspec/specs/erp-purchasing/spec.md), [erp-inventory](../../openspec/specs/erp-inventory/spec.md) |
| [add-erp-sales-and-orders](../../openspec/changes/archive/2026-10-17-add-erp-sales-and-orders/tasks.md) | 7/7 | [erp-sales](../../openspec/specs/erp-sales/spec.md) |
| [add-erp-fiscal-issuance](../../openspec/changes/archive/2026-10-17-add-erp-fiscal-issuance/tasks.md) | 17/17 | [erp-fiscal-issuance](../../openspec/specs/erp-fiscal-issuance/spec.md) |
| [add-erp-accounting-documents](../../openspec/changes/archive/2026-10-17-add-erp-accounting-documents/tasks.md) | 7/7 | [erp-accounting-documents](../../openspec/specs/erp-accounting-documents/spec.md), fiscal-documents |
| [add-erp-reports-and-launch](../../openspec/changes/archive/2026-10-17-add-erp-reports-and-launch/tasks.md) | 8/8 | [erp-reports](../../openspec/specs/erp-reports/spec.md), application-runtime |
| [complete-xml-accounting-workflow](../../openspec/changes/archive/2026-10-17-complete-xml-accounting-workflow/tasks.md) | 29/29 | [fiscal-documents](../../openspec/specs/fiscal-documents/spec.md), application-runtime |

The merge created ten ERP specs and updated five existing capabilities, adding 45 requirements and modifying 13. Existing Purpose text, unrelated requirements and surviving scenarios were retained. All 58 delta requirements were compared with the resulting main specs, and a second merge produced no changes. Relative links between archived changes and repository references were adjusted; `.openspec.yaml` metadata was preserved. Two older Work page links now point to their existing nested `index.vue` files.

The archive preserves checklist status without turning it into deployment acceptance. Several ERP task evidence sections explicitly report that desktop/mobile browser flows were not run. ERP issuance/live model canaries, complete-core production approval, NF-e science/recovery canaries and wider fleet activation remain unverified or blocked in the recorded evidence. Existing provider, release and rollout gates were not changed. The earlier source-core inventory and verification tables above remain their dated audit evidence, not a fresh ERP acceptance report.

This documentation-only workflow validates the canonical OpenSpec contracts, every selected delta, archive contents and local links. Application tests, browser sessions and live providers were not rerun. Application/runtime files and the incomplete [add-super-admin-platform](../../openspec/changes/archive/2026-10-20-add-super-admin-platform/tasks.md) change were preserved; that 2026-10-13 audit snapshot recorded 9/56 checked original tasks. The reconciled 2026-10-18 snapshot separately reports 46/46 valid tasks checked after ten items were retired.

Final archive verification: `openspec validate --all --strict --no-interactive` passed all 28 active items (27 specs and one change); `openspec list --json` reported only `add-super-admin-platform`. All 58 archived delta requirements matched canonical specs, all 113 checked tasks and ten metadata files were preserved, and all 227 local links across 55 scoped documents resolved. Hash comparison confirmed 1,387 application/runtime and pending-change files unchanged. The 58 archived files retained their content except for adjusted Markdown link targets.


## Platform canonical synchronization and archive — 2026-10-20

The accepted Super Admin change is [archived](../../openspec/changes/archive/2026-10-20-add-super-admin-platform/proposal.md). All five reconciled deltas were synchronized into canonical platform specifications: 18 modified and five added requirements, preserving existing Purpose sections, scenarios and D009. A repeated merge proposed no changes. The archive retains 46 accepted valid tasks and ten explicitly retired originals; retirement is not implementation.

Before archive, canonical validation passed 27 specifications and strict validation passed 28 items. Fresh focused platform regressions passed 108 tests and 1,102 assertions before integration and on locally integrated main `75bb6ad` using PHP 8.4 in an isolated network-disabled container. Host PHP/OpenSSL could not generate three test certificates; the supported container run passed. The earlier complete backend/PostgreSQL/frontend/browser evidence remains dated above and was not rerun by this documentation operation. Separate ERP completion and combined acceptance remain in progress. No push, live SERPRO request, fiscal rollout, APP_KEY rotation or production-gate change occurred.
