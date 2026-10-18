# System specification reconciliation

Reconciled on 2026-10-13 from the current working tree, accepted product decisions, registered routes, page sources, and current test runs. This is a system-wide contract map and documentation audit, not a claim that every provider or deployment behavior has passed acceptance.

## Authority and scope

- [Product decisions](product-decisions.md) govern accepted scope. D009 activates Clients, Fiscal, Monitoring, Work, Team, Settings, and platform administration while preserving real membership and existing access contracts.
- [Reconciliation proposal](../../openspec/changes/archive/2026-10-13-reconcile-system-specs/proposal.md), [design](../../openspec/changes/archive/2026-10-13-reconcile-system-specs/design.md), and [tasks](../../openspec/changes/archive/2026-10-13-reconcile-system-specs/tasks.md) contain the reviewable delta history, archived on 2026-10-13 after completion and synchronization.
- [Canonical specs](../../openspec/specs/) describe observable contracts after synchronization. Stronger accepted guarantees are not weakened merely because runtime verification is incomplete.
- [Earlier platform proposal](../../openspec/changes/add-super-admin-platform/proposal.md) remains active with unchecked implementation tasks. Its original deltas are pending targets, not a description of all delivered behavior. Foreign support is superseded by D009; other differences below remain follow-up candidates.
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

| Earlier target | Current system | Disposition |
| --- | --- | --- |
| Foreign support enter/exit, effective admin role and banner | Membership-only Account switching; no enter/exit routes or support projection | Superseded by D009; do not reintroduce by sync |
| Audited support mutation lifecycle | Existing log reader; support audit helper is unreachable for ordinary membership-only HTTP operations | Do not claim active lifecycle audit coverage |
| Dedicated GET /admin/summary | Frontend composes Account/user/subscription/plan/log APIs | Document current API composition |
| Generic GET /admin/accounts/{id} and root contact fields | Separate GET/PATCH /admin/accounts/{id}/commercial and settings-backed platform_billing_contact | Preserve actual endpoint/envelope and owner secrecy |
| Uniform meta.page and data-wrapped plan list | Account meta.page, resource meta.current_page, raw plan array | Frontend normalizes current pagination; uniform target remains unimplemented |
| Commercial metadata without operational effect | ResolveAccount blocks unsafe methods when subscription is not active; PlanLimits enforces users/clients creation | D009 imported behavior now explicitly specified |
| Exactly three fully validated nullable plan-limit keys | Controller accepts generic limits array; UI editor and API validation differ | Strict schema/finite-to-unlimited handling needs follow-up verification |
| Insert-only full catalog seeder and platform:initialize command | Observer lazily creates Básico; additive migration backfills defaults; PlanSeeder upsert can overwrite edited catalog | Do not claim non-destructive full catalog reseeding or absent command |
| Arbitrary contracting Account selector and global certificate API | Reuse selects earliest Account; e-CNPJ upload is tenant-admin controlled in settings | Arbitrary-source API/selector remains unimplemented |
| certificate_source/contracting_account_id metadata and rotation revision fencing | Resource omits those fields; current singleton/rotation has fewer advertised guarantees | Retain as pending verification/design gaps |
| Unified connectivity actor/IP budgets and fixed proxy/provider deadlines | Existing route throttle and runtime timeout configuration | Do not claim stronger budgets/fencing without dedicated checks |
| Fully reviewed 56-task implementation | Earlier checklist remains unchecked | Import presence and successful regular tests do not complete that checklist |

## Known limits and implementation follow-ups

1. **Fiscal extraction:** D001/D002's services/fiscal microservice and NFePHP package layout are not implemented. Laravel currently owns capture, reconciliation, manifestation, XML storage, and jobs.
2. **Private persistence:** disks are private, Account-separated paths under backend/storage. Local Compose preserves them through a source bind mount; no dedicated certificate/XML volume or deployed persistence policy was proven here. Keep the persistence contract and verify deployment separately.
3. **Fiscal rollout:** CT-e manual and scheduled flags are separate; the scheduled command does not universally inherit the manual gate. NFS-e and manifestation retain their own opt-in gates. Do not treat fake contracts or a single-client canary as fleet-wide production acceptance.
4. **SERPRO source lifecycle:** earliest-Account reuse, safe metadata, certificate validity enforcement, source rotation, and mixed-snapshot concurrency need comparison with the stronger pending target. Local regular tests do not prove external credentials or independent-process credential races.
5. **Administrative UI:** support search traverses matching server-filtered pages; it is not the proposed one-detail-lookup workflow. Plan API has broader payload acceptance than the older strict target. Browser/mobile acceptance of every mapped state was not repeated here.
6. **Demonstration surfaces:** home widgets, inbox, and remaining template settings/sample handlers are not complete business features. They remain separately identified in the frontend inventory below.
7. **Verification environment:** the development backend container mounts only backend at /app and sets fiscal homologation. Tests that inspect repository siblings/Compose or expect production defaults need the repository layout and explicit FISCAL_ENVIRONMENT=producao. The initial direct container run failed for these reasons; its affected test group was rerun successfully without source edits.

## Current verification

| Check | Current evidence |
| --- | --- |
| Frontend regular tests | 598 passed, zero failures/skips using pnpm test |
| Backend initial container run | 1,672 tests, 9,131 assertions; 5 missing-sibling/Compose errors, 7 fiscal environment expectation failures, 3 optional live probes skipped |
| Affected backend group in repository layout | 166 passed, 884 assertions with FISCAL_ENVIRONMENT=producao |
| Complete backend rerun in repository layout | 1,672 total: 1,669 passed, 9,206 assertions, zero failures/errors, 3 optional external probes skipped; repository-layout execution with FISCAL_ENVIRONMENT=producao |
| OpenSpec strict validation, route coverage, links, idempotence, source preservation | 17 canonical specs and both active changes passed strict validation (19 items total); 121/121 API entries and 45/45 page sources mapped; all modified access scenarios retained; final link/idempotence/source checks recorded below |
| Independent PostgreSQL/Redis processes | Not rerun in this documentation audit; prior results in the integration ledger are historical evidence only |
| Live SERPRO/fiscal/WhatsApp providers | Not exercised; optional credentialed probes remain explicit skips |
| Browser/lint/typecheck/build | Not rerun for this documentation-only change; earlier runtime evidence is not current acceptance |

Commands and runtime details: [README](../../README.md), [integration ledger](../superpowers/plans/2026-10-13-legacy-integration.md). Temporary logs used for current evidence are local execution artifacts, not tracked credentials or provider payloads.

## Registered application API inventory

Every registered /api/v1 route maps to a primary capability below. Auth and isolation additionally apply across every protected tenant route. Inventory counts route entries; GET|HEAD and PUT|PATCH are combined entries, not separate rows. Framework /up, development Boost logging, and framework storage routes are outside business API scope.

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
| GET / HEAD | `api/v1/admin/accounts/{account}/commercial` | platform-account-management |
| PATCH | `api/v1/admin/accounts/{account}/commercial` | platform-account-management |
| PATCH | `api/v1/admin/accounts/{id}` | platform-account-management |
| POST | `api/v1/admin/accounts/{id}/first-access` | platform-account-management |
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

This documentation-only workflow validates the canonical OpenSpec contracts, every selected delta, archive contents and local links. Application tests, browser sessions and live providers were not rerun. Application/runtime files and the incomplete [add-super-admin-platform](../../openspec/changes/add-super-admin-platform/tasks.md) change were preserved; that change remains active at 9/56 checked tasks.

Final archive verification: `openspec validate --all --strict --no-interactive` passed all 28 active items (27 specs and one change); `openspec list --json` reported only `add-super-admin-platform`. All 58 archived delta requirements matched canonical specs, all 113 checked tasks and ten metadata files were preserved, and all 227 local links across 55 scoped documents resolved. Hash comparison confirmed 1,387 application/runtime and pending-change files unchanged. The 58 archived files retained their content except for adjusted Markdown link targets.
