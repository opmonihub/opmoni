# Task 1.2: ERP and dashboard template baseline

Captured 2026-10-23, before ERP restyling. This is measurement evidence, not an acceptance of the current differences. The paired PNG and JSON files are in [`baseline/`](baseline/); each stem is `{opmoni|template}-{settings|catalog|editor|operations}-{light|dark}-{desktop|mobile}`. Desktop is 1366 × 900 CSS px; mobile is 390 × 844 CSS px. **Twenty-six pairs are verified; six reference pairs remain provisional and must not be used for visual acceptance:** `template-editor-dark-{desktop,mobile}`, `template-operations-dark-{desktop,mobile}`, `template-settings-dark-mobile`, and `template-catalog-dark-mobile`. Verified JSON has `captureVersion: 2` and, for the template, `consentSettled: true`. Every JSON records the URL, viewport and scale, DOM color mode, resolved semantic tokens, computed type and control metrics, and state. Each origin received its own persisted color preference and reload; the DOM mode and captured JSON mode were asserted before accepting a sample. The same root size (16 px), theme, page family, and viewport are compared within each verified pair.

## Source families and component contract

| Family | Template source in read-only main `.dashboard` | Opmoni source | Captured state |
| --- | --- | --- | --- |
| Shell | `.dashboard/app/layouts/default.vue:118-161` | `frontend/app/layouts/erp.vue:44-113` | Expanded desktop sidebar; collapsed mobile sidebar |
| Settings | `.dashboard/app/pages/settings.vue:30-49`, `settings/index.vue:51-157` | `frontend/app/pages/erp/configuracoes.vue:13-32`, `frontend/app/components/erp/ErpSettingsSectionForm.vue:111-187` | Template profile; ERP company settings with operating write gate disabled |
| Catalog | `.dashboard/app/pages/customers.vue:210-329` | `frontend/app/components/erp/ErpCatalogPageShell.vue:11-40`, `frontend/app/components/erp/catalogs/ErpCatalogResource.vue:379-428` | Template Customers demo data; one synthetic ERP Product row |
| Editor | `.dashboard/app/components/customers/AddModal.vue:26-58` | `frontend/app/components/erp/catalogs/ErpCatalogResource.vue:428-503`, `ErpProductCatalogForm.vue:191-649` | New customer modal; edit existing synthetic Product slideover |
| Operational list | `.dashboard/app/pages/inbox.vue:51-88` | `frontend/app/components/erp/ErpFinanceWorkspace.vue:316-570` | Template Inbox list; ERP Finance obligations list, empty under synthetic fixture |

The installed `@nuxt/ui` is **4.11.3** in both trees (Nuxt **4.6.0**). The Nuxt UI MCP was used to inspect the actual `DashboardPanel`, `DashboardNavbar`, and `Table` component docs; `DashboardSidebar`, `DashboardToolbar`, `Slideover`, and `Form` metadata; and the official `DashboardSidebarExample` and `DashboardNavbarExample`. The relevant official references are [Dashboard Panel](https://ui.nuxt.com/docs/components/dashboard-panel), [Dashboard Navbar](https://ui.nuxt.com/docs/components/dashboard-navbar), [Dashboard Sidebar](https://ui.nuxt.com/docs/components/dashboard-sidebar), [Dashboard Toolbar](https://ui.nuxt.com/docs/components/dashboard-toolbar), [Table](https://ui.nuxt.com/docs/components/table), [Slideover](https://ui.nuxt.com/docs/components/slideover), and [Form](https://ui.nuxt.com/docs/components/form). Generated theme files for these components, modal, and form were byte-identical across the independently installed trees. The pages use the documented Nuxt UI dashboard panel/navbar/toolbar/sidebar, table, form, modal, and slideover primitives, so their component APIs are compatible with the installed version.

## Measured tokens and geometry

The CSS `--font-sans` is `Public Sans` in both source trees (`frontend/app/assets/css/main.css:5`, `.dashboard/app/assets/css/main.css:5`). Computed body family in the browser starts with `Public Sans` followed by generated system fallbacks. The **Latin faces at 400, 500 and 600 loaded** in the captured pages after `document.fonts.load` and `document.fonts.ready`; other unicode-range/weight records may stay unloaded because their glyphs were unused. The computed family list alone does not prove font delivery; `publicSansFaces` in each JSON preserves that distinction.

| Measure | Opmoni light | Template light | Both dark / shared geometry |
| --- | --- | --- | --- |
| `--ui-primary` | `#0A5331` (ERP route override) | `#00C16A` | `#00DC82` in both |
| `--ui-bg` | `#fff` | `#fff` | `oklch(21% 0.006 285.885)` in both |
| `--ui-radius`; `--ui-header-height` | `.25rem`; `4rem` | `.25rem`; `4rem` | Same values |
| Desktop shell | Sidebar 240 px; panel x=240, width=1126 px | Same | 64 px navbar in measured full-width panels |
| Mobile shell | Sidebar width 0; panel x=0, width=390 px | Same | 64 px navbar where the full-width panel is used |
| Settings body | Full panel, with `max-w-4xl` inner content | Full panel, with `lg:max-w-2xl` inner content | ERP toolbar 49 px desktop / 103 px mobile; template desktop toolbar 49 px |
| Catalog table | 1078 px desktop / 642 px mobile | 1078 px desktop / 816 px mobile | Mobile tables overflow horizontally within their list region |
| Editor overlay | Slideover 768 px desktop / 390 px mobile | Modal 512 px desktop / 358 px mobile | Distinct documented Nuxt UI primitives and purposes |
| Operational list | Finance table 1076 px desktop / 768 px mobile | Inbox list pane 399 px desktop; mobile changes layout | These are workflow analogues, not identical data tables |

The JSON files include exact `x/y/width/height`, computed padding, font size and line height, foreground/background, and visible control labels for sidebar, panel, navbar, toolbar, body, heading, first **visible** button and input, table/header, and dialog/input where present. A missing geometry object means that element does not exist in that captured page state (for example, the Inbox list has no `table`). In the mobile editor, dimmed background content may report a negative table `x` while the slideover is open; the overlay bounds are the useful foreground comparison.

The content differences are unavoidable in a genuine baseline: Customers and Inbox are template demo pages, while Products and Finance are native ERP workflows. The synthetic ERP Product is the only catalog row. Finance has an empty obligations table because no transactions were fabricated. Company settings retain their unconfirmed/disabled state under the existing operating gate. The Product editor was opened through its actual row action and its unit resolved to `UN`; some other optional lookup controls can still show a pending indicator in the captured editor, so their settled visual state is **not** established by these screenshots. No report/release or provider gate was enabled to create a visually richer page.

The reference copy was served independently on `127.0.0.1:3341`; Opmoni used `localhost:3340`. The reference sample profile labels/avatar were sanitized in the ignored copy. Its demo consent cookie was set only in the browser so the persistent toast does not obscure verified reference captures. Browser sessions are named `opmoni-task2-reference` and `opmoni-task2-baseline`. Fixture and runtime ownership are documented in [`fixture-provenance.md`](fixture-provenance.md); credentials, cookies and ingress remain ignored.
