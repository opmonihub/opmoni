# Legacy business integration

## Contract

Complete the previously copied business modules in the existing Opmoni application. Preserve canonical access screens, session ownership, account membership enforcement, theme, and existing `/api/v1` routes. Domain logic belongs to the existing backend/frontend directories; source collisions are references, not a second application. Do not restore the legacy persistent current-account field or create a parallel Sanctum authentication flow.

## Tasks

1. Reproduce baseline failures and save a full source backup before writes. Baseline: backend 358 tests, 347 pass, missing XMLSecLibs causes 11 failures/errors. Frontend typecheck is running.
2. Backend: install missing XML signing dependency, merge business methods into Account/User, map membership relations to account_memberships, enforce tenant roles and context, activate business APIs, reconcile policies/storage/provider/schedules, initialize business defaults safely.
3. Frontend: extend existing auth/API helpers, add account assertion and CSRF to business requests, enable business navigation in the current shell, reconcile page data contracts and missing QR dependencies. Run all access and domain utility tests, lint, typecheck, production build.
4. Tests: migrate domain feature suites from scaffold to the current API/session contract, retain historical superseded contracts with a coverage map, verify the full unit/feature suite. Root adds real login-to-client CRUD and tenancy regression coverage.
5. Integration: run PostgreSQL/Redis concurrency suite using isolated opmoni_test, verify migrations against PostgreSQL, apply additive development migrations preserving users/data, synchronize container dependencies, and smoke test the browser modules.
6. Independent review: inspect account isolation, background context, session/CSRF handling, multipart/XML downloads, stale account state, membership mutations, and activated routes. Fix significant findings with regression tests and rerun affected checks. Record final verification in the import manifests.

## Interface preflight

- Backend APIs -> frontend: `/api/v1` prefix, current domain resource payloads, standard Opmoni access errors. Authentication remains Identity; platform account provisioning retains its existing response.
- Backend tenant middleware -> frontend adapter: selected account comes from the session; X-Account-Id only asserts the displayed account. ResolveAccount precedes route binding and never grants tenant writes merely for being super admin.
- Queue/console -> domain scopes: context must be explicit and reset; null context never exposes all tenants. Global administrative queries must be explicit and scoped to their task.
- Domain tests -> production: adapt old fixtures to the current contract; no production compatibility hacks for obsolete test assumptions.

## Verification

Backend full PHPUnit and isolated PostgreSQL/Redis integration; frontend access and domain test command, ESLint, Nuxt typecheck, Nuxt build; Docker configuration, dependency synchronization, migration status and browser module smoke checks.

## Progress

- Baseline captured and private source backup created at /tmp/opmoni-before-integration-20261013.tar.gz.
- Backend runtime integration complete: canonical /api/v1 session group, membership checks, closed tenant scopes, explicit background contexts, policies/storage/schedules, commercial platform data, and additive business migrations.
- Frontend integration complete: current access screens and theme preserved; business pages use selected-account assertions and CSRF, with SSR-safe headers, account-aware permissions, multipart/blob support, and live portfolio counters.
- Activated 112 imported business suites; seven superseded authentication/provisioning/impersonation contracts remain historical with replacement coverage mappings.
- PostgreSQL/Redis integration passed: 19 tests, 389 assertions in isolated opmoni_test and Redis databases 13-15. Fiscal writer PostgreSQL rerun passed after the last context fix: three tests, seven assertions.
- Development migrations applied; Docker backend/frontend healthy, queue and scheduler running. Source and development database backups retained privately in /tmp. Existing user rows preserved byte-for-byte after disposable browser fixture cleanup.
- Desktop/mobile browser checks passed: real login, client creation/deletion, counters, platform commercial update, and initial SSR loads for Fiscal, Work and Team.
- Independent review complete; both important background-context findings fixed and covered, with no remaining important findings.
- Frontend test/lint/typecheck and final production build passed: 524 tests; Nitro output 23.6 MB. Complete backend regression run passed: 1,653 tests, 9,128 assertions, zero failures/errors; three optional external SERPRO probes skipped. All six integration tasks are complete.
