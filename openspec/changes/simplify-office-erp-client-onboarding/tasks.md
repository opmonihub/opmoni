## 1. Additive domain foundation

- [x] 1.1 Add Company owner-setup state, contract administrative/trial attribution and technical-plan reference, purpose-scoped owner setup, office trial links and durable trial grants using additive migrations; verify legacy rows and trial uniqueness in `OfficeErpCommercial/TrialSchemaTest` on SQLite and PostgreSQL.
- [x] 1.2 Add `trialing`/`trial_expired` subscription representations and strict `erp_subscriptions` validity checks without changing office subscription constraints or enabling trial creation through generic platform updates; verify enum/serializer/platform-filter behavior in `ErpCommercialAccessTest` and `TrialSchemaTest`.

## 2. Core customer administration

- [x] 2.1 Separate office-admin configuration/provisioning capabilities from the legacy retail resale flag while retaining active Account, real membership, active office subscription for new provisioning and explicit licence/module/client limits; verify role, inactive-subscription maintenance, zero/null capacity and cross-office cases in `CommercialAdmissionTest`.
- [x] 2.2 Extract reusable Client preflight/persistence and unclaimed Company provisioning without changing legacy owner signup behavior; verify CNPJ provider failure, same-office reuse, inactive/foreign Client denial and no automatic Company access in `CustomerEnrollmentTest` and existing `ClientCrudTest`/`ErpCommercialAccessTest`.
- [x] 2.3 Implement idempotent direct paid enrollment, plan discovery, exact frozen terms and existing contract/counter/invoice integration without a published offer or owner acceptance; verify actual route rollback and lost-response lookup in `CustomerEnrollmentTest`.
- [x] 2.4 Add optional purpose-scoped verified owner access setup without allocating, activating or billing again; verify existing-email/foreign-owner/consumed-token denial and existing invitation compatibility in `OwnerSetupTest`/`OwnerAcceptanceTest`.

## 3. Office-attributed ERP trial

- [x] 3.1 Implement guarded trial-link read/configure/rotate and safe public availability, keeping release flags and sponsor resolution server-controlled; verify revocation, rotation, stale revision, missing capacity and foreign-office isolation in `TrialLinkTest`.
- [x] 3.2 Implement atomic public owner registration, same-office Client creation/reuse and one 168-hour trial per normalized CNPJ, with CSRF/origin/rate guards and password-free recovery; verify full route behavior and failed pre-commit/session-after-commit cases in `TrialRegistrationTest`.
- [x] 3.3 Enforce live trial deadlines and frozen module grants in HTTP and queued effects, preserve authorized reads, and reconcile expiry without deleting data or inventing debt; verify frozen-clock exact-end and scheduler-delay cases in `TrialLifecycleTest`.
- [x] 3.4 Implement explicit paid conversion on the same Company/subscription/allocation, preserving holds and trial history and issuing one first invoice from confirmed paid terms; verify valid/expired/capacity-denied/replayed cases in `TrialLifecycleTest` and `RecurringInvoiceTest`.

## 4. Customer-first Nuxt flows

- [x] 4.1 Replace invitation-centric customer screens with new-client/portfolio enrollment, technical plan/terms, truthful subscription/trial states and optional owner access; verify real rendered handlers, errors, pagination and context invalidation in `officeErpOnboarding.test.ts`/`officeErpContracts.test.ts`.
- [x] 4.2 Add office trial-link controls, `/erp/testar/[code]`, owner trial details and explicit conversion UI using existing transport/recovery; verify anonymous access, known-email login, validation correction, unknown result/no password replay, refresh failure and access-boundary changes in rendered SFC/transport tests.
- [x] 4.3 Defer Offers from primary navigation, remove duplicate office header text and align settings permissions/feedback with the backend, preserving legacy routes, actual office selection and print isolation; verify navigation/settings tests and keyboard/light/dark layouts at 320px, 390px and 1366px.

## 5. Integrated acceptance and handoff

- [x] 5.1 Prove independent PostgreSQL/Redis races for last licence, same CNPJ across offices, link rotation/registration, duplicate conversion and expiry/conversion in two fresh processes; verify exact outcomes and retained-store repeatability without touching development stores.
- [x] 5.2 Run relevant backend/frontend focused checks followed by regular suite, PostgreSQL profile, frontend lint/typecheck/tests/build and guarded browser signup/conversion/expiry flows; record exact source, counts, failures/skips and unverified boundaries before accepting completion.
- [x] 5.3 Rehearse additive migration/compatible-app recovery on private restored stores and update product decisions, README, reconciliation and scenario evidence; verify preserved financial/identity snapshots, unchanged production gates and strict OpenSpec validation. Follow the detailed [implementation plan](../../../docs/superpowers/plans/2026-10-25-simplify-office-erp-client-onboarding.md).
