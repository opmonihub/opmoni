## Why

The current ERP workspace mixes portfolio registration, subscriptions and responsible-person setup, making ongoing company and team administration difficult. Offices need the reviewed Empresas/Assinaturas flow, including company editing, per-company subscription history, module changes and management of every ERP user's access.

## What Changes

- Expose **Empresas** at `/gestao-erp/empresas` with only registered ERP Companies for the selected office, including Companies awaiting assignment. Provide company inspection/editing, search, authoritative pagination and ordering by name or registration date.
- Make **Novo cliente** offer **Escolher da carteira** and **Cadastrar novo**. The portfolio modal supports inline **+**, multiple checkboxes and select-all for eligible rows on the current filtered page, without registering duplicates or selecting unseen clients.
- Separate registration, assignment and activation. Registration creates or reuses only the selected office's Client and an unclaimed ERP Company with no office licence allocation, commercial contract or invoice. A technical pending subscription, if required internally, grants no operating access and does not appear as an applied commercial subscription.
- Add **Adicionar assinatura** to Company rows without an assignment. Applying explicit plan/price/cycle/start/due terms creates one pending administrative contract and reserves one occupied allocation; activation in its detail issues the first invoice exactly once. Preserve existing paid and trial contracts and the legacy atomic enrollment endpoint.
- Make **Assinaturas** list Companies with an applied subscription and open a Company detail with current terms, linked faturas, contract history, lifecycle controls, module management and **Usuários e acessos**. Keep old customer/registration bookmarks and contract-detail URLs functional.
- Permit descriptive company/contact/address editing while preserving CNPJ, ownership, global identities, issued financial records and fiscal configuration. Define Client-versus-Company field ownership explicitly.
- Permit next-cycle module additions and removals with current and pending grants displayed separately. Additions require current provisioning/module eligibility; removals are bounded maintenance and do not destroy data or rewrite invoices. Non-module operating and fiscal release gates still apply.
- Let a real administrator of the selected active office directly manage ordinary members of its currently managed Companies, without a separate owner approval. This follows the user's explicit decision on 2026-10-28. Add/search members, issue/reissue/revoke invitations, edit Manager/Operator/Viewer roles, action permissions and establishment scope, and remove Company membership while preserving the account elsewhere.
- Preserve the responsible owner, prohibit owner replacement/removal and implicit office self-enrollment through this commercial boundary, and retain Company-owner-only `/erp/members` APIs independently. Commercial administration itself grants no ERP operating access or accounting delegation.
- Permit direct password reset for eligible active Company members, with password confirmation, explicit global-account consequences, secret-free audits, uncertain-outcome recovery and revocation of their previous sessions. Protect platform/office-administrator identities from this commercial direct-reset path; retain email recovery for them and ordinary members.
- Retain optional owner setup and recipient-confirmed first access. Invitations never overwrite existing identity credentials. Add recipient-only, single-use email recovery and member-invitation acceptance pages without opening general public registration.

## Capabilities

### New Capabilities

None; this change extends existing capability contracts.

### Modified Capabilities

- `office-erp-commercial-management`: registered Company portfolios, registration/assignment/activation separation, Company detail/lifecycle, module removal, commercial member-management authority and safe operation recovery.
- `client-portfolio`: selected-client ERP registration, bounded bulk selection and ownership of editable company/contact/address fields, preserving ordinary portfolio access and foreign-resource rules.
- `erp-company-foundation`: unassigned unclaimed Companies, explicit office-commercial member administration, protected ownership, roles/action/establishment grants and recipient-confirmed member invitations.
- `erp-commercial-access`: no commercial activation or billing at registration/assignment, exactly-once activation and next-cycle module amendments without changing trials or ordinary operating gates.
- `auth`: bounded administrative password reset, recipient-controlled email recovery, protected identities and global session invalidation without rewriting membership.
- `platform-commercial-management`: classify member servicing, safe credential recovery and module reductions as bounded maintenance of existing contracts, while keeping new assignment/expansion and ordinary portfolio writes gated.
- `access-pages`: Empresas/Assinaturas navigation, customer picker and editing, multi-user access UI, compatible bookmarks, and public invitation/recovery continuation.

## Impact

- **Frontend:** commercial shell/sidebar/search/overview destinations, Empresa lists and modals, Company subscription detail, lifecycle/module editors, member administration and new public access pages. Reuse installed Nuxt UI, existing table/list-navigation/read/context/command helpers and the private `/api/v1` proxy; no new UI library.
- **Backend:** additive office-commercial Company/registration/assignment/member/invitation/recovery controllers and services; reuse provisioning, membership validation, operation journal, billing, calendars, hashing, password broker and session guards. Never weaken `ErpCompanyAdministration::assertOwner` or global tenant middleware.
- **Persistence:** explicit Account/Client/Company registration links, additive commercial contact fields, member invitations, encrypted access-delivery outbox, an identity authentication revision and action-specific operation handling. Backfill existing relationships without creating identities, resetting data or changing existing grants/contracts.
- **Compatibility:** retain all accepted paid/trial behavior, the existing `customer.enroll` endpoint as an atomic compatibility composition, owner setup/legacy invitation continuations, contract/invoice URLs and lifecycle recovery. Unlinked direct subscriptions are not silently adopted.
- **Decision reconciliation:** narrowly supersede D007's deferred recovery, the 2026-10-28 onboarding decision's mandatory atomic single-step UI for new office registrations, and owner-only administration only through the new bounded commercial route. Existing contracts are not migrated to pending, and D009's no-impersonation/no-implicit-operating-access rule remains.
- **Non-goals:** platform-wide Company discovery; changing CNPJ, owner or global email/name; generic arbitrary-user administration; implicit operating/delegated access; membership suspension as a new status; public forgot-password initiation; payment automation; broader fiscal/provider or release-gate changes.
- **Planning home:** `/home/obsidian/dev/opmoni/.worktrees/manage-office-erp-client-access`, branch `codex/manage-office-erp-client-access`, base `e216b26905e581b10382c76e0e580ae6a328caea`. The original main-checkout draft and standalone preview are preserved. This worktree's planning artifacts are authoritative for this continuation.
- **Review evidence:** the copied standalone preview and `preview-review.md` record the user's visual feedback and prototype checks. They establish the requested interaction, not application authorization, credentials, PostgreSQL concurrency or live email acceptance. Mail transport readiness remains a deployment acceptance item.
