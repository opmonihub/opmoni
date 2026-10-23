# ERP template standardization design

User direction, 2026-10-20: restore ERP consistency with the actual local dashboard template and use expandable menus like the existing Settings navigation. This applies the fidelity contract in this directory's README to existing ERP screens.

## Scope

Keep existing routes, native Nuxt UI components, Public Sans, semantic green/zinc colors and the documented light ERP contrast adaptation. Restore a list-first workspace, focused create/edit interactions and compact expandable sidebar groups. Keep office Account and ERP Company authority and data separate.

Navigation has one registry for catalog children and Company settings children, used consistently by the sidebar and respective page navigation. Cadastros and Configurações expand like office Settings. Modules with multiple existing areas expose children using their existing hash contracts; Finance adopts the same validated hash convention for accounts/obligations/payments. Active descendants open their parent, including deep links and browser history. Clicking an expansion trigger keeps the mobile menu open; selecting a destination closes it and restores navigation focus. Collapsed desktop navigation uses native popovers. Child permissions must preserve action/family grants and direct-owner restrictions; an empty group is omitted. Overview remains a direct leaf.

Each page has one native panel/navbar, desktop collapse and mobile toggle, navbar actions and an optional native toolbar. Filters/navigation live in the toolbar or a clearly grouped list-control band. Records, valid empty/error state and domain totals take precedence over optional entry forms.

Finance creation opens explicit native overlays. Safe drafts remain retained across closes and area changes within the same authority, and are destroyed on authority invalidation. Validation/unknown-outcome recovery and authoritative resource refresh remain intact; a failed request must not close an editor or claim success. Domain details, receipts, allocations and reversals keep their accepted contracts. Do not nest forms or silently create transactions.

Catalog creation/edit/detail opens on demand using native overlays with sensible width and scrolling. Keep the list context mounted, real typed forms, permission-aware row actions, notes/file privacy, server pagination, transient last-good state and context fencing. Archive remains an explicit impact confirmation.

Existing consultation hubs and reports receive the same shell/toolbar composition without implementing new purchase/cash/stock/sales/fiscal/accounting operations. Task 12's unfinished cash test and previous runtime outputs are preserved. Non-ERP audit findings are outside this standardization.

## Acceptance

Meaningful source/component regressions prove nested navigation permissions and active state, deep links/history, explicit editor opening, draft retention and authority clearing, existing financial/catalog behavior and mobile navigation. Run focused suites plus matching lint/typecheck and one final build. Coordinator performs bounded direct browser comparison on representative catalogs/Finance/settings/hub, desktop/mobile and both themes; keyboard/focus and visible list placement are checked. Providers, fiscal canaries and unavailable flows remain explicitly unverified.
