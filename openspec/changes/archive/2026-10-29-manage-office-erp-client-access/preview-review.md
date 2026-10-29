# Company and subscription management preview

Status: historical record of the exploratory visual prototype and user feedback. The completed planning artifacts in this worktree (`proposal.md`, `design.md`, `specs/` and `tasks.md`) now define the proposed implementation; the provisional notes below are retained as review history and are superseded where the final design resolves them. The user explicitly confirmed direct office administration of Company users without additional owner approval. No application implementation or production acceptance is implied.

## Review surface

- Preview: `http://localhost:18762/#empresas`.
- Artifact: `.impeccable/mocks/erp-company-preview/`, served independently from Nuxt and Laravel.
- Companies: registration, inspection and explicit editing of company information, with a **Novo cliente** dropdown offering **Escolher da carteira** and **Cadastrar novo**.
- Portfolio modal: searchable client table, multiple checkboxes, an indeterminate select-all checkbox for eligible clients in the current search, inline **+** addition and bulk addition. Existing ERP registrations are identified and cannot be added again.
- Company row actions: **Ver cadastro** plus **Adicionar assinatura** for Companies without a plan, or **Ver assinatura** when a plan already exists.
- Company inspection: larger modal with registration/tax identity, contact person, email, phone, WhatsApp and structured address. Missing fields remain explicitly unknown; sample contact/address values are demonstrative.
- Subscriptions: one row per Company with an applied subscription; selecting a row opens that Company's subscription detail.
- Subscription detail: current terms, activation, responsible-person access, invoices and history. Access preparation and password recovery are placed here, not in the Companies list. Its management modal edits plan, exact price, interval and due date, and offers renewal, suspension/resumption and cancellation with confirmation/reason.
- **Usuários e acessos:** company member list with name/email, role, active/pending-invitation state, search/status filters, adding a user and a per-member management action. Supports a Company with only the owner or multiple users. Owner setup remains available when unclaimed/pending.
- Member management: protected Owner plus Manager, Operator and Viewer roles, role defaults or a demonstrative subset of explicit action permissions, all/selected establishment scope, direct-password change plus email recovery for active accounts, invitation resend for pending access and confirmed Company-scoped removal. Removing membership preserves the global identity and other Company memberships. The prototype discards password inputs and simulates no credential/session changes.
- Sorting: Companies table headers sort by company name and registration date in either direction. A mobile selector exposes the same four explicit options when the registration column is hidden. Subscriptions retains name sorting and the mobile ordering selector.
- Company editing: **Ver cadastro → Editar cadastro**, with registration/tax fields, contacts and address, save/cancel, required-name/email/CEP validation, a read-only CNPJ and fixed action footer. Changing contact data does not replace the login identity, contract or ownership.
- Modules: **Assinaturas → Company → Gerenciar módulos** in the current-subscription block, also reachable from **Gerenciar assinatura**. Uses the eight supported ERP module keys and separates current grants from a proposed pending selection. Toggles edit the next-cycle draft; submission schedules the preview change and records an event, while existing grants remain unchanged.
- Owner view: a demonstrative first-access screen for choosing and confirming a password. Its password inputs are discarded and no credential is sent or persisted.

## Prototype assumptions recorded during review

1. Company registration can precede plan assignment. The user explicitly requested **Adicionar assinatura** on the Company's row, opening the plan modal with that Company already selected. The Assinaturas list retains a secondary general assignment entry. Saving the assignment opens the subscription detail for activation/access. Current production enrollment atomically provisions Company, subscription and initial billing; separating these stages still requires a revised domain/API contract before implementation.
2. The portfolio picker shows office Clients not yet registered in ERP as selectable, while already registered Clients are labeled and disabled. The user explicitly requested inline addition plus select-all/bulk selection.
3. Companies includes registered ERP Companies without a subscription; Assinaturas includes only Companies with applied subscriptions. Both remain scoped to the selected office.
4. The user explicitly superseded email-only recovery: a real authorized office administrator must be able to set a new password for the current ERP responsible person from subscription details, while retaining email recovery. The old password is never displayed. The real implementation must define this bounded administrative exception to the global identity contract, enforce the Company/contract/Account relationship and current actor authority, protect privileged identities, record attributable secret-free audits and invalidate the target identity's old sessions. A general arbitrary-user password editor remains out of scope.
5. Company editing preserves CNPJ as the identity key; edits affect descriptive data, contacts and address, not ownership, login or subscription. The real implementation must define which portfolio Client and ERP Company projections own those fields and update them through their authorized existing boundaries.
6. Module enabling/disabling is an exploratory follow-up requested during preview review. The current application offers a next-cycle terms form with module selection in `frontend/app/pages/gestao-erp/assinaturas/[id].vue`, but `OfficeErpAdmission::assertModulesAllowed` rejects shrink-only changes by requiring identical grants for `contract.amend.price`. Expansion is authorized separately as `modules.expand`. The preview demonstrates next-cycle enable/disable; real removal support must be explicitly specified, implemented and tested before claiming it exists. Invoice/data preservation and other Company/operating/fiscal release gates remain applicable.
7. The user explicitly requested managing every user's access within the Company's subscription details, superseding the earlier owner-only preview. Existing `/erp/members` administration invokes `ErpCompanyAdministration::assertOwner`; it does not authorize selling-office administrators. The implementation must specify a separate bounded, current-authority office-commercial member-management capability rather than weakening `assertOwner` or granting Company operating access implicitly. Scope includes member lifecycle, invitation/identity establishment, per-member permissions/establishment checks, last-owner protection and privileged/self-escalation restrictions. Any required owner authorization and its revocation semantics must be resolved in the full design before apply; the visual prototype is not evidence that the office can already administer members.
8. The current membership model has roles/permissions/establishment scope and deletion, without a per-membership suspension field. This preview offers active versus pending invitation state and removal; it does not claim an existing suspend-member implementation. Inviting a previously unknown identity is also proposed scope: the current member API associates existing global users. An existing identity's name, email and password must never be overwritten by invitation or association.

## Verification performed

- JavaScript syntax checks passed for the standalone script and local icon resources.
- Browser interaction covered adding an office portfolio client, applying a plan, opening subscription details, preparing first access, visiting the owner password screen, discarding its input and activating a subscription.
- Visual inspection covered desktop and mobile with dark/light themes. The inspected access detail had no document horizontal overflow at widths 1440, 390 and 320 pixels; the 390px portfolio modal fit its viewport.
- Impeccable detector ran once. It reported typography/radius/elevation advisories and a font warning. The font resource was corrected to variable Public Sans; nonstandard radii and combined border/shadow treatment were adjusted. Minor prototype typography differences remain; there is no claim of a clean detector rerun or production accessibility acceptance.
- No application source, dependencies, configuration, credentials, real users, subscriptions, invoices or email delivery were changed by this preview. No production test suite or live provider flow was run.

### Follow-up verification after the user's review

- Passed browser assertions for select-all, partial/indeterminate state, filtered selection, inline addition without closing the modal, bulk addition and duplicate prevention.
- Passed browser assertions for Company-row plan assignment, activation, editing price/plan/period, renewal, suspension/resumption, cancellation without deleting Company data, and sorting by name/date in both directions.
- Passed browser assertions for direct-password confirmation, discarding both sensitive inputs, absence of the entered value from prototype records, and retention of the email action.
- Inspected revised portfolio, company and management modals on desktop/mobile; all three fit the 390px viewport without document or modal horizontal overflow. Company list and direct-password screen fit a 320px viewport. This is prototype evidence, not real application, email, PostgreSQL or session-concurrency acceptance.
- Impeccable detector reran for this revision and still reports typography advisories plus a font-parser warning. Actual loaded font remains local variable Public Sans. No clean detector result is claimed.

### Company editing and module-management follow-up

- Browser assertions passed for entering editing mode, read-only CNPJ, canceling without mutation, saving descriptive/contact/address data, updating the company list and preserving owner and subscription terms.
- Browser assertions passed for all eight module controls, staged addition/removal, preserving current grants, reopening a pending selection, discarding canceled drafts, clearing a pending difference by restoring current selection, and the shortcut inside subscription management.
- The company editor fit a 390px viewport without document/modal horizontal overflow; the module modal fit a 320px viewport. Desktop/mobile visual inspection completed. Both long forms keep their actions available in a fixed footer.
- The Impeccable detector was run for this follow-up and continues to report typography advisories and the same font-parser warning. No real Company update, entitlement mutation, fiscal release, mail or session acceptance was performed.

### Multi-user access follow-up

- Browser assertions passed for four initial member rows, owner-first ordering, role/per-member permission changes, rejection of Viewer write grants, protected owner role/removal, scope preservation and unchanged unrelated members.
- Browser assertions passed for per-user password confirmation, clearing sensitive fields, absence of the entered value from prototype records, retained email recovery, duplicate-member rejection, default read-only invited users and Company-scoped removal preserving the identity.
- Browser assertions passed for name/email search, invitation-status filtering, preserved owner setup/acceptance, a single-owner Company, reuse of an existing identity without replacing its name, and removal from one Company while retaining another Company membership.
- Desktop/mobile visual inspection completed. The user list fit a 320px viewport; the permissions modal fit a 390px viewport without document/modal horizontal overflow. Syntax checks passed; the detector continues to report typography advisories and its existing font-parser warning. There is no claim of live authorization, invitation delivery, credentials/session revocation, PostgreSQL races or production acceptance.

## Runtime

Serve only the prototype directory:

```bash
python3 -m http.server 18762 --bind 127.0.0.1 --directory /home/obsidian/dev/opmoni/.impeccable/mocks/erp-company-preview
```

The preview server is intentionally left available for review. It has no application API integration, and reload resets the local demonstration records. Stop its serving process with Ctrl+C in the owning terminal when the preview is no longer needed; do not terminate unrelated application services.

## Isolated planning continuation

The completed change lives in `/home/obsidian/dev/opmoni/.worktrees/manage-office-erp-client-access` on `codex/manage-office-erp-client-access`, based on `e216b26905e581b10382c76e0e580ae6a328caea`. The main-checkout draft and original preview server were preserved. The preview source was copied byte-for-byte into this worktree before planning consolidation; it was not edited or re-served during this propose continuation. The absolute server command above identifies the original review runtime, not an implementation service in this worktree.
