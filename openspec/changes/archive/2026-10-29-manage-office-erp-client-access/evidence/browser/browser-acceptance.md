# Browser acceptance — 2026-10-28–29

The coordinator exercised the actual Nuxt application at `http://localhost:3350` with authenticated Chromium sessions, relative `/api/v1` calls, the isolated browser backend on port 18871, database `opmoni_client_access_browser` and Redis databases 10–12. These are synthetic acceptance identities and records. No development database was reset. Viewports were emulated Chromium at 320px, 390px and 1440px; physical-device/Safari acceptance is not implied.

## Verified application flows

| Flow | Observed result |
| --- | --- |
| Office navigation and list round trip | Login/context and Company list returned 200. Sidebar/list/detail navigation retained the SPA sentinel. Returning from assignment/cancellation retained the Company search. |
| Company profile | A nine-character legal name was rejected locally; 255 characters persisted through PostgreSQL/API 200. Invalid email returned 422 with Portuguese field feedback and retained the draft. Cancel preserved confirmed fields. The fixture name was restored after the length check; the intended phone change remained. |
| Portfolio registration | Bulk selection of two existing Clients returned 201, created two registrations and cleared selection; registered rows became disabled. Single-row addition returned 201 and kept the modal open, refreshed the registered row and prevented duplicate addition. |
| Selection across search changes | A final follow-up selected Client 07, filtered to Client 08 and observed an unchecked current-page header with global selection count 1. Selecting that header checked only visible Client 08 and raised the count to 2. Filtering back to 07 showed its row/header checked; clearing that header left Client 08 selected (count 1). The dialog was closed without a registration POST. |
| Registration gives no operating authority | As the office-only commercial administrator, an actual CSRF-protected switch to newly registered, unassigned Company 9 returned 403. An operating purchases GET with matching `X-Company-Id: 9` also returned 403. The selected Company context remained unchanged. No fake membership/owner was created. |
| Pending assignment and activation | Company 6 was assigned exact terms `99.90` from its row. The resulting detail resolved contract 7, initially without an invoice. Activation returned the effective subscription and first invoice 7 for `99.90`. |
| Suspension and resumption | Company 6 was suspended through the confirmation modal; the refreshed action became Retomar. Resumption restored the active state. |
| Pending cancellation | Company 7 received pending contract 8 at `88.80`. Confirmed immediate cancellation released its allocation, preserved the Company and left invoice count zero. The final detail rendered Cancelada. |
| Immutable pending changes | Company 4 retained current revision 1 and issued invoice 3 at `125.50`. A pending `130.50`/purchases snapshot became `131.50` through Gerenciar assinatura; Gerenciar módulos then restored stock transfers in pending revision 4 while preserving `131.50`. Current terms and invoice stayed unchanged. |
| Module boundary | Separate coherent Companies 10/11 were provisioned through real services with original calendar anchors. Before the boundary purchases/transfers returned 200/200; at the boundary they returned 200/403 `erp_module_disabled`. No clock override or scheduler was used. Existing rows remained snapshot-equal. See [sanitized HTTP evidence](module-boundary-browser.json). |
| One/many members and grants | One-Owner and multiple-member states rendered from actual scoped APIs. Owner demotion/removal controls were protected. An ordinary member was updated to Viewer with `erp.read` and one same-Company establishment (200); empty selected scope was rejected locally. |
| Invitation acceptance | A new recipient accepted Company access without implicit login. An existing recipient logged in, returned to the clean receiver and accepted a second Company while preserving the original global name/password. Removing its first Company access preserved its global identity and second membership. |
| Direct credential reset | The actual commercial reset returned 200 and cleared both password fields. Broker recovery preparation returned 202 with precise prepared/awaiting-send copy. No office response exposed a bearer URL. |
| Recipient recovery | An anonymous receiver completed recovery and removed password inputs without implicit login. An independently resolved losing receiver subsequently showed Link indisponível. A mismatched signed-in identity could not enter a password. |
| Receiver replacement and privacy | Both public receivers consumed a replacement fragment on the same mounted route and rejected invalid/malformed replacements without resurrecting the previous proof. Encoded/reordered SPA fragments and browser Back were checked: bearer absent from DOM, history, local/session storage, console and errors. |

## Responsive and keyboard checks

The approved local prototype informed the subscription section order, management/module dialogs, portfolio flow and neutral operational actions. The initial 320px layout was rejected by the user and revised. The captures below record the final technical review; they do not assert subsequent user visual approval.

- Companies and portfolio were inspected at 320/390px in both actual application themes, with no document overflow. Portfolio identity/name/CNPJ share a readable column; row actions remain available. The native checkbox retains semantics and has a measured 44px hit area. Selection updates the header and Adicionar count.
- Portfolio has two reachable 44px footer buttons, and redundant pagination is hidden for one filtered page. The 320px dialog measured 304px; its table columns measured 44/183/44px. The 390px dialog measured 374px.
- At 1440px, Companies columns measured 312/136.5/136.5/136.5/253.5px with 56.5px rows; portfolio columns measured 96/353.5/353.5/44px with 60.5px rows. A desktop column-width regression found during mobile work was corrected and checked after a fresh mount.
- Profile Tab/Shift+Tab stayed inside its dialog and Escape closed it. Portfolio Escape restored Novo Cliente focus after the leave transition. The nested module dialog contained Tab/Shift+Tab and Escape returned to subscription management.
- Subscription management and module editors were inspected at 320/390px. Their bodies scroll within the dialog and native footers remain reachable. The 320px management dialog measured 272px and retained visible 44px footer controls.

## Final screenshot index

| Surface | Final captures |
| --- | --- |
| Companies, desktop | [1440 light](companies-1440-light-final.png), [1440 dark](companies-1440-dark-final.png) |
| Companies, mobile | [320 light](companies-320-light-final.png), [320 dark](companies-320-dark-final.png), [390 light](companies-390-light-final.png) |
| Portfolio, mobile | [320 light](portfolio-320-light-final.png), [320 dark](portfolio-320-dark-final.png), [390 light](portfolio-390-light-final.png), [390 dark](portfolio-390-dark-final.png) |
| Management | [320 light](subscription-management-320-light-final.png), [390 light](subscription-management-390-light-final.png) |
| Modules | [320 light](modules-320-light-final.png) |
| Subscription | [390 light](subscription-390-light-final.png) |
| Members and accepted invitation | [Members, 390 dark](members-390-dark.png), [accepted invitation, 320 dark](invitation-accepted-320-dark.png) |

Other captures are intermediate diagnostic evidence. In particular `portfolio-320-dark.png`, `portfolio-320-dark-mobile-review.png`, `profile-validation-390-light.png` and `subscription-management-390-dark.png` precede the final corrections and must not be used as final visual acceptance.

## Delivery and production limits

Public receiver links were opened manually from encrypted synthetic outboxes by a private helper; this proves recipient behavior, not email delivery. No SMTP/provider/inbox was configured or exercised. Controlled backend fakes cover transport/failure branches. A final read-only privacy scan covered three outboxes, two in-memory bearers and 27,963 log lines, with zero bearer/URL matches and zero failed jobs; no live failed-job event was induced.

Both public paths returned SSR 200 with `no-store` and `no-referrer`; fragment values were absent from SSR. Production registration, fiscal/SERPRO, payment and WhatsApp gates were preserved. No deployment, canonical spec synchronization or archive occurred.
