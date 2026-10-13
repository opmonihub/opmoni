## Why

The current application exposes only a basic platform Account page, while the legacy Super Admin area provides seven connected administration surfaces. The user now wants the complete area in the current Nuxt/Laravel application, with working backend contracts rather than copied screens backed by missing endpoints.

## What Changes

- Add a Super Admin submenu and shared Admin shell for Resumo, Contas, Planos, Assinaturas, Usuários, Suporte, and Serpro, following the mapped legacy presentation.
- Add global summary and read-only user listing APIs; extend Account resources with commercial contact fields, plan information, and a consistent detail endpoint.
- Preserve current owner activation, link reissue, existing identities, default-office login, and the absolute session deadline. Do not restore legacy manual password assignment.
- Add a persisted, initialized plan catalog and one administrative subscription per Account, with editable limits, plan, and status. No payment processing or operational quota enforcement is introduced.
- Add explicit session-local support access to active foreign Accounts, an exit/banner flow, and transactional audit events without changing memberships or the operator's identity.
- Add encrypted platform Serpro credentials, validated certificate upload, safe metadata, rotation, and a real authentication-only connectivity check. Implement the backend infrastructure needed to reproduce the legacy certificate-source selector, including a private contracting-office certificate vault and metadata/upload API; no separate office settings page is added.
- Add authorization, tenancy, session, multipart-ingress, concurrency, and UI verification for the new workflows.

## Capabilities

### New Capabilities

- `platform-administration`: Super Admin routes, shared navigation, summary, global user catalog, and protected API/response conventions.
- `platform-account-management`: Extended platform Account listing/detail/contact presentation while preserving the existing provisioning and activation contracts.
- `platform-commercial-management`: Initial plan catalog, non-destructive subscription provisioning, and administrative plan/subscription editing.
- `platform-support-access`: Session-local audited support context, identity representation, tenant authorization, exit, and support-event browsing.
- `platform-serpro-connection`: Global encrypted connection, contracting-office certificate source, rotation, metadata, and connectivity verification.

### Modified Capabilities

No modified capability deltas are declared in this proposal. The access foundation is now archived under `openspec/changes/archive/2026-10-12-add-access-accounts-foundation/`, and its canonical contracts are in `openspec/specs/{auth,accounts,isolation,access-pages}/spec.md`. The five platform capabilities explicitly preserve or extend that baseline; these existing contracts must be checked when reviewing and applying the change.

## Impact

- Frontend: shared Admin page, seven child pages, navigation helpers, table/retry components, typed API contracts, identity/support handling, route/focus guards, and multipart proxy behavior.
- Backend: versioned platform/support/Serpro endpoints, Form Requests and Resources, Plan/Subscription/audit/connection/certificate persistence, support-aware Account context, secret-safe HTTP authentication, and tests.
- Database: additive migrations and idempotent provisioning/backfill for existing Accounts; no legacy database or credential migration and no destructive reset.
- Infrastructure: retain PostgreSQL and Redis sessions/serialization; verify PHP OpenSSL/cURL support, private temporary certificate handling, upload limits, and proxy/provider timeout alignment. Use existing dependencies where sufficient.
- Scope: supersedes the previous deferral of administrative plans/subscriptions and Serpro configuration for this proposed change only. Monitoring, fiscal service operations, Work, WhatsApp dispatch, billing collection, automatic password recovery, user CRUD, and automatic provider synchronization remain outside this change.
- Reference: `docs/architecture/super-admin-frontend-map.md` and `/home/obsidian/dev/legacy` are source inventories; current access behavior remains the compatibility baseline.
