## Context

See `proposal.md` for scope. The current working tree has a Nuxt frontend, a private `/api/v1` proxy, Laravel-owned sessions/authorization/data, PostgreSQL, Redis, and imported business modules. `services/fiscal/` does not exist; fiscal integrations currently execute in Laravel. Existing source changes are outside this documentation-only reconciliation.

## Goals / Non-Goals

**Goals:** Establish traceable behavioral contracts across the whole application; preserve surviving access scenarios; separate accepted decisions, delivered contracts, and failed/unverified guarantees.

**Non-Goals:** Implement missing APIs, change permissions or subscription gates, perform provider calls, alter credentials/data, approve production rollout, or complete/archive the older platform implementation plan.

## Decisions

### Evidence and decision precedence

D009 controls accepted business scope and real-membership access. D008 controls default-office login. Earlier access scenarios survive unless explicitly modified. Observable routes, middleware, controllers, forms, resources, jobs, configuration, and existing tests supply implementation evidence. Code that conflicts with an accepted security/storage guarantee is recorded as a gap rather than used to weaken that guarantee.

Alternative: copy every earlier platform delta directly. Rejected because foreign support violates D009 and several planned routes/envelopes do not exist.

### Complete delta coverage before synchronization

Seventeen flat capability paths cover the application. Four modify the existing access baseline; thirteen introduce delivered business/platform/runtime contracts. Modified requirement blocks retain every pre-existing scenario. New deltas contain Purpose and ADDED sections; canonical specs contain one Purpose and one Requirements section, never delta operation headers.

The implementation-to-spec mapping, complete registered application route inventory, frontend-page inventory, and outstanding differences live in `docs/architecture/system-spec-reconciliation.md`. Mapping describes coverage; passing OpenSpec validation is not proof that every runtime scenario passes.

Alternative: edit canonical specs without deltas. Rejected because it loses the requested change history and reviewable before/after record.

### Keep current transport and fiscal boundaries explicit

Browser/SSR calls remain relative and session-bound; JSON, multipart, and XML downloads preserve the existing ingress, CSRF, and Account assertion. Platform lists currently have heterogeneous envelopes. The summary composes existing APIs. Fiscal capture and storage currently belong to Laravel; the separate PHP/NFePHP service remains an unimplemented extraction target under D001/D002. No new microservice API is invented.

Alternative: normalize documentation to an ideal future API. Rejected because clients would then rely on nonexistent endpoints and serialization fields.

### Pending platform proposal remains reviewable

The older `add-super-admin-platform` artifacts retain their original task evidence and proposed guarantees. A reconciliation notice identifies the canonical baseline, D009's superseded foreign support, and pending stronger guarantees. Its unchecked tasks are not marked complete based on an imported screen or this sync. Its deltas must be revised against the reconciled baseline before future synchronization or archive.

## Risks / Trade-offs

- Accepted private-storage persistence/encryption guarantees may exceed deployed configuration → retain the guarantee and record the exact runtime gap; do not claim a source mount proves production persistence.
- Imported provider guards and certificate-source assumptions differ from the older target → document their current scope and require independent verification before broader rollout.
- A complete regular suite can reveal unrelated failures → record them with test identities; preserve implementation files.
- Feature tests do not prove process races or live providers → report the independent-process and live-check status separately.
- Concurrent user changes may move the source baseline during the audit → compare source hashes and identify observed changes without reverting them.

## Migration Plan

No application migration or deployment is needed. Read the CLI-provided specs rules, create the deltas, reconcile documentation, merge selected deltas semantically into canonical specs, and run strict validation. Keep both changes active. Recovery consists of reverting only this run's documentation edits; preserve existing uncommitted application work and historical archives.
