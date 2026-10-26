# UI reference and component revalidation (task 1.4)

Checked on 2026-10-24 at `a535f3c11c02ac19ae789b67b091ea78ac4a9861` in `.worktrees/office-erp-commercial-design`. The source of visual evidence is the read-only absolute path `/home/obsidian/dev/opmoni/.dashboard/app`. This record rechecks [the change audit](../../../openspec/changes/archive/2026-10-25-add-office-erp-commercial-management/ui-reference-audit.md) and [component matrix](../../../openspec/changes/archive/2026-10-25-add-office-erp-commercial-management/component-matrix.md) before UI implementation; it does not establish rendered or behavioral acceptance.

## Reference fingerprints

`sha256sum` was run against the six absolute `.dashboard/app` files. Every current value equals the value in the change audit.

| Path relative to `.dashboard/` | Current SHA256 | Audit match |
|---|---|---|
| `app/app.config.ts` | `f06e9b2f85689ecc9a7979c903e7919abce26a59865ddd19bbe790fcf6e318ef` | Yes |
| `app/assets/css/main.css` | `e355fab5f1f08f067ae25739e4bba07acda1bd8e82a8c28836a30889b097f41d` | Yes |
| `app/layouts/default.vue` | `43aff144b51a96b3146a478e73510ba94e58ba4f5efe5fd2f29250973a924a66` | Yes |
| `app/pages/customers.vue` | `d3885e88d1c906a0ccb40e0e8e66e6bd43178cb66d21d2106ecaf3dcbce5861f` | Yes |
| `app/components/customers/AddModal.vue` | `e912eecbb38e277b2670a7603624b0863610dedb99e40ecda8919266e6ae0dd9` | Yes |
| `app/pages/settings.vue` | `4e1493d3bd6ecd262cd65820847f3044c0153e447e8b41d77db68cbd579d2e16` | Yes |

## Installed baseline

Both `frontend/` in this worktree and `/home/obsidian/dev/opmoni/.dashboard/` declare `pnpm@12.11.2` and resolve the same installed package versions: Nuxt `4.6.0`, `@nuxt/ui` `4.11.3`, Tailwind CSS `4.3.3`, Vue `3.5.43`, and Zod `4.6.5`. These values were read from each `package.json` and each installed `node_modules/<package>/package.json`. The audit's statement that new worktrees lack installed dependencies is now stale for this prepared worktree; no package version or reference fingerprint has drifted.

The matrix has 33 numbered, unique rows. For **each** row below, both `frontend/node_modules/@nuxt/ui/dist/runtime/components/<name>.vue.d.ts` and `<name>.vue` exist in this worktree, and both files' SHA256 values equal the corresponding installed files under `/home/obsidian/dev/opmoni/frontend/`. This proves that the complete local declarations and implementations used by the original matrix are unchanged, including its selected props, slots, and emits. The table explicitly records every checked row.

| Matrix rows | Installed component declarations and implementations | Result |
|---|---|---|
| 1–6 | App, DashboardGroup, DashboardSidebar, DashboardSearchButton, DashboardSearch, NavigationMenu | 6/6 identical |
| 7–12 | DashboardPanel, DashboardNavbar, DashboardSidebarCollapse, DashboardToolbar, Button, Icon | 6/6 identical |
| 13–18 | PageGrid, PageCard, Table, Pagination, DropdownMenu, Badge | 6/6 identical |
| 19–24 | Form, FormField, Input, InputNumber, Select, SelectMenu | 6/6 identical |
| 25–30 | Switch, Textarea, Alert, Skeleton, Modal, Slideover | 6/6 identical |
| 31–33 | Separator, Card, FileUpload | 3/3 identical |

The generated `frontend/.nuxt/ui/page-card.ts` also matches the main checkout byte-for-byte and still defines `subtle` and `naked` variants. The selected API subset remains compatible with the prepared worktree's installed version. In particular:

- `ButtonProps` inherits icon/loading from `UseComponentIconsProps` and disabled/type/to/form behavior through `LinkProps` and button HTML attributes; absence of these names directly in `Button.vue.d.ts` is not an API removal.
- `TableProps` inherits `getRowId` from TanStack `CoreOptions`; installed `Table.vue` forwards `getRowId`. `SelectProps` and `SelectMenuProps` inherit `loading` from `UseComponentIconsProps`; installed implementations declare and forward it.
- `InputNumberProps` binds `number | null`, so it remains restricted to integer capacities/days and must not be used for BRL. `FileUploadProps` confirms `variant="button"` is single-file only; native `File`, `accept`, `multiple`, and `disabled` remain in the installed declaration.

The local Nuxt UI skill's conventions, component selection, dashboard, and data-table references were reviewed. Official Nuxt UI MCP metadata/usage was rechecked for [Table](https://ui.nuxt.com/docs/components/table), [SelectMenu](https://ui.nuxt.com/docs/components/select-menu), and [FileUpload](https://ui.nuxt.com/docs/components/file-upload) where current online APIs or inherited declarations can be confusing. Current online FileUpload usage mentions custom file items; the matrix's installed-version restriction to native `File` remains necessary. The official table example is demonstrative and does not replace the planned server-side pagination.

No fingerprint, package version, or selected component API requires a change to the matrix or visual audit. Existing contrast corrections in `frontend/app/assets/css/main.css` and `frontend/app/app.config.ts` remain the implementation baseline. Actual SFC compilation, browser rendering, responsive layout, accessibility, and business flows remain assigned to later implementation and acceptance tasks.
