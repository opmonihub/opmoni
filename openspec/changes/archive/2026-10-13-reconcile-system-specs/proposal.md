## Why

The canonical specs still describe the access-only release, while D009 authorized and the working tree implements the legacy business integration. The pending Super Admin proposal also contains contracts that differ from the delivered application. Planning must distinguish the current system, accepted decisions, and remaining gaps before future changes rely on it.

## What Changes

- Map every application API, frontend page family, tenant boundary, scheduled operation, and external integration to delta specs and implementation evidence.
- Reconcile the four access capabilities without losing their existing identity, activation, session, or isolation scenarios.
- Add contracts for the five platform capabilities and all delivered business modules, then semantically synchronize these deltas into canonical specs.
- Reconcile D001/D002's planned fiscal extraction with the fiscal implementation currently inside Laravel, and D004's deferrals with D009.
- Keep the earlier `add-super-admin-platform` proposal available as a pending target, with explicit supersession/gap notes; do not blindly publish its foreign support, uniform envelope, arbitrary certificate-source, or concurrency promises as delivered functionality.
- Record verification evidence and unresolved implementation risks in `docs/architecture/system-spec-reconciliation.md`.

## Capabilities

### New Capabilities

- `client-portfolio`: Client CRUD, validation, filters, analytics, selections, tags, CNPJ lookup, and bulk deletion.
- `client-certificates`: Private A1 certificate validation, replacement, removal, encryption, and safe metadata.
- `fiscal-documents`: Fiscal coverage, documents/XML, capture, recovery, manifestation, and source gates.
- `monitoring-serpro`: Account certificate/authorization, monitoring, schedules, sync runs, manual search, and protected artifacts.
- `work-management`: Templates, recurring processes, tasks, assignment, progress, and calendar.
- `team-management`: Real memberships, last-admin protection, directory, and departments.
- `whatsapp-delivery`: Account channel, dispatch contacts, explicit guide delivery, tracking, and webhooks.
- `application-runtime`: Private API ingress, development runtime, background processing, storage, and isolated verification.
- `platform-administration`: Seven Admin pages, summary composition, global users, authorization, and existing response conventions.
- `platform-account-management`: Foundational provisioning plus separate commercial-contact presentation and editing.
- `platform-commercial-management`: Catalog, default subscriptions, editing, operational write gating, and creation limits.
- `platform-support-access`: Membership-only entry and read-only audit browsing, with foreign support superseded by D009.
- `platform-serpro-connection`: Singleton credential lifecycle, safe metadata, certificate sources, and connectivity.

### Modified Capabilities

- `auth`: Extend the existing CSRF/session contract to business mutations without creating a second authentication mechanism.
- `accounts`: Remove obsolete membership/commercial deferrals and document additive business defaults.
- `isolation`: Document active role enforcement, subscription write gates, and explicit background context restoration.
- `access-pages`: Expose integrated business/Admin navigation while retaining the approved demonstration home and access flows.

## Impact

Documentation and OpenSpec artifacts only; no application behavior, dependencies, deployment, credentials, development data, or source migration changes. D009 is the accepted scope authority; this reconciliation does not invent new product approval or retire the planned fiscal extraction. Provider canaries and stronger pending platform guarantees remain unverified or unimplemented as individually recorded. Changes remain active until a separate archive decision; synchronization alone does not complete the earlier implementation checklist.
