# Super Admin frontend portability map

Legacy inventory date: 2026-10-12. Current destination status: 2026-10-18.

## Current destination status — 2026-10-18

All seven routes in the route inventory below now exist. The current Admin area includes account list/detail and contact editing, summary, plan and subscription administration, read-only global users, membership-only support search with sanitized audit browsing, and SERPRO connection administration. The dedicated summary, commercial catalog, platform certificate vault, source lifecycle, diagnostic and initialization command are implemented. Use the [system reconciliation](system-spec-reconciliation.md) and [runtime/API instructions](../../README.md#platform-administration-and-initialization) for current contracts.

D009 remains controlling: Super Admin does not bypass real Account membership. Foreign support entry/exit, effective support roles and the support banner in the legacy UI are not part of this implementation. The platform certificate vault is separate from each tenant's certificate storage. The current evidence snapshot reports 56 original items as 46 valid and ten retired, with 46/46 valid tasks checked and local implementation acceptance complete; the ten retired originals remain retired. No live SERPRO acceptance is claimed.

The page-by-page behavior below is a record of the legacy source and the destination gaps observed on 2026-10-12. Statements that the destination lacked pages, APIs or data are historical and are superseded by the current status above; legacy-only behavior remains reference material, not a current Opmoni contract.

## Intent and scope

The user requested the same Super Admin navigation and pages as the legacy application, and a complete page inventory before copying the frontend. Three agents independently inspected page behavior, shared frontend dependencies, and the destination. This dated section records that original source inspection; current implementation and acceptance status are stated above.

Source: `/home/obsidian/dev/legacy`. Destination: `/home/obsidian/dev/opmoni`.

The target navigation was a Super Admin group containing Resumo, Contas, Planos, Assinaturas, Usuários, Suporte, and Serpro, in that order. The group is visible only to a platform Super Admin. The subsequent implementation added these pages and supported APIs, subject to the acceptance limits recorded above.

## Route inventory

| Label | Route | Legacy source | Current destination status |
| --- | --- | --- | --- |
| Resumo | `/admin` | `frontend/app/pages/admin/index.vue` | Implemented with `GET /api/v1/admin/summary` |
| Contas | `/admin/contas` | `frontend/app/pages/admin/contas.vue` | Implemented; account list/detail and contact projection are available |
| Planos | `/admin/planos` | `frontend/app/pages/admin/planos.vue` | Implemented; catalog API exists and the UI exposes limit editing |
| Assinaturas | `/admin/assinaturas` | `frontend/app/pages/admin/assinaturas.vue` | Implemented with paginated list and update APIs |
| Usuários | `/admin/usuarios` | `frontend/app/pages/admin/usuarios.vue` | Implemented as a read-only global user list |
| Suporte | `/admin/suporte` | `frontend/app/pages/admin/suporte.vue` | Implemented for real-member search/switch and sanitized audit browsing; foreign entry/exit is retired |
| Serpro | `/admin/serpro` | `frontend/app/pages/admin/serpro.vue` | Implemented with a separate platform vault and bounded diagnostic; no live-provider acceptance is claimed |

## Legacy page behavior and reference contracts

All source paths in this section are relative to `/home/obsidian/dev/legacy/frontend/app`. Legacy endpoints are relative to its `/api` transport. They are reference contracts, not routes currently available in Opmoni; new application endpoints must use the current `/api/v1` ingress and session/CSRF transport.

### Resumo

Source: `pages/admin/index.vue:25`, `:40`, `:47`, `:89`, `:127`.

- Four linked metric cards: Account count, plan count, subscription count, and user count.
- Recent support table shows five events: date, Super Admin, action, and Account. Column-visibility menu; no filters, pagination, or write actions.
- Action badges: Entrada/info, Saída/neutral, Criação/success, Edição/warning, Exclusão/error.
- The legacy page made parallel GETs to `/admin/accounts?page=1`, `/admin/users?page=1`, `/admin/subscriptions?page=1`, `/admin/plans`, and `/admin/support/logs?page=1`. Counts came from pagination totals; the plan count came from the returned array. That legacy composition is replaced in Opmoni by `GET /api/v1/admin/summary`.
- Initial failure replaces the entire summary with retry rather than showing false zero counts. Refresh failure preserves the previous result. Empty audit copy: “Nenhum acesso de suporte registrado ainda.”

### Contas

Source: `pages/admin/contas.vue:59`, `:101`, `:189`, `:217`, `:315`, `:377`, `:451`, `:557`, `:665`, `:781`.

- Search by name/ID; active/suspended/all filter; column-visibility menu; 15-row server pagination.
- Columns: ID, Account, status, plan, member count, and actions. The current Opmoni platform projection now includes a safe subscription/plan summary.
- Row actions: edit, enter support, and suspend/reactivate. Status changes have a confirmation modal.
- Create modal includes Account name, owner name, login email, access mode, optional initial password, phone, WhatsApp flag, state, and city. The legacy permits no owner access, manual password assignment, or first-access links. These are legacy behaviors to adapt, not instructions to replace the current activation contract.
- Edit modal fetches details and updates contact fields; owner/access controls appear only when the Account has no members. Newly issued links keep the modal open for copying.
- GET `/admin/accounts` with `page,q,status`; POST `/admin/accounts`; GET and PATCH `/admin/accounts/{id}`. Detail expects `{data: AdminAccount}`.
- Current supported operations include GET/POST `/api/v1/admin/accounts`, GET/PATCH `/api/v1/admin/accounts/{id}`, GET/PATCH `/api/v1/admin/accounts/{id}/commercial`, and POST `/api/v1/admin/accounts/{id}/first-access`. Creation requires an owner and returns a temporary one-use link; the API does not assign the owner's password. Contact fields are supported through the bounded platform contact projection.
- The legacy offered foreign support entry. Opmoni only allows ordinary Account switching for a real active membership, consistent with D009.
- Loading, initial retry, refresh toast, empty results, modal detail loading, submit busy states, and clipboard feedback must be preserved.

### Planos

Source: `pages/admin/planos.vue:25`, `:43`, `:50`, `:90`, `:109`, `:128`, `:254`.

- Local search by plan name/slug; column-visibility menu; no pagination.
- Columns: plan name/slug, user limit, client limit, monitoring limit, and actions. Missing/null limits display “Ilimitado”.
- Only write action in the Admin UI: “Editar limites”; no create/delete controls.
- Slideover contains name and three number inputs. Current update validation accepts a 2–255 character name and only `users`, `clients`, and `monitorings` as nullable nonnegative integer limits. A supplied sparse limits object sets omitted known keys to null; slug and unknown fields are rejected.
- Current API provides GET/POST `/api/v1/admin/plans`, GET `/api/v1/admin/plans/{id}`, and PUT/PATCH `/api/v1/admin/plans/{id}`; there is no delete route. The separate POST API can create plans even though the UI exposes editing only.
- Empty copy: “Nenhum plano encontrado.” Footer compares filtered results with total plans. Initial retry and refresh preservation follow the shared pattern.

### Assinaturas

Source: `pages/admin/assinaturas.vue:35`, `:55`, `:95`, `:133`, `:153`, `:164`, `:185`, `:275`.

- Search by Account/plan; active/past_due/canceled/all status filter; column-visibility menu; 15-row server pagination.
- Columns: ID, Account, plan, status, actions. Status badges: Ativa/success, Inadimplente/warning, Cancelada/neutral.
- Edit slideover contains plan and status selectors. No separate creation/deletion flow.
- GET `/api/v1/admin/subscriptions` with `page,q,status`, GET `/api/v1/admin/plans` for the plan selector, and PUT/PATCH `/api/v1/admin/subscriptions/{id}` with `{plan_id,status}`. List resources use `meta.current_page`; only active, past_due and canceled statuses are accepted.
- Adjust an out-of-range page after list refresh. Loading, retry, refresh preservation, footer count, and “Nenhuma assinatura encontrada.” are explicit states.
- The subscription API and model are implemented. Updates require a real plan and valid status, preserve Account status/memberships, and do not call a payment provider.

### Usuários

Source: `pages/admin/usuarios.vue:42`, `:60`, `:90`, `:129`, `:173`, `:204`.

- Global identity catalog, read-only. Search by name/email; all/Super Admin/ordinary user filter; column-visibility menu; 15-row server pagination.
- Columns: ID, name with email below, identity type, Account memberships. Membership badges show Account name and role: Admin, Operador, Usuário. No membership displays “—”.
- GET `/admin/users` with `page,q,type`; each row includes `is_super_admin` and `account_links[]` containing role and Account metadata.
- No user creation, editing, deletion, promotion, or row actions in the legacy page.
- Empty copy: “Nenhum usuário encontrado.” Loading, retry, refresh preservation, and footer count use shared list behavior.
- The current global listing API returns allowlisted identity fields and actual Account-role links; it has no user mutation endpoints.

### Suporte

Source: `pages/admin/suporte.vue:49`, `:70`, `:75`, `:94`, `:120`, `:166`, `:207`, `:237`, `:313`.

- Two tabs: Entrar and Auditoria.
- Entrar has name/ID search, search button, Account results with ID/status, and support-entry action. The initial state provides search instructions.
- Numeric search uses GET `/admin/accounts/{id}`. Name search downloads all Account pages and filters locally. Prefer the current server-side `q` search when adapting this interaction.
- Entry refreshes identity and reloads the application at `/` to clear state from the previous Account.
- Auditoria has positive Account-ID filtering; columns date, Super Admin, Account, action, IP; column menu and 15-row pagination. GET `/admin/support/logs` with `page,account_id`; empty copy: “Nenhum evento encontrado.”
- The legacy entry/exit APIs and support banner are not implemented in Opmoni. Current `switchAccount()` is membership-only; support audit browsing is read-only and does not claim an active foreign support lifecycle.

### Serpro

Source: `pages/admin/serpro.vue:26`, `:54`, `:119`, `:136`, `:194`, `:213`, `:307`, `:349`, `:416`; `composables/useSerpro.ts:190`.

- Single platform credential, outside tenant-specific data. No table, filters, pagination, modal, or synchronization action.
- Configured state shows key hint, contracting document, certificate subject/serial/validity, configured badge, and “Atualizar credencial”. Never populate fields with stored secrets.
- Unconfigured state shows instructions and the credential form. Current unconfigured connection reads return successful metadata; a failed load remains an error.
- Form: consumer key, optional replacement consumer secret, certificate source choice, `.pfx/.p12` upload, and certificate password. The current UI offers direct upload or an active Account's separate platform contracting-certificate vault; it does not automatically copy a tenant certificate.
- GET `/api/v1/serpro/connection` returns `{data: SerproConnectionMetadata}`. PUT `/api/v1/serpro/connection` retains the public PUT API; the browser uses POST multipart with `_method=PUT` through Nuxt. Omitted or blank key/secret replacement fields preserve saved values, and an explicitly empty password remains distinct from omission. Secrets are write-only and cleared from the form after save/cancel.
- POST `/serpro/connectivity` returns `{data: {ok,failed_element,message,checked_at}}`. The button tests authentication only; result shows status, failed element, message, and time. Saving clears an obsolete test result.
- Preserve skeleton loading, retry, busy save/test states, configured/unconfigured distinction, and write-only credential behavior. A bounded diagnostic is implemented; live SERPRO acceptance is not claimed.

## Legacy shared frontend inventory — 2026-10-12

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

### Historical destination file set proposal

The 2026-10-12 proposal was to create/adapt the shared `frontend/app/pages/admin.vue`, six missing route files, and `frontend/app/utils/adminNav.ts`, then port the mapped components and types. The current implementation has since added the shared shell and all seven route pages. This file set is retained as planning history, not a current implementation checklist.

Modify `frontend/app/layouts/default.vue`, `frontend/app/middleware/access.global.ts`, and `frontend/app/plugins/access-focus.client.ts` to apply navigation and access behavior to the full Admin route family. Keep existing authentication and first-access files as the destination source of truth.

## Navigation and access behavior

- Expanded sidebar: one Super Admin trigger with seven child links. Route changes reopen its group; selecting a child closes mobile navigation.
- Collapsed sidebar: icon with tooltip/popover, active for `/admin` and `/admin/*`. The summary child is active only at `/admin`.
- Group folding in the legacy keeps the active child visible while hiding siblings. Keep this behavior only if the destination adopts the corresponding sidebar helpers.
- Command palette includes all seven destinations only for Super Admin.
- Shared header derives its title and horizontal tabs from the same route catalog.
- The legacy used `auth` and `super-admin` middleware. Opmoni now integrates platform route authorization and focus refresh with its existing identity/session contract; post-fix task 10.4 now verifies live privilege revocation, ordinary denial, no-office Admin and selected-own suspended Account behavior.
- Do not copy legacy `useAuth` or `plugins/api.ts`: identity transport, API prefix, CSRF, and response envelopes differ. Both projects use Nuxt frontend and Laravel backend APIs; no Inertia migration is needed.

Sources: legacy `frontend/app/layouts/default.vue:188`, `:205`, `:243`, `:283`, `:298`; `frontend/app/utils/adminNav.ts:19`; `frontend/app/middleware/super-admin.ts:1`; `backend/routes/api.php:296`.

## Resolved legacy contract differences

1. Account detail uses the same `{data}` envelope as the Account list; the summary and commercial metadata also use `{data}`.
2. Support entry is enabled only through an active real membership. Foreign support elevation remains superseded by D009.
3. Plan updates serialize the three supported limit keys; null and omitted keys in a supplied replacement `limits` object mean unlimited.
4. An unconfigured SERPRO connection returns successful metadata, while a failed read remains an error.

## Historical destination boundaries — 2026-10-12 (superseded)

The following bullets describe the destination when the legacy inventory was written. They are preserved to explain the original port decisions; they are not current instructions.

- `frontend/app/layouts/default.vue:76` has a single conditional platform-account link; replace it with the nested group. The existing Settings group illustrates `children` and mobile-close callbacks.
- `frontend/app/layouts/default.vue:165` wraps only `/admin/contas` in an administrative panel. A shared parent page should replace this special case, avoiding nested dashboard panels.
- `frontend/app/middleware/access.global.ts:30` and `frontend/app/plugins/access-focus.client.ts:24` special-case `/admin/contas`. All administrative routes must preserve platform access without an active office while denying ordinary users platform data.
- `frontend/app/composables/useAuth.ts` and `useAccessApi.ts` already own identity, session, CSRF, and Account switching. Adapt legacy calls to these contracts instead of importing the legacy authentication system.
- `backend/routes/api.php:26` exposes only platform Account list, creation, update, and activation-link reissue. Sample template APIs are not administrative backends.
- `frontend/shared/types/access.ts:16` exposes `id`, `name`, `status`, `members_count`, and `pending_owner`; no plan, billing, commercial-contact, or support fields exist.

## Current Account access contract

The Account page implements this first-access contract:

- Creation accepts office name, owner name, and owner email. It issues an activation URL for temporary copying; the platform does not assign the owner's password.
- Reissue is available only for an active Account with no members, invalidates the previous link, and does not modify an existing user's identity.
- Activation URLs are displayed only when newly issued and cleared when the modal closes. They must not be recovered from Account lists.
- Suspension/reactivation requires confirmation. Tenant data and memberships are preserved.
- Super Admin status does not grant membership-free operational Account switching. Foreign support entry/exit is retired; the current Support page provides member search and read-only audit browsing.

Sources: `frontend/app/pages/admin/contas.vue:63`, `backend/app/Http/Controllers/Api/V1/AccessController.php:88`, and the canonical `openspec/specs/accounts/spec.md` (foundation archived under `openspec/changes/archive/2026-10-12-add-access-accounts-foundation/`).

## Historical port sequence — 2026-10-12

1. Introduce the shared Admin page shell, route inventory, nested sidebar group, command-palette entries, and route authorization.
2. Port shared table density, column controls, pagination, retry handling, and metadata types.
3. Adapt Contas presentation to the current Account API and preserve owner activation behavior.
4. Port Resumo, Planos, Assinaturas, and Usuários using explicit data contracts. Missing API data must not appear as successful zero totals or a successful empty list.
5. Port Suporte and Serpro interfaces without enabling operations that lack server-side authorization, auditing, or credential handling.
6. Verify direct navigation, sidebar expanded/collapsed/mobile behavior, ordinary-user denial, Super Admin access without an office, loading/failure/empty states, and Account mutations. Run frontend lint, typecheck, access tests, and build when product code is changed.

## Validation and acceptance status

The 2026-10-12 inventory was produced by read-only source inspection. It predates the current implementation. The 2026-10-18 evidence snapshot records 46/46 valid tasks checked, with final backend/PostgreSQL and frontend checks, complete browser acceptance, documentation reconciliation and approved independent source review. See [the platform acceptance section](system-spec-reconciliation.md#current-platform-acceptance-2026-10-18); the current task record provides the final local evidence and its live-provider limits.
