# System UI Pattern Crosswalk

Date: 2026-10-26  
Baseline: root `main`, `dac7293`  
Method: static source inventory, representative frontend/API reads and official Nuxt UI examples  
Status: planning evidence; no product changes or browser/test acceptance

## Coverage and counting

The inventory scanned all Vue files under `frontend/app`, removing script blocks and HTML comments before counting literal component tags. It found **31 `UTable`, 64 `UModal`, seven `USlideover`, one `UDrawer`, 72 `UCard`, 26 `UPageCard`, and two native `<table>` declarations**. These are source declarations, not numbers of runtime screens: generic components and loops create many consumers.

The [declaration inventory](2026-10-26-system-ui-component-inventory.csv) provides the source paths and counts. A documentation example inside the `PanelList` script would inflate a naive `UTable` count to 32; it is excluded. The native calendar table is screen-reader markup, not an oversimplified operational table. The accounting deliveries table is an actual native table and needs its own capability assessment.

Two read-only workers separately compared table families and form/overlay families, followed by coordinator source checks. Both were requested as `gpt-6-luna`, medium effort; actual routing telemetry was unavailable. No interrupted worktree draft was used as evidence. Capability reads are representative, not proof that every specialized company-ERP table has identical behavior.

## Existing table families and gaps

| Family | Present in source | Missing or next assessment | Reuse / preservation |
| --- | --- | --- | --- |
| Portfolio clients | Server sort/filter, visibility, incremental loading, row actions, explicit/all-matching snapshot selection and tags action | Consistent presentation and selection feedback; assess added domain actions individually, not a replacement basic table | `ClientPortfolioTable`, `DataTableSortButton`, `useClientListSelection`, `CustomersSelectionBar` |
| Work tasks | Flat table, local sorting/filter facets, paging, task selection, bulk assign/advance/dismiss | Document loaded-data scope and independent per-task mutation results; server-wide search/sort or atomic bulk would require different contracts | `WorkTarefasBulkBar`, task-selection helpers; preserve task IDs and partial failures |
| Work clients/processes | Derived client/process grouping, expansion and aggregate checkbox selection of eligible task leaves | Consolidate shared presentation; do not replace derived groups with arbitrary tree nodes or submit group IDs | `workGroupedTableOptions`, `workExpandedOptions`, leaf-selection utilities |
| Monitoring | Server search/tags/status/page, incremental loading, selected loaded clients for manual search, desktop table/mobile records | User sorting is not wired in the sampled table/query path; selection does not authorize generic mass mutation | `MonitoringSheet`, existing association/manual-search/send dialogs and resource gates |
| Fiscal documents | Persisted server criteria and paging; request sort allowlist and visible emission-order control; contextual document details | Assess additional useful sort controls and operation-specific selection; no generic row-selection/bulk mutation in the sampled list | Existing fiscal query contract and `FiscalDocumentSheet` business behavior |
| Fiscal clients | Locally filtered/sorted/paged aggregate rows, selection and export actions | State the loaded/filtered aggregate scope; export is not the same as mutating fiscal documents in bulk | Existing export/selection handlers and summary facts |
| Team | Shared panel composition, custom member/department lists, local search/filter and name ordering, per-record actions and modal forms | Add table-style capabilities only if scale and a useful operation justify them; department membership multi-select is a form field | Existing lists and invitation/department modals |
| Administration | Shared panel/columns, search/status and paging on sampled lists, local plan search, per-record operations | User sorting, row selection and bulk controls absent in representative lists; API sort/bulk support needs explicit work | `DataTablePanelList`, `ColumnMenu`, existing account/team modal examples |
| Commercial office ERP | Server pagination/query criteria, column visibility, client/contract detail expansion, existing financial filters and domain commands | No user-sort or row-selection wiring in the shared table; list validation does not accept sort params. Add only after defining endpoint behavior | Existing shared filters/paging; preserve exact money, authority, recovery and print bypass |
| Company ERP catalogs | Generic resource host, server search/page, fixed ID order, row actions, create/edit/detail and archive dialogs | Clear high-leverage gap: no exposed user sort/selection. Status exists in the API scope but must be reviewed for actual UI wiring. Sort/bulk are not supplied by the current host contract | `ErpCatalogResource`, `useErpCatalogList`, resource query/permission guards |
| Company ERP operations | Separate sales/purchase/stock/cash/finance tables and workflow modals; domain-specific paged criteria | Assess each operation table individually; picking records for reconciliation is not a universal bulk command | Existing domain forms/workspaces and authorization/recovery guards |
| Reports, hubs and home | Summary/report tables, cards, totals, links, report-basis copy | Identify comparison/export needs; do not require mutation selection for read-only summaries. Distinguish preview tables from full operational lists | `ErpReportMetrics`, `ErpModuleHub`, `HomeSales`, accounting deliveries |

Evidence sources:

- [Portfolio table](../../frontend/app/components/customers/ClientPortfolioTable.vue), sorting and selection columns around lines 56–124; [selection ownership](../../frontend/app/composables/useClientListSelection.ts), lines 25–109; [client request](../../backend/app/Http/Requests/Tenant/IndexClientRequest.php), lines 26–55.
- [Work tasks](../../frontend/app/pages/work/tarefas.vue), selection/actions around lines 332–382 and 511–568; [Work grouping](../../frontend/app/utils/workGroupedTable.ts); [grouped clients](../../frontend/app/pages/work/clientes.vue), lines 737–752; [grouped processes](../../frontend/app/pages/work/processos/index.vue), lines 809–824; [task controller](../../backend/app/Http/Controllers/Api/V1/Tenant/TaskController.php), index/grouped query contracts.
- [Monitoring](../../frontend/app/components/monitoring/MonitoringSheet.vue), lines 72–122, 254–275 and 790–835; [monitoring controller](../../backend/app/Http/Controllers/Api/V1/Tenant/SerproMonitoringObligationController.php), lines 24–39; [reader](../../backend/app/Services/SerproMonitoringReader.php), query and fixed-order behavior.
- [Fiscal documents](../../frontend/app/pages/fiscal/documentos.vue), query/order controls around lines 291–386; [fiscal request](../../backend/app/Http/Requests/Tenant/IndexFiscalDocumentRequest.php), lines 41–70; [fiscal clients](../../frontend/app/pages/fiscal/clientes.vue), local summary query/selection around lines 169–281 and 361–421.
- [Team members](../../frontend/app/pages/equipe/index.vue), lines 69–87 and 151–185; [departments](../../frontend/app/pages/equipe/departamentos.vue); [admin users](../../frontend/app/pages/admin/usuarios.vue); [admin subscriptions](../../frontend/app/pages/admin/assinaturas.vue); [admin user request](../../backend/app/Http/Requests/Admin/IndexUserRequest.php); [subscription request](../../backend/app/Http/Requests/Admin/IndexSubscriptionRequest.php).
- [Office table wrapper](../../frontend/app/components/office-erp/OfficeErpDataTable.vue), props and capabilities around lines 6–31 and 35–89; [commercial list request](../../backend/app/Http/Requests/OfficeErp/ListCommercialRequest.php), filter allowlists and validation.
- [Catalog resource](../../frontend/app/components/erp/catalogs/ErpCatalogResource.vue), table around lines 437–475; [catalog reader](../../frontend/app/composables/useErpCatalogList.ts), line 161; [query builder](../../frontend/shared/utils/erpCatalogs.ts), line 28; [catalog controller](../../backend/app/Http/Controllers/Api/V1/Erp/Catalogs/ErpCatalogController.php), lines 33–71; [catalog scope](../../backend/app/Erp/ErpCatalogScope.php), `listParams`.

## Table hierarchy: terminology and existing implementation

There is no local standalone `TableTree` component or `getSubRows`/`get-sub-rows` use in the audited current root. Work uses TanStack grouping over task records through the existing utility. Commercial ERP uses `#expanded` to disclose one record's details. Neither is interchangeable with an actual parent/child tree.

Official examples retrieved through the Nuxt UI MCP:

| Example | Use |
| --- | --- |
| `TableRowExpandableExample` | Detail region for the same record, using `row.toggleExpanded()` and `#expanded` |
| `TableGroupedRowsExample` | Group flat records with grouping options and group/leaf behavior |
| `TableTreeDataExample` | Real parent/child data via `:get-sub-rows="row => row.children"` |
| `TableColumnSortingReusableExample` | Shared sort trigger and table sorting state |
| `TableColumnFiltersExample` | Column-filter state and controls |
| `TableRowSelectionExample` | Selection state and checkboxes; product-specific IDs/scope must still be supplied |
| `ModalFooterSlotExample` | Form-oriented footer composition in `UModal` |
| `FormExampleOnError` | Validation error handling and focus behavior |

Documentation: [Table examples](https://ui.nuxt.com/docs/components/table#examples), [Modal](https://ui.nuxt.com/docs/components/modal), [Form](https://ui.nuxt.com/docs/components/form). Installed-version API metadata confirms Table sorting/filter/selection/expanded state, `getRowId` and `getSubRows`, and Modal scroll/fullscreen/dismissal controls. Examples supply composition, not product permissions or backend bulk contracts.

## Overlay crosswalk: complete slideover/drawer inventory

| Source and baseline line | Actual role | Planned treatment |
| --- | --- | --- |
| [OfficeErpPlanEditor](../../frontend/app/components/office-erp/OfficeErpPlanEditor.vue), 108 | Bounded plan editor | Migrate to modal; preserve immutable revision guidance, modules, publication and recovery |
| [Admin subscriptions](../../frontend/app/pages/admin/assinaturas.vue), 303 | Bounded subscription editor | Migrate to modal; preserve save/close guards |
| [Admin plans](../../frontend/app/pages/admin/planos.vue), 276 | Plan fields, limits and modules | Migrate to a suitably sized, scrollable modal |
| [Catalog resource](../../frontend/app/components/erp/catalogs/ErpCatalogResource.vue), 492 | Shared create/edit and read-only detail host | Migrate editor modes to modal with explicit mode handling; review the read-only-detail container separately |
| [NotificationsSlideover](../../frontend/app/components/NotificationsSlideover.vue), 11 | Notification feed/navigation | Not a registration form; separate decision |
| [Inbox](../../frontend/app/pages/inbox.vue), 84 | Mobile counterpart of selected-message panel | Preserve context; separate decision |
| [FiscalDocumentSheet](../../frontend/app/components/fiscal/FiscalDocumentSheet.vue), 293 | Contextual document inspector and restricted document operations | Separate review; not automatically included in bounded-form migration |
| [FilterPanel](../../frontend/app/components/data-table/FilterPanel.vue), 167 | Mobile filter drawer, with desktop popover counterpart | Retain filter behavior; not a registration/editor sheet |

The catalog host is instantiated 13 times across nine catalog page files: contacts, products, units, categories, brands, customer groups, prices (two resources), fiscal (two resources), and logistics (three resources). Its editor migration is one shared change with many consumers and existing nested discard/archive dialogs. Preserve dirty state, focus return, pending mutations, tabs and authority invalidation.

Already useful modal references in the product: [client creation](../../frontend/app/components/customers/ClientCreateModal.vue), [admin accounts](../../frontend/app/pages/admin/contas.vue), [company administration](../../frontend/app/components/erp/ErpCompanyAdministration.vue), [receipt](../../frontend/app/components/office-erp/OfficeErpReceiptModal.vue), [reversal](../../frontend/app/components/office-erp/OfficeErpReversalModal.vue), and team invitation/department dialogs.

Long enrollment, subscription/invoice detail, Work model/process editing and company configuration may remain pages. A file or token named `Sheet` can be a table/page surface: `MonitoringSheet` and `sheetTableUi` are not slideover forms.

## Cards, layout families and exceptions

Settings and team already closely follow the reference's centered PageCard composition; administration uses `DataTablePanelList` with a broader panel. Portfolio/monitoring use dense scrollable tables, Work has grouped/flat table styling, commercial ERP has its own panel/table wrappers, and company ERP has `ErpCatalogPageShell` plus operational table styles. These are the concrete sources of divergence to reconcile, rather than evidence that every card is wrong.

Shared style sources to compare: [panel tokens](../../frontend/app/components/data-table/panel.ts), [sheet tokens](../../frontend/app/components/data-table/sheet.ts), [Work table tokens](../../frontend/app/utils/workGroupedTable.ts), [ERP operational tokens](../../frontend/app/components/erp/erpPresentation.ts), [page shells](../../frontend/app/utils/pageShell.ts), and [CatalogPageShell](../../frontend/app/components/erp/ErpCatalogPageShell.vue).

Use one visual grammar while retaining intentional scroll ownership, operational width, report grouping and domain state. A report card, a preference section and a row detail do not automatically need the same container component. Card migration must be justified by hierarchy and task, not performed by a global `UCard` → `UPageCard` replacement.

## Follow-up gates

Use the [system-wide roadmap](../superpowers/plans/2026-10-26-system-ui-standardization-map.md) to sequence the next changes. First agree on shared compositions and the per-family capability contract; then pilot a useful list and a modal editor. List missing API/query/bulk capabilities as separate implementation items before exposing corresponding controls. Paired visual, keyboard, mobile and behavior checks are future acceptance work.

This pass writes documentation and inventory only. No runtime, browser, product API call, test execution, dependency installation, Git mutation, component/style/backend edit or worktree cleanup was performed. Official component examples and API metadata were read through the Nuxt UI MCP.
