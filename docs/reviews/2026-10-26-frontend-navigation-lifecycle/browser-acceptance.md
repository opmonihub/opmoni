# Frontend navigation lifecycle browser acceptance

Browser acceptance completed on final product source `3948e62`, using the source-bound production artifacts below. Coverage is representative and explicitly separates real backend outcomes from injected technical variants.

## Runtime and source binding

QA owns `codex/navigation-browser-qa` and the two report files. Product source and private runtime are separately owned. The named browser session is `navigation-browser-qa` on `http://localhost:3354`.

Backend mount, exclusive PostgreSQL/Redis volumes, `opmoni_test`, Redis 13/14/15 and exact frontend origin were independently verified before additive fixtures. Fixtures contain real office memberships, 30 portfolio clients, two Work templates, direct Company ownership, a separate delegated Company with read grants, an empty office, certificate metadata, tax profiles and synthetic commercial records. The effective commercial fixture was completed with an occupied licence and linked subscription before its successful detail/print acceptance. No original development stores, provider/consent operation or financial UI command was used. Registration/fiscal gates remain unchanged.

| Built product source | Output tree SHA256 | Browser scope |
| --- | --- | --- |
| `36370d295470567cb547518b1e0a6da52eb72abf` | `5f0dd750b6332fe049e312984cdc751bc18a52c83b0fd7ed2de580f162cd5299` | Office hydration, navigation, filters, read ownership, direct/delegated contexts, errors and mobile |
| `a51f4ee68853772e8422b84f571edbcf5f80ea3f` | `f80a2df479969abbb955c0a3d4a81e97399486c80874a3abbf7c600f0dcf3434` | Resolved selector, real adjacent save/conflict/validation, six Company viewport/theme cases and 36 state matrix cases |
| `3948e62f7ec7337c8aa6b8d8d60465e07eec2ad5` | `8a4a5d610376999016a729524f78b7cb9203387619244c583603af80fdbd14de` | Final Work/Admin ownership fixes, login six-case matrix and representative hydration/shell/Company/operational smoke |

The second product differs from the first by an explicit import of the existing tax-profile selector. The final product adds only Work and Admin read-ownership changes in `frontend/app` (12 files, 65 additions/10 deletions). Those changed families were rechecked on the final artifact; prior unchanged-family acceptance is composed with final representative smoke, rather than presented as a complete rerun. The final task-owned frontend PID at capture was 653830; server index SHA256 was `ee075118ea0e1d9f857f9160a33bef55992ef6e2beb79bbea3352d7bd37b677f`.

Counts use actual proxy/backend GET paths and distinguish SSR from instrumented browser fetches. Only safe method/path/query/status metadata is retained. Synthetic error responses are explicitly labelled; they do not establish real provider or session-revocation behavior. The first development runtime had compilation/CDP delays; baseline timing is not a performance claim. Initial empty browser timing buffers were not interpreted as zero reads.

## Reproduced baseline and direct-entry result

| Detail | Before candidate conversion | Production browser result |
| --- | --- | --- |
| Client `/customers/empresa/1` | Stable `77d07d6`, exact `GET /api/v1/clients/1` at 14:25:55 and 14:26:34 UTC: 2 | Source `36370d2`: 1 SSR GET, 0 hydration GET |
| Work `/work/modelos/1` | Stable `4afa3a8`, exact `GET /api/v1/process-templates/1` at 14:19:00 and 14:19:49 UTC: 2 | Source `36370d2`: 1 SSR GET, 0 hydration GET |

Distinct dispatch contacts, catalogs, directory and preview reads were counted separately. IDs 1 and 2 both passed direct entry. SPA ID 1→2 retained the document, disconnected the old owned entity heading/input and displayed the correct second record with one entity GET. Native entity remount behavior was preserved.

## Completed browser checks

| Behavior | Observed result |
| --- | --- |
| Monitoring populated SSR/hydration | 1 overview GET; portfolio 1 and 16 attention keys retained; hydrated success |
| Monitoring zero and later freshness | Empty office actual 200/zero retained; adding a private synthetic monitoring row between visits changed portfolio 1→2 after reentry |
| Certificate populated/absent | Actual 200 metadata retained or actual 404/null retained, each one initial certificate GET |
| WhatsApp absence | Actual feature-gated 404/null retained; the existing gate was preserved |
| WhatsApp populated | Explicitly injected SSR 200/connected response retained with 1 initial GET and no hydration GET; not real gateway acceptance |
| Commercial seven tabs/history | Actual nonnull document, panel, navbar and commercial navigation nodes stayed mounted; one matching current entry, including nested contract/invoice destinations and Back/Forward |
| Commercial initialization | Each normal entry confirmed context once and read its logical resource once. Configuration cards shared 1 context GET, then settings and trial-link GET once each |
| Filters | Commercial search survived tab round trip; explicit URL won; changing a filter from page 2 dispatched only its final page-1 list query. Admin user search and Company product search survived round trips |
| Departed safe read | Delayed Admin subscription GET produced browser AbortError after departure; current users loaded; no unused plan catalog dispatched or departed failure feedback appeared |
| Rapid commercial transitions | Final route/current marker agreed; obsolete contract list was not dispatched in the sampled rapid sequence |
| Work direct month normalization | Missing/invalid month changed the URL once, retained unrelated query keys and requested one grouped dataset; valid direct month remained authoritative |
| Purchases/sales initial area | Direct purchases/default/returns and sales/orders/sales selected their actual area with each required list/reference once; no redundant default-area list |
| Direct/delegated Company | Real settings 200; direct editor enabled. Delegation change reloaded authority and removed the old draft; delegated fields were read-only, with no enabled mutation action |
| Settings query/hash | Same actual IE input and unsaved value retained, with zero settings GET |
| Adjacent real save | UI selected the real synthetic tax profile; actual settings PATCH 200 updated it and preserved the unsaved IE field |
| Named validation through adjacent save | Actual Company PATCH 422 marked IE invalid; another card's actual PATCH 200 preserved the invalid IE draft and named feedback |
| Real revision conflict | Private fixture advanced only the synthetic default revision; actual UI PATCH returned 409, retained the draft and exposed explicit review |
| Own exact-value save | Actual settings PATCH 200 and direct reload retained CST PIS `01` and rate `1.6500` as exact strings |
| Remote dirty-field conflict | Real refreshed IE changed remotely; local dirty IE stayed unchanged, with conflict notice and explicit discard action |
| Print | Real issued synthetic invoice printed with invoice content and no sidebar/commercial menu; PDF generated. Context was confirmed separately in server/client apps (2 total), invoice print read once |

The tax selector initially rendered an unresolved custom tag in `36370d2`; root fixed its existing-component import. In `a51f4ee` the selector resolved, actual adjacent PATCH and actual 409/review passed. This finding is resolved.

## Responsive, theme and error coverage

Representative viewport matrix: 320×800, 390×844 and 1440×1000, each light/dark, with actual theme class checked. Monitoring populated and commercial client lists passed all six combinations without document overflow. The no-selected-Company settings fallback also passed six combinations; it is not counted as an authorized Company editor. After the selector import, actual direct Company 1 settings passed all six combinations with enabled fields, resolved selector and no overflow. This is representative coverage, not browser coverage of all 105 inventoried routes.

Mobile Company checks passed at 390px: drawer opens with focus inside, Escape returns focus to its trigger, hash navigation and cross-module navigation close the drawer and restore trigger focus. At 320px, Enter opened the menu, Tab stayed inside the dialog and Escape restored focus. Disclosure kept the active child reachable. Injected Monitoring 503 at 320/light and 390/dark retained a visible retry action without overflow. Additional state coverage loaded each state once, then verified its content and geometry at all six viewport/theme combinations: actual empty Monitoring 200, actual missing certificate 404/null, injected Company 403, injected Company context-changed 409, injected Monitoring 503 and actual retry 200 (36 checks). Each state remained truthful without overflow. Final post-401 login also passed all six combinations with null actor, password form, no protected Admin content and no overflow.

Desktop technical variants used a private GET-only injection proxy. Monitoring/certificate/WhatsApp initial 503 remained a retryable error through hydration, without fabricated zero/absence; retry returned actual 200 for Monitoring/certificate and actual gated 404/null for WhatsApp. Injected Company 403 and `409 company_context_changed` cleared/disabled protected fields. Ordinary prelogin `/me` 401 was real. An injected Admin list 401 initially exposed two extra Admin reads during invalidation. Final `3948e62` resolved that finding: the single injected users GET 401 cleared actor/protected DOM, redirected to login and admitted zero subsequent users GETs. Only the public registration-status GET followed. A clean real login returned 200. Injection does not revoke the actual backend session. A later real UI logout returned 204 and actual `/me` returned 401 with null actor.

## Final artifact verification

- Work Tarefas August direct: grouped GET 1 and its own unscoped GET 1. SPA to Clientes September: grouped GET 1, outgoing unscoped GET 0. Back to Tarefas August: grouped GET 1 and its own unscoped GET 1. This resolves the earlier duplicate grouped/departed-view reads. Missing and invalid month entries each made exactly one month-changing URL replacement, preserved the unrelated `qa` query and made one grouped GET.
- Client 2 and Work model 2 direct entries each made one exact entity GET; distinct dispatch contacts/directory/catalog/preview requests were excluded from the entity count. Monitoring SSR made one overview GET and retained portfolio 2 with 16 attention keys.
- Commercial Faturas→Assinaturas→Configurações preserved actual nonnull document, panel, navbar and navigation nodes with a single matching current entry. Configuration context, settings and trial-link each read once.
- Real direct Company settings retained the resolved selector and enabled fields. Query/hash retained the same IE input and unsaved draft with zero reads. Purchases `#returns` and Sales `#orders` each dispatched only the selected logical list and required references once.
- Final injected Admin 401 admitted zero followup protected reads and passed the six login geometry/theme cases. Clean reauthentication used the real backend and returned 200. Final real UI logout returned 204; actual `/me` returned 401, actor remained null and the route was login.
- Only the named QA browser session was closed after capture; injection controls were restored to `{}`. The task-owned runtime remains available for the parent’s inspection.

Independent root test/build results are outside this browser report's verification claims. No live provider, PostgreSQL concurrency, all-105-route browser coverage or complete possible-state Cartesian matrix was accepted here. Detailed sanitized counts and source metadata are in `browser-evidence.json`; private raw scratch is excluded from Git.
