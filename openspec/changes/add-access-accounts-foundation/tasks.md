> Status: superseded architecture. This pending proposal targets the earlier Nuxt-only backend decision (D005). The user subsequently chose a Laravel 13 backend in `backend/` (D006). Preserve this proposal as reference; do not apply its implementation tasks until the proposal, design, specs, and tasks have been revised and reviewed for D006. See `docs/architecture/product-decisions.md`.

## 1. Review and freeze the access foundation

- [ ] 1.1 Review the proposed legacy-preserved behaviors and deviations with the user; verify accepted decisions are recorded in `docs/architecture/product-decisions.md` and reflected consistently in proposal, specs, and design.
- [ ] 1.2 Review one page at a time: login, onboarding, first access, unavailable Account, Account switcher/home, and platform Accounts; verify each page's fields, actions, failure states, and acceptance are recorded before implementation.
- [ ] 1.3 Confirm the PostgreSQL/session implementation design and explicit deferral of additional-member management/support; verify the reviewed contracts have no unresolved behavior-changing decisions.

## 2. Persistence and shared contracts

- [ ] 2.1 Verify installed Node/Nuxt/H3 versions, select compatible `pg` and test tooling using primary documentation, and add dependencies with pnpm; verify clean installation, lint, and typecheck.
- [ ] 2.2 Add development/test PostgreSQL configuration, server-only database pool, and an explicit migration runner; verify both databases initialize separately and no application request runs migrations.
- [ ] 2.3 Add migrations for users, Accounts, memberships, sessions, owner activations, rate-limit buckets and migration history; verify uniqueness/FKs/enums with PostgreSQL integration tests and migration re-run idempotency.
- [ ] 2.4 Define shared access DTOs, Zod input schemas, safe projections and the common error envelope; verify validation tests cover normalization, password limits, allowed fields, size enum and absence of credentials in responses.

## 3. Session and security primitives

- [ ] 3.1 Implement versioned asynchronous password hashing, constant-time verification and bounded hashing concurrency; verify password round trips, incorrect credentials, unique salts, and benchmarked memory/latency on the selected runtime.
- [ ] 3.2 Implement anonymous/authenticated database sessions, 12-hour authenticated expiry, rotation and revocation; verify cookie attributes, fixation prevention, expiry and credential replay after logout through API tests.
- [ ] 3.3 Implement `/api/csrf`, mutation Origin/CSRF checks and same-origin access configuration; verify missing/invalid tokens and hostile origins cannot mutate data, including login and owner activation.
- [ ] 3.4 Implement shared rate-limit buckets and bounded cleanup of expired transient records; verify multiple application instances enforce one IP/email budget and return 429 with Retry-After at the specified thresholds.

## 4. Access and owner activation APIs

- [ ] 4.1 Implement registration availability and transactional one-time bootstrap with persisted team size and no subscription/department side effects; verify empty/already-initialized cases and two independent PostgreSQL requests create exactly one initial Account/Super Admin.
- [ ] 4.2 Implement login, `/api/me` and logout with safe no-store representations; verify generic credentials errors, membership-based initial selection, suspended/no-membership identity access and server-confirmed logout behavior.
- [ ] 4.3 Implement platform Account creation and hashed seven-day owner activation; verify atomic Account/link creation, existing/reserved email conflicts and that only issuance responses contain the raw URL.
- [ ] 4.4 Implement first-access status and transactional acceptance; verify valid/expired/revoked/suspended/replayed links, email conflict preservation and independent-process concurrent acceptance creates one owner/membership only.
- [ ] 4.5 Implement activation reissue and link invalidation for active Accounts without members; verify old links fail, existing owners receive 409 and suspension prevents issuance/activation.

## 5. Account authorization and platform lifecycle

- [ ] 5.1 Implement per-request session/Account/membership guards and separate Super Admin platform authorization; verify two concurrent Account contexts remain isolated and missing context or foreign membership never broadens queries.
- [ ] 5.2 Implement safe tenant repository boundaries, role guards and displayed-Account assertions; verify `user` write denial, foreign IDs/payloads, missing assertion, and stale-tab 409 using test fixtures without adding production-only test endpoints.
- [ ] 5.3 Implement session-local Account switching; verify own active Accounts succeed, foreign/nonexistent/suspended Accounts fail without changing selection, and independent authenticated sessions retain distinct selections.
- [ ] 5.4 Implement platform Account search/filter/pagination and atomic name/status updates; verify ordinary admin denial, literal search, unknown ID 404 and combined rename+suspend changes both fields.
- [ ] 5.5 Coordinate switching, activation and suspension with the specified transaction lock order; verify a committed suspension blocks subsequent tenant actions while identity/logout/platform administration remain available.

## 6. Access pages and authenticated shell

- [ ] 6.1 Add request-aware authentication state/API helpers and SSR route guards; verify direct protected navigation redirects before rendering, availability failure falls back to login, and public `/api/me` checks do not cause redirect loops.
- [ ] 6.2 Build the reviewed login page and three-step onboarding; verify field validation, password-free review, loading/duplicate-submit prevention, rate-limit/service errors, bootstrap race feedback and successful navigation in browser checks.
- [ ] 6.3 Build the reviewed first-access page with no-referrer/no-store behavior and safe URL handling; verify loading, valid/invalid links, retryable service failure, matching passwords, and no third-party/query logging of activation credentials.
- [ ] 6.4 Build the unavailable-Account page and minimal authenticated home; verify suspended/no-membership states provide switching/logout where appropriate, display no template metrics and expose no unimplemented business links.
- [ ] 6.5 Replace the template switcher/logout flows with reviewed Account-aware behavior; verify full state invalidation, stale-response suppression, focus revalidation across shared-session tabs and independent-session selection.
- [ ] 6.6 Build the reviewed minimal platform Accounts page; verify filters/pagination, create/rename/status confirmations, one-time link copy/reissue and distinct loading/error/empty states for Super Admin only.

## 7. Integrated verification and documentation

- [ ] 7.1 Run lint, typecheck, unit/API tests and production build; verify every new access endpoint contract and that application credentials remain server-only in output artifacts.
- [ ] 7.2 Run independent PostgreSQL concurrency cases for bootstrap, activation, email reservations, Account switching/suspension and multi-instance limits; verify durable outcomes, not just mocked interleavings.
- [ ] 7.3 Run browser acceptance for each reviewed access page, including reload/SSR, two tabs, two sessions and service failure; verify no protected data survives Account switch/logout and no Account-unavailable redirect loop exists.
- [ ] 7.4 Document local setup, explicit migrations, test commands and known limitations; verify instructions work against a fresh dedicated database, all required checks have recorded results, and legacy/fiscal-service source files were not changed.
