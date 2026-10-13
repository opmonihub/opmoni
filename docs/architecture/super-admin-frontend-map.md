# Super Admin frontend portability map

Date: 2026-10-12.

## Intent and scope

The user requested the same Super Admin navigation and pages as the legacy application, and a complete page inventory before copying the frontend. Three agents independently inspected page behavior, shared frontend dependencies, and the current application. This document records source inspection; it does not claim browser validation or implemented functionality.

Source: `/home/obsidian/dev/legacy`. Destination: `/home/obsidian/dev/opmoni`.

The target navigation is a Super Admin group containing Resumo, Contas, Planos, Assinaturas, Usuários, Suporte, and Serpro, in that order. The group is visible only to a platform Super Admin. This frontend inventory does not implement the missing commercial, support, or provider subsystems.

## Route inventory

| Label | Route | Legacy source | Current destination status |
| --- | --- | --- | --- |
| Resumo | `/admin` | `frontend/app/pages/admin/index.vue` | Page and summary APIs missing |
| Contas | `/admin/contas` | `frontend/app/pages/admin/contas.vue` | Page and foundational Account APIs exist; presentation differs |
| Planos | `/admin/planos` | `frontend/app/pages/admin/planos.vue` | Page, catalog model, and APIs missing |
| Assinaturas | `/admin/assinaturas` | `frontend/app/pages/admin/assinaturas.vue` | Page, subscription model, and APIs missing |
| Usuários | `/admin/usuarios` | `frontend/app/pages/admin/usuarios.vue` | Global user-list page and API missing |
| Suporte | `/admin/suporte` | `frontend/app/pages/admin/suporte.vue` | Page, support access, and audit APIs missing |
| Serpro | `/admin/serpro` | `frontend/app/pages/admin/serpro.vue` | Page, credential storage, and provider APIs missing |

## Page behavior and API contracts

All source paths in this section are relative to `/home/obsidian/dev/legacy/frontend/app`. Legacy endpoints are relative to its `/api` transport. They are reference contracts, not routes currently available in Opmoni; new application endpoints must use the current `/api/v1` ingress and session/CSRF transport.

### Resumo

Source: `pages/admin/index.vue:25`, `:40`, `:47`, `:89`, `:127`.

- Four linked metric cards: Account count, plan count, subscription count, and user count.
- Recent support table shows five events: date, Super Admin, action, and Account. Column-visibility menu; no filters, pagination, or write actions.
- Action badges: Entrada/info, Saída/neutral, Criação/success, Edição/warning, Exclusão/error.
- Parallel GETs: `/admin/accounts?page=1`, `/admin/users?page=1`, `/admin/subscriptions?page=1`, `/admin/plans`, and `/admin/support/logs?page=1`. Counts come from pagination totals; the plan count comes from the returned array. There is no dedicated summary endpoint.
- Initial failure replaces the entire summary with retry rather than showing false zero counts. Refresh failure preserves the previous result. Empty audit copy: “Nenhum acesso de suporte registrado ainda.”

### Contas

Source: `pages/admin/contas.vue:59`, `:101`, `:189`, `:217`, `:315`, `:377`, `:451`, `:557`, `:665`, `:781`.

- Search by name/ID; active/suspended/all filter; column-visibility menu; 15-row server pagination.
- Columns: ID, Account, status, plan, member count, and actions. The plan requires `subscription.plan.name`, which the current Account resource does not expose.
- Row actions: edit, enter support, and suspend/reactivate. Status changes have a confirmation modal.
- Create modal includes Account name, owner name, login email, access mode, optional initial password, phone, WhatsApp flag, state, and city. The legacy permits no owner access, manual password assignment, or first-access links. These are legacy behaviors to adapt, not instructions to replace the current activation contract.
- Edit modal fetches details and updates contact fields; owner/access controls appear only when the Account has no members. Newly issued links keep the modal open for copying.
- GET `/admin/accounts` with `page,q,status`; POST `/admin/accounts`; GET and PATCH `/admin/accounts/{id}`. Detail expects `{data: AdminAccount}`.
- Current supported operations remain GET/POST/PATCH `/api/v1/admin/accounts` and POST `/api/v1/admin/accounts/{id}/first-access`. Current creation requires an owner; it does not accept contact/billing fields or manual passwords.
- Support entry uses either Account switching for a membership or an audited support endpoint for foreign Accounts. Only membership-based switching exists in the destination.
- Loading, initial retry, refresh toast, empty results, modal detail loading, submit busy states, and clipboard feedback must be preserved.

### Planos

Source: `pages/admin/planos.vue:25`, `:43`, `:50`, `:90`, `:109`, `:128`, `:254`.

- Local search by plan name/slug; column-visibility menu; no pagination.
- Columns: plan name/slug, user limit, client limit, monitoring limit, and actions. Missing/null limits display “Ilimitado”.
- Only write action: “Editar limites”; no creation or deletion.
- Slideover contains name and three number inputs. Limits are nullable nonnegative integers; empty means unlimited. Cancel/save with busy feedback. Legacy payload omits null entries from `limits`; validate how a future API clears an existing limit before copying that serialization.
- GET `/admin/plans`; PUT `/admin/plans/{id}` with `{name,limits}`. Current backend has neither route nor catalog model.
- Empty copy: “Nenhum plano encontrado.” Footer compares filtered results with total plans. Initial retry and refresh preservation follow the shared pattern.

### Assinaturas

Source: `pages/admin/assinaturas.vue:35`, `:55`, `:95`, `:133`, `:153`, `:164`, `:185`, `:275`.

- Search by Account/plan; active/past_due/canceled/all status filter; column-visibility menu; 15-row server pagination.
- Columns: ID, Account, plan, status, actions. Status badges: Ativa/success, Inadimplente/warning, Cancelada/neutral.
- Edit slideover contains plan and status selectors. No separate creation/deletion flow.
- GET `/admin/subscriptions` with `page,q,status`, plus GET `/admin/plans` for the plan selector; PUT `/admin/subscriptions/{id}` with `{plan_id,status}`.
- Adjust an out-of-range page after list refresh. Loading, retry, refresh preservation, footer count, and “Nenhuma assinatura encontrada.” are explicit states.
- Current backend has no subscription routes/model. Do not present a save as successful until that contract exists.

### Usuários

Source: `pages/admin/usuarios.vue:42`, `:60`, `:90`, `:129`, `:173`, `:204`.

- Global identity catalog, read-only. Search by name/email; all/Super Admin/ordinary user filter; column-visibility menu; 15-row server pagination.
- Columns: ID, name with email below, identity type, Account memberships. Membership badges show Account name and role: Admin, Operador, Usuário. No membership displays “—”.
- GET `/admin/users` with `page,q,type`; each row includes `is_super_admin` and `account_links[]` containing role and Account metadata.
- No user creation, editing, deletion, promotion, or row actions in the legacy page.
- Empty copy: “Nenhum usuário encontrado.” Loading, retry, refresh preservation, and footer count use shared list behavior.
- User models exist in the destination, but the global listing resource/API does not.

### Suporte

Source: `pages/admin/suporte.vue:49`, `:70`, `:75`, `:94`, `:120`, `:166`, `:207`, `:237`, `:313`.

- Two tabs: Entrar and Auditoria.
- Entrar has name/ID search, search button, Account results with ID/status, and support-entry action. The initial state provides search instructions.
- Numeric search uses GET `/admin/accounts/{id}`. Name search downloads all Account pages and filters locally. Prefer the current server-side `q` search when adapting this interaction.
- Entry refreshes identity and reloads the application at `/` to clear state from the previous Account.
- Auditoria has positive Account-ID filtering; columns date, Super Admin, Account, action, IP; column menu and 15-row pagination. GET `/admin/support/logs` with `page,account_id`; empty copy: “Nenhum evento encontrado.”
- Entry and exit APIs are POST `/support/accounts/{id}/enter` and POST `/support/exit`. A support banner names the current Account, explains that actions are audited, and offers exit.
- Current `switchAccount()` is membership-only. A support UI requires a dedicated server-authorized support session and audited operations before entry is enabled.

### Serpro

Source: `pages/admin/serpro.vue:26`, `:54`, `:119`, `:136`, `:194`, `:213`, `:307`, `:349`, `:416`; `composables/useSerpro.ts:190`.

- Single platform credential, outside tenant-specific data. No table, filters, pagination, modal, or synchronization action.
- Configured state shows key hint, contracting document, certificate subject/serial/validity, configured badge, and “Atualizar credencial”. Never populate fields with stored secrets.
- Unconfigured state shows instructions and the credential form. Legacy GET 404 becomes unconfigured state; any destination contract must distinguish an intentionally unconfigured credential from an unimplemented endpoint.
- Form: required consumer key, optional replacement consumer secret, certificate source choice, `.pfx/.p12` upload, and certificate password. The Account-certificate option assumes the legacy's Account 1 certificate storage, which is absent in the destination.
- GET `/serpro/connection` returns `{data: SerproConnectionMetadata}`. PUT `/serpro/connection` sends FormData with key and provided secret/certificate/password or `use_account_certificate: '1'`. Do not manually set multipart Content-Type. Omitted replacement secrets preserve stored values; form secrets are cleared after save/cancel.
- POST `/serpro/connectivity` returns `{data: {ok,failed_element,message,checked_at}}`. The button tests authentication only; result shows status, failed element, message, and time. Saving clears an obsolete test result.
- Preserve skeleton loading, retry, busy save/test states, configured/unconfigured distinction, and write-only credential behavior. Provider APIs/storage are absent in the destination.

## Shared frontend inventory

Source paths below are relative to the legacy `frontend/app`. Adapt presentation to the destination's existing Nuxt 4, Nuxt UI 4, VueUse, Zod, Lucide, Public Sans, and green/zinc theme; copying the full legacy layout would import unrelated product modules.

| File | Responsibility | Port treatment |
| --- | --- | --- |
| `utils/adminNav.ts:9` | Ordered routes, sidebar children, tabs, active route, navbar title | Reuse route catalog and helpers; `/admin` must match exactly |
| `pages/admin.vue:4` | Shared protected panel, navbar, tabs, nested page outlet | Adapt as destination parent page; remove current layout special case |
| `utils/sidebarNav.ts:32` | Collapsed parent highlight and folded active-child handling | Port only behavior required by the Admin group |
| `components/data-table/PanelList.vue:29` | Table-page title, description, actions, card, toolbar/body | Reuse; only local import is `panel.ts`, with Nuxt UI `UPageCard` |
| `components/data-table/panel.ts:13` | Table density, footer, pagination, select, action tokens | Reuse compatible tokens |
| `components/data-table/ColumnMenu.vue:8` | Column-visibility dropdown | Include its responsive composable |
| `composables/useClientMediaQuery.ts:8` | SSR-safe media-query handling | Needed by column menu |
| `components/data-table/RowActionsMenu.vue:26` | Accessible per-row action menu | Preserve row-specific accessible name |
| `components/data-table/PanelTableEmpty.vue:13` | Table empty state | Reuse presentation |
| `components/MetricCard.vue:2` | Linked summary count cards | Reuse presentation |
| `components/ErrorRetryAlert.vue:18` | Error explanation and retry | Reuse presentation |
| `composables/useRetryableLoad.ts:50` | Initial failure versus stale successful data | Adapt error handling to `apiFailure` |
| `composables/useApiError.ts:14` | Legacy API status/error extraction | Prefer current `shared/utils/access.ts` semantics |
| `utils/adminListFilters.ts:9` | Pagination envelopes, query filters, valid page, stale-response rejection | Adapt after choosing exact destination envelopes |
| `utils/brazilUfs.ts` | Legacy contact form state options | Needed only if contact fields are later supported |
| `components/SupportBanner.vue:21` | Support context and exit | Requires support session APIs |
| `composables/useSupportMode.ts:4` | Distinguishes memberships from foreign Account support | Requires explicit membership/support identity metadata |
| `types/serpro.ts:240` | Credential metadata and connectivity result | Extract relevant types only |
| `utils/serproConnectivityPresentation.ts:10` | Connectivity labels, color, icon | Extract without Monitoring dependencies |
| `utils/monitoringPresentation.ts:832` | ISO date formatter used by Serpro | Extract formatter; avoid copying unrelated Monitoring helpers |
| `composables/useSerpro.ts:190` | Connection GET/PUT and connectivity POST | Extract only three methods; use current API client |

`utils/pageShell.ts` is not a dependency of the mapped Admin pages or `PanelList.vue` and can be excluded from the minimum copy set.

### Destination file set

Create/adapt the shared `frontend/app/pages/admin.vue`, the six missing route files under `frontend/app/pages/admin/`, and `frontend/app/utils/adminNav.ts`. Adapt the existing `contas.vue` in place. Port the table components/tokens, summary card, retry alert, responsive column-menu helper, and list utilities listed above. Add narrowly scoped support/Serpro types and clients only when their destination contracts are defined.

Modify `frontend/app/layouts/default.vue`, `frontend/app/middleware/access.global.ts`, and `frontend/app/plugins/access-focus.client.ts` to apply navigation and access behavior to the full Admin route family. Keep existing authentication and first-access files as the destination source of truth.

## Navigation and access behavior

- Expanded sidebar: one Super Admin trigger with seven child links. Route changes reopen its group; selecting a child closes mobile navigation.
- Collapsed sidebar: icon with tooltip/popover, active for `/admin` and `/admin/*`. The summary child is active only at `/admin`.
- Group folding in the legacy keeps the active child visible while hiding siblings. Keep this behavior only if the destination adopts the corresponding sidebar helpers.
- Command palette includes all seven destinations only for Super Admin.
- Shared header derives its title and horizontal tabs from the same route catalog.
- Legacy `auth` and `super-admin` page middleware and server-side guards enforce platform access independently. Destination guards must integrate with its current identity/session, including refresh-on-focus.
- Do not copy legacy `useAuth` or `plugins/api.ts`: identity transport, API prefix, CSRF, and response envelopes differ. Both projects use Nuxt frontend and Laravel backend APIs; no Inertia migration is needed.

Sources: legacy `frontend/app/layouts/default.vue:188`, `:205`, `:243`, `:283`, `:298`; `frontend/app/utils/adminNav.ts:19`; `frontend/app/middleware/super-admin.ts:1`; `backend/routes/api.php:296`.

## Legacy inconsistencies to correct during the port

1. Account detail envelope: `pages/admin/contas.vue:377` expects `{data: AdminAccount}`, while `pages/admin/suporte.vue:120` expects a direct Account. Use one destination contract.
2. Support membership: `pages/admin/suporte.vue:94` treats presence in `accounts` as membership, although `AuthAccountLink` supports `is_member: false`. Check actual membership metadata; do not silently switch into foreign Accounts.
3. Missing limits: the plan editor displays null as unlimited but omits null limits when saving. Confirm explicit clearing semantics in the new API.
4. Unconfigured Serpro: a missing implementation returning 404 must not masquerade as a successfully loaded, unconfigured provider.

## Current application boundaries

- `frontend/app/layouts/default.vue:76` has a single conditional platform-account link; replace it with the nested group. The existing Settings group illustrates `children` and mobile-close callbacks.
- `frontend/app/layouts/default.vue:165` wraps only `/admin/contas` in an administrative panel. A shared parent page should replace this special case, avoiding nested dashboard panels.
- `frontend/app/middleware/access.global.ts:30` and `frontend/app/plugins/access-focus.client.ts:24` special-case `/admin/contas`. All administrative routes must preserve platform access without an active office while denying ordinary users platform data.
- `frontend/app/composables/useAuth.ts` and `useAccessApi.ts` already own identity, session, CSRF, and Account switching. Adapt legacy calls to these contracts instead of importing the legacy authentication system.
- `backend/routes/api.php:26` exposes only platform Account list, creation, update, and activation-link reissue. Sample template APIs are not administrative backends.
- `frontend/shared/types/access.ts:16` exposes `id`, `name`, `status`, `members_count`, and `pending_owner`; no plan, billing, commercial-contact, or support fields exist.

## Preserve current Account access behavior

The current Account page must keep its implemented first-access contract when its presentation is ported:

- Creation accepts office name, owner name, and owner email. It issues an activation URL for temporary copying; the platform does not assign the owner's password.
- Reissue is available only for an active Account with no members, invalidates the previous link, and does not modify an existing user's identity.
- Activation URLs are displayed only when newly issued and cleared when the modal closes. They must not be recovered from Account lists.
- Suspension/reactivation requires confirmation. Tenant data and memberships are preserved.
- Super Admin status does not grant membership-free operational Account switching. Support access needs its own backend and audit contract.

Sources: `frontend/app/pages/admin/contas.vue:63`, `backend/app/Http/Controllers/Api/V1/AccessController.php:88`, and the canonical `openspec/specs/accounts/spec.md` (foundation archived under `openspec/changes/archive/2026-10-12-add-access-accounts-foundation/`).

## Recommended port sequence

1. Introduce the shared Admin page shell, route inventory, nested sidebar group, command-palette entries, and route authorization.
2. Port shared table density, column controls, pagination, retry handling, and metadata types.
3. Adapt Contas presentation to the current Account API and preserve owner activation behavior.
4. Port Resumo, Planos, Assinaturas, and Usuários using explicit data contracts. Missing API data must not appear as successful zero totals or a successful empty list.
5. Port Suporte and Serpro interfaces without enabling operations that lack server-side authorization, auditing, or credential handling.
6. Verify direct navigation, sidebar expanded/collapsed/mobile behavior, ordinary-user denial, Super Admin access without an office, loading/failure/empty states, and Account mutations. Run frontend lint, typecheck, access tests, and build when product code is changed.

## Validation of this inventory

This mapping was produced through read-only source inspection. No product code, backend schema, runtime credentials, or existing local changes were modified. Browser behavior and API execution remain to be verified during implementation.
