## 1. Isolated implementation foundation

- [x] 1.1 Reuse `/home/obsidian/dev/opmoni/.worktrees/manage-office-erp-client-access` on `codex/manage-office-erp-client-access`, read its applicable frontend/backend instructions and preserve unrelated changes; verify Git worktree/branch/status and record the actual baseline in the change's acceptance report.
- [x] 1.2 Inventory the existing registration, allocation, activation, revision, membership, invitation, password/session and UI contracts against `design.md`; verify the source/API map and dependency order are recorded before changing application code.
- [x] 1.3 Add the registration-link table and nullable Client contact fields, contract planned-start date and revision supersession marker; verify focused schema/constraint tests and an isolated PostgreSQL forward migration preserve existing IDs, records and defaults.
- [x] 1.4 Add purpose-specific member-invitation and encrypted access-delivery schemas plus User authentication revision; verify hashed/encrypted secret storage, foreign keys, uniqueness and default revision 0 through focused persistence tests.
- [x] 1.5 Implement an insert-only repeatable registration backfill/reporting command; verify an existing paid/trial/historical fixture retains all Company/User/membership/subscription/invoice/allocation fields and a second run creates no duplicates or side effects.

## 2. Authentication revision and shared identity locking

- [x] 2.1 Add revision capture to password verification/authentication and fresh revision checks to every authenticated request, preserving deadlines/logout/CSRF; verify current access-foundation tests plus old-revision 401 and initial legacy-revision compatibility.
- [x] 2.2 Establish one shared normalized-email/User/Company lock order across existing owner-member mutations, invitation acceptance and the new commercial paths without weakening owner authorization; verify focused owner API regressions and an independent-process lock-order scenario.
- [x] 2.3 Cover password-login/reset races, old-session persistence after reset and authentication-store failure with independent PostgreSQL/Redis processes; verify no obsolete password receives a new-revision session and no stale session revives.
- [x] 2.4 Document revision-aware rollout/rollback compatibility; verify the recovery procedure never deploys a revision-ignorant application after credential resets or flushes development sessions.

## 3. Registered Company APIs and profile ownership

- [x] 3.1 Add scoped registered-Company reads and capability projections, resolving current management from the real subscription/contract link; verify foreign-resource 404, historical-only restrictions, stable server sorting/counts and one Company row per registration.
- [x] 3.2 Implement single new/portfolio Client registration using existing preparation/provisioning helpers and no fake identity, commercial contract, allocation or invoice; verify registration-state, active-office/subscription/creation-limit and CNPJ-association route tests.
- [x] 3.3 Implement bounded atomic explicit-ID bulk registration with same-office existing entries reported unchanged; verify mixed invalid/foreign inputs cause no partial registration and concurrent duplicates do not create extra Clients/Companies.
- [x] 3.4 Add profile read/update validation for Client contact/address and Company descriptive/IE projections with immutable CNPJ and expected profile revision; verify atomic save, stale-edit 409, failed-draft field errors and unchanged credentials/owner/fiscal settings.
- [x] 3.5 Keep ordinary Client CRUD/enrollment APIs compatible and isolate the new action classifications/capability identity; verify existing portfolio/enrollment tests and new role/subscription/Account-switch denial cases.

## 4. Subscription assignment, activation and Company detail

- [x] 4.1 Extract reusable registration/assignment/activation stages while retaining `customer.enroll`'s atomic legacy response/effects; verify existing enrollment, owner setup and trial/conversion regression tests remain green.
- [x] 4.2 Implement plan assignment on a registered Company with one existing technical subscription, explicit exact terms/planned start and one occupied allocation; verify assignment creates no invoice or operating entitlement and rejects duplicate/current foreign associations.
- [x] 4.3 Extend activation to use planned service terms and issue the first invoice exactly once, including operation lookup/replay; verify independent-process last-capacity and duplicate-activation tests plus original-anchor/calendar behavior.
- [x] 4.4 Add subscription-Company list/detail and paginated contract history/fatura projections using authoritative current links; verify one Company with several contracts, old contract IDs, legacy Company-less contracts, trial states and historical-only read behavior.
- [x] 4.5 Preserve actual lifecycle guards and support releasing a pending assignment by confirmed cancellation; verify existing renewal/suspension/resumption/cancellation tests, unchanged issued debt/history and allocation accounting.

## 5. Scheduled module and term amendments

- [x] 5.1 Add net-addition versus pure-reduction admission, preserving historical grants and current office module/capacity limits; verify allowed maintenance reductions, rejected unsupported keys and atomic denial of mixed changes without expansion eligibility.
- [x] 5.2 Implement immutable expected-version replacement of pending term/module snapshots and retain superseded revisions; verify concurrent replacement conflict, no lost pending price/interval changes and unchanged current grants/invoices.
- [x] 5.3 Resolve effective module grants at the service boundary for synchronous and queued work independently of scheduler reconciliation; verify removed-module denial at the boundary, data preservation and unchanged role/trial/manual/fiscal gates.

## 6. Direct commercial member administration

- [x] 6.1 Add a current-management office-commercial member capability and selected-Account routes, retaining independent Company-owner API guards; verify direct real-admin access without owner approval and denial for operators, Super Admin without membership, stale/historical/foreign contexts.
- [x] 6.2 Add paginated active-member/pending-invitation search/status reads and actual role/permission/establishment options; verify response discrimination, target IDs, one-owner/many-member Companies and absence of arbitrary global-identity lookup disclosure.
- [x] 6.3 Share role/action/establishment validation and implement ordinary-member updates; verify Viewer write rejection, selected-establishment requirements, unknown/foreign grants and unchanged unrelated identities/memberships.
- [x] 6.4 Implement confirmed Company-scoped member removal with journal/audit recovery; verify global identity/other-Company access survive and Owner demotion/removal plus office-actor/self-enrollment are denied server-side.
- [x] 6.5 Implement seven-day purpose-bound member invitation issue/reissue/revoke with normalized-email uniqueness and safe operation receipts; verify duplicate prevention, prior-token invalidation and no membership before recipient acceptance.
- [x] 6.6 Implement recipient acceptance for existing authenticated and new identities through a bounded continuation; verify preserved existing profile/password/verification, atomic new identity/member/consumption, mismatch/expiry/current-authority denial and no subscription/invoice/office effects.
- [x] 6.7 Cover owner/member add/remove/acceptance and authority-loss races using independent PostgreSQL/Redis processes; verify one membership, protected ownership and no late grant after relationship revocation.

## 7. Credential recovery and secure link delivery

- [x] 7.1 Implement target-derived direct reset with confirmation and protected-identity checks, atomically updating password/auth revision and invalidating recovery tokens; verify allowed ordinary targets and denial for unrelated, pending, self, Super Admin and Account-admin identities.
- [x] 7.2 Keep passwords and password-derived fingerprints out of commercial operation input/outcome, audits, errors/logs and recovery storage; verify lost-response receipt recovery, repeated-key behavior and secret-absence assertions on the real registered routes.
- [x] 7.3 Implement recipient-only broker recovery with 60-minute expiry, replacement and shared actor/IP/normalized-identity throttle; verify foreign-recipient rejection, 429/Retry-After, single-use consumption and direct-reset/revocation invalidation.
- [x] 7.4 Implement encrypted delivery outbox and after-commit dispatch with ID-only queue payloads, current-authority checks and explicit accepted/failed/unknown states; verify transactional rollback, worker claims, pre-send failure, uncertain-send no-retry and same-key no-duplicate email using controlled fakes.
- [x] 7.5 Enforce configured frontend fragment URLs, no bearer returns to office endpoints, no-referrer/no-store and refusal of log/array/fallback-log mail transports; verify raw URL/token absence from SSR, history, app/failed-job logs and ordinary API projections.
- [x] 7.6 Implement public member-invitation/recovery resolve/current/accept-or-complete endpoints through existing ingress/session/CSRF guards; verify anonymous/no-workspace and mismatched-session flows without opening general registration or implicitly logging in another identity.
- [x] 7.7 Verify independent-process concurrent reset/consume/reissue and delivery versus membership revocation; assert exactly one effective reset, no duplicate grant and no old token/session acceptance.

## 8. Production Nuxt UI flows

- [x] 8.1 Update commercial shell/sidebar/search/overview to Empresas and Company-based Assinaturas while preserving old customer, registration, contract and invoice/print routes; verify route/navigation SFC tests and same-authority list return state.
- [x] 8.2 Build the Novo cliente dropdown, searchable paginated portfolio modal with inline plus/bulk/tri-state current-page selection and registration form using actual Nuxt UI APIs; verify rendered interaction tests for filtering, duplicates, invalid batches and unknown-result recovery.
- [x] 8.3 Build Company inspection/editing and target-bound assignment modals with source-owned fields, named errors, conflict feedback and preserved drafts; verify rendered save/cancel/409 tests and no changes to CNPJ, User identity or fiscal settings.
- [x] 8.4 Build Company subscription detail with current/pending terms, real lifecycle controls, modules/faturas/history and legacy contract links; verify rendered stage transitions, activation recovery and accurate next-cycle/pending revision behavior.
- [x] 8.5 Build Usuários e acessos with one/many users, role/status search/pagination, invitation actions and per-member permission/establishment editor; verify rendered owner protection, Viewer restrictions, identity-preserving addition/removal and current-authority invalidation.
- [x] 8.6 Build per-user direct/email recovery and public member-invitation/password receiver pages; verify password clearing, no automatic sensitive resend, logged-in mismatch, login continuation, retryable outages and no token/password serialization/storage.
- [x] 8.7 Use existing read/context/command lifecycles for every new editor and list; verify leave-page/context/session/role-change cancellation, stale result rejection, no duplicate initialization and no full-document reload for ordinary internal navigation.

## 9. Cross-layer acceptance

- [x] 9.1 Run the appropriate focused backend/SFC tests during each change and the final frontend matrix once with `pnpm --dir frontend lint`, `typecheck`, `test` and `build`; verify recorded commands/exit status distinguish typecheck/build/browser evidence from prototype assertions.
- [x] 9.2 Run the regular backend suite from the host repository layout with `cd backend && OPENSSL_CONF=/etc/ssl/openssl.cnf FISCAL_ENVIRONMENT=producao vendor/bin/phpunit`, preserving the disabled ERP registration test profile, then `vendor/bin/pint --dirty --format agent`; verify actual results and record any environment exclusions accurately.
- [x] 9.3 Run the documented PostgreSQL suite and new independent-process cases in this worktree's own guarded runtime using `opmoni_test` and Redis 13–15; verify migration/backfill repeatability, race assertions and no development-store cleanup, wrapper bypass or shared-runtime collisions.
- [x] 9.4 Exercise real application desktop/mobile at 320/390px and desktop widths in both themes, including keyboard/focus, list round trips, bulk registration, pending assignment/activation, company edits, module boundary, one/many users and credential recovery; verify screenshots/network evidence identifies fixture versus real delivery behavior.
- [x] 9.5 Verify migrations on representative isolated existing paid/trial/historical data and compatible-app recovery; compare identity/password hashes, contracts, invoices, trials, allocations, registrations, keys and release gates before/after. Record real email transport/inbox acceptance only if explicitly configured/authorized; otherwise mark it unverified for deployment.

## 10. Documentation and handoff

- [x] 10.1 Reconcile D007's recovery deferral, the onboarding stage decision, direct office member-management authority and backend/route/operational documentation without rewriting canonical specs before the normal sync/archive step; verify README and reconciliation links describe delivered versus pending behavior accurately.
- [x] 10.2 Produce the acceptance report mapping all seven capability deltas to actual checks and residual production/runtime gates, run `openspec validate manage-office-erp-client-access --strict` and review the scoped diff; verify no credentials, unrelated edits, automatic commit/push/merge or unsupported acceptance claims enter the handoff.
