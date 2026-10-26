# Frontend navigation lifecycle browser acceptance

Checkpoint: completed browser evidence is recorded below. Final Work/Admin followup checks and final-artifact smoke remain pending. No complete final-source acceptance is claimed.

## Runtime and source binding

QA owns `codex/navigation-browser-qa` and the two report files. Product source and private runtime are separately owned. The named browser session is `navigation-browser-qa` on `http://localhost:3354`.

Backend mount, exclusive PostgreSQL/Redis volumes, `opmoni_test`, Redis 13/14/15 and exact frontend origin were independently verified before additive fixtures. Fixtures contain real office memberships, 30 portfolio clients, two Work templates, direct Company ownership, a separate delegated Company with read grants, an empty office, certificate metadata, tax profiles and synthetic commercial records. The effective commercial fixture was completed with an occupied licence and linked subscription before its successful detail/print acceptance. No original development stores, provider/consent operation or financial UI command was used. Registration/fiscal gates remain unchanged.

| Built product source | Output tree SHA256 | Browser scope |
| --- | --- | --- |
| `36370d295470567cb547518b1e0a6da52eb72abf` | `5f0dd750b6332fe049e312984cdc751bc18a52c83b0fd7ed2de580f162cd5299` | Office hydration, navigation, filters, read ownership, direct/delegated contexts, errors and mobile |
| `a51f4ee68853772e8422b84f571edbcf5f80ea3f` | `f80a2df479969abbb955c0a3d4a81e97399486c80874a3abbf7c600f0dcf3434` | Resolved tax-profile selector, real adjacent save/conflict and six Company viewport/theme cases |

The second product differs from the first by an explicit import of the existing tax-profile selector. Its real browser acceptance is separate from earlier evidence. Final Work and Admin changes are not in these tested artifacts.

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
| Real revision conflict | Private fixture advanced only the synthetic default revision; actual UI PATCH returned 409, retained the draft and exposed explicit review |
| Remote dirty-field conflict | Real refreshed IE changed remotely; local dirty IE stayed unchanged, with conflict notice and explicit discard action |
| Print | Real issued synthetic invoice printed with invoice content and no sidebar/commercial menu; PDF generated. Context was confirmed separately in server/client apps (2 total), invoice print read once |

The tax selector initially rendered an unresolved custom tag in `36370d2`; root fixed its existing-component import. In `a51f4ee` the selector resolved, actual adjacent PATCH and actual 409/review passed. This finding is resolved.

## Responsive, theme and error coverage

Representative viewport matrix: 320×800, 390×844 and 1440×1000, each light/dark, with actual theme class checked. Monitoring populated and commercial client lists passed all six combinations without document overflow. The no-selected-Company settings fallback also passed six combinations; it is not counted as an authorized Company editor. After the selector import, actual direct Company 1 settings passed all six combinations with enabled fields, resolved selector and no overflow. This is representative coverage, not browser coverage of all 105 inventoried routes.

Mobile Company checks passed at 390px: drawer opens with focus inside, Escape returns focus to its trigger, hash navigation and cross-module navigation close the drawer and restore trigger focus. At 320px, Enter opened the menu, Tab stayed inside the dialog and Escape restored focus. Disclosure kept the active child reachable. Injected Monitoring 503 at 320/light and 390/dark retained a visible retry action without overflow.

Desktop technical variants used a private GET-only injection proxy. Monitoring/certificate/WhatsApp initial 503 remained a retryable error through hydration, without fabricated zero/absence; retry returned actual 200 for Monitoring/certificate and actual gated 404/null for WhatsApp. Injected Company 403 and `409 company_context_changed` cleared/disabled protected fields. Ordinary prelogin `/me` 401 was real. An injected Admin list 401 cleared actor/protected DOM and redirected to login, but exposed two extra Admin reads during invalidation; this is a reproduced issue awaiting the final Admin guard artifact, not a passing zero-read assertion.

## Pending final-artifact acceptance

- Final product artifact `3948e62` (or a later explicitly declared artifact) with source/tree fingerprint.
- Work Tarefas month 08→Clientes month 09→Back: earlier artifact sent duplicate grouped reads and a departed unscoped read after the router already selected the destination. The reported `aaafa97` ownership fix still needs actual final browser counts.
- Admin injected 401: verify zero newly admitted protected reads after the final ownership guard, then clean reentry.
- Representative final-artifact smoke for direct hydration/entity reads, commercial shell/configuration, Company selector/draft and operational hashes, with source differences stated.
- Close only the QA browser session and disable private injection controls after final capture.

Independent root test/build results are outside this browser report's verification claims. No live provider, PostgreSQL concurrency or complete state-by-viewport Cartesian matrix was accepted here. Detailed sanitized counts and source metadata are in `browser-evidence.json`; private raw scratch is excluded from Git.
