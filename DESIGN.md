---
name: Opmoni System UI
description: Shared Nuxt UI compositions with task-specific list, form, workspace and inspection behavior.
colors:
  primary: "var(--ui-primary)"
  neutral-bg: "var(--ui-bg)"
  neutral-elevated: "var(--ui-bg-elevated)"
  neutral-text: "var(--ui-text)"
  action-text: "var(--ui-text-inverted)"
  neutral-border: "var(--ui-border)"
typography:
  title:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "16px"
    fontWeight: 600
  body:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "14px"
    fontWeight: 400
  label:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "14px"
    fontWeight: 500
  detail:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "12px"
    fontWeight: 400
rounded:
  sm: "4px"
  md: "6px"
  lg: "8px"
  xl: "12px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  lg: "24px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.action-text}"
    rounded: "{rounded.md}"
  input-default:
    backgroundColor: "{colors.neutral-bg}"
    textColor: "{colors.neutral-text}"
    rounded: "{rounded.md}"
  card-default:
    backgroundColor: "{colors.neutral-bg}"
    textColor: "{colors.neutral-text}"
    rounded: "{rounded.xl}"
    padding: "16px"
---

# Design System: Opmoni

## Overview

**Creative North Star: "Compact ERP operations"**

ERP management keeps the established Opmoni identity and Nuxt UI language. Its visual character is clean, organized, and restrained: the interface gives priority to client registration and ERP activation, then keeps subscriptions and commercial records easy to scan. Green is the default primary accent, while the selected light or dark theme supplies the actual color values through Nuxt UI tokens.

The module favors compact information hierarchy over decorative treatment. Small headings, short supporting copy, compact rows, and familiar application components help office operators move between registration, activation, and ongoing commercial work. Layouts adapt to mobile so registration and operation remain available on narrow screens.

**Key Characteristics:**
- Opmoni identity and runtime-selectable Nuxt UI theme
- Compact hierarchy for frequent commercial work
- Client registration and ERP activation remain prominent
- Complete operation adapts to mobile

## Colors

The module uses the application's default green primary and zinc neutral family while resolving surfaces and text through Nuxt UI's active light or dark theme.

### Primary
- **Opmoni Green** (`var(--ui-primary)`): Default action and focus accent. The configured primary palette is green; the runtime token keeps theme selection authoritative.

### Neutral
- **Theme Surface** (`var(--ui-bg)`): Main page and component background in the active theme.
- **Raised Surface** (`var(--ui-bg-elevated)`): Subtle contrast for table headers, selected content, and raised controls.
- **Theme Text** (`var(--ui-text)`): Default text, with semantic muted and highlighted roles supplied by Nuxt UI.
- **Theme Border** (`var(--ui-border)`): Fine separators and component outlines.

**The Live Theme Rule.** Use semantic Nuxt UI colors such as primary, neutral, default, elevated, muted, and highlighted so the user's selected light or dark mode remains in control.

## Typography

**Display Font:** Public Sans (with sans-serif fallback)
**Body Font:** Public Sans (with sans-serif fallback)
**Label/Mono Font:** Public Sans is used for labels; no distinct mono family is established in the inspected ERP surfaces.

**Character:** Public Sans keeps commercial data familiar and direct. The hierarchy is compact, with emphasis coming from weight and spacing instead of oversized headings.

### Hierarchy
- **Title** (600, 16px): ERP page and section headings.
- **Body** (400, 14px): Supporting copy, form values, and common operational text.
- **Label** (500, 14px): Form labels use Nuxt UI FormField at its default medium size.
- **Detail** (400, 12px): Secondary table and helper text where denser information is useful.
- **Metric** (600, 20px): Summary figures use tabular numerals for quick comparison.

**The Compact Heading Rule.** Keep ERP page headings at 16px and supporting subtitles brief, as confirmed for this surface.

## Layout

The commercial shell uses a flexible dashboard panel with responsive padding (16px, increasing to 24px and 32px at wider breakpoints) and `pageListMeasureClass` (max-w-5xl). Settings forms use `pageFormMeasureClass` (max-w-3xl). Dense workspaces retain their available width and own scroll regions. Section navigation switches to the full-width selector below 1280px so route labels stay readable beside the sidebar. Important actions remain available in the page header or form action row.

Operational lists combine a compact heading, search and optional filters, a bordered table area, and responsive pagination. Client and subscription lists use one native expandable table at every width. Native column visibility removes secondary mobile columns without phantom widths; the same stable record ID owns expanded facts and actions. Other operational tables retain horizontal scrolling and column visibility controls; forms and action groups wrap for mobile. Keep the existing filter panel as the place for less frequent date and advanced filters.

## Elevation & Depth

The inspected ERP surfaces are primarily flat. Depth comes from subtle elevated theme backgrounds, fine borders, and restrained selected or hover states; the shared dashboard patterns do not establish a decorative shadow vocabulary. Nuxt UI supplies disclosure transitions, and reduced-motion variants are used where present.

## Shapes

Forms and controls use modest rounded corners, commonly in the 6–8px range. Main content panels use a softer 12px radius. Tables and their inner scrolling region use 8px corners; borders stay thin and use semantic theme colors.

## Components

### Buttons
- **Character:** Compact actions use the established Nuxt UI button variants.
- **Primary:** Green semantic primary, typically small in the ERP header; reserve for the main action such as client registration or activation.
- **Secondary / Ghost:** Neutral outline or ghost variants support refresh, navigation, and lower-priority actions.
- **States:** Preserve Nuxt UI hover, active, loading, disabled, and keyboard focus treatments. Use Lucide icons through the existing icon system.

### Inputs / Fields
- **Style:** Use Nuxt UI fields and form-field labels with full-width controls in mobile layouts.
- **Focus:** Retain semantic primary focus indication and visible keyboard focus.
- **Error / Disabled:** Keep field validation and disabled states visible through the component's existing semantic props.

### Cards / Containers
- **Corner Style:** Main ERP panels use gently rounded corners (12px); inner table regions commonly use 8px.
- **Background:** Use the active default surface with a fine default border.
- **Internal Padding:** Common panel padding is 16px, increasing to 24px on wider screens where used.
- **Shadow Strategy:** Use tonal surfaces and borders; no recurring ERP shadow treatment was found.

### Navigation
- **Style:** Use the dashboard navbar and Nuxt UI navigation menu on desktop. On mobile, use the existing full-width section selector. Keep labels clear and route states visible.

### Operational Tables
- **Density:** Compact rows with 8px vertical cell padding and restrained header styling.
- **Responsive behavior:** Clients and subscriptions retain native row expansion and reachable facts/actions on phones. Their secondary columns use native visibility rather than a duplicated mobile list. Other tables use labeled horizontal scrolling and column visibility controls.
- **Filters:** Keep search in the toolbar and reveal optional filters through the established filter panel.

## Do's and Don'ts

### Do:
- **Do** reuse Nuxt UI components and semantic theme tokens.
- **Do** keep headings small, subtitles short, and table rows compact in ERP management.
- **Do** keep client registration and ERP activation easy to find.
- **Do** make forms, navigation, tables, and actions usable on mobile.
- **Do** preserve clear keyboard focus and reduced-motion support.

### Don't:
- **Don't** hard-code light-only or dark-only surfaces where a semantic Nuxt UI token exists.
- **Don't** promote this ERP module's compact heading and table density into an unverified rule for every Opmoni surface.
- **Don't** replace established components with bespoke controls without a demonstrated need.

## Shared system compositions

The local dashboard Profile and Members examples are the reference for field hierarchy, subtle cards and toolbars. Their sample endpoints remain demonstrations. Reuse the installed Nuxt UI 4 components and semantic tokens; the commercial pilot does not prescribe its compact density for every task.

| Purpose | Existing composition | Intentional boundary |
| --- | --- | --- |
| Ordinary operational list | `DataTablePanelList`, `panel.ts`, native Table | One heading/action row, one optional toolbar and one result owner |
| Commercial list | `OfficeErpPanel` adapter, `OfficeErpDataTable` | Same panel slots; compact clients/contracts retain native expansion |
| Dense portfolio/monitoring sheet | `sheet.ts`, `pageTableClass` | Viewport-owned scroll and optional columns remain useful |
| Work grouped rows | Existing Work row utility and controls | Client/process/task hierarchy and eligible leaves remain domain-owned |
| Company financial/operational tables | `erpOperationalTableUi` | Content-sized columns and contained horizontal scroll preserve exact facts |
| Scalar settings/form | `pageFormMeasureClass`, `settingsFieldRowClass`, native FormField | Stack controls on phones; keep long workflows and item grids intact |
| Selection feedback | `DataTableSelectionBar` with domain action slot | Count/label/clear only; no generic query, snapshot or mutation ownership |

`tableChrome.ts` shares row separators and header tone between panel and sheet tables. Width, padding, virtualization and grouped-row behavior stay in the corresponding table family.

Sort controls appear only for supported fields. Company catalogs, commercial resources and Admin Account/user/subscription lists request server ordering before pagination. Admin plans are a complete bounded raw array and sort locally. Reset omits both ordering parameters. Sorting cannot infer new bulk permissions or change CFOP selection. Navigation memory stores validated criteria only, under current authority; URL/history criteria take precedence. It never stores records, selected IDs or editor drafts.

## Editors and inspectors

Office plans, Admin plans/subscriptions and catalog create/edit use native `UModal` with a single mounted form and its footer submit action. Dirty dismissal asks before discarding; pending commands and unknown outcomes retain their existing owner and original operation identity. Programmatic editor hosts return focus to their connected opener after nested overlays leave. Named field feedback remains associated with controls and the first invalid control receives focus.

Catalog read-only detail stays a slideover. Fiscal document inspection, notification/inbox/filter drawers, long enrollment workflows and record pages retain their task-specific containers. A modal migration does not create new authorization, financial commands or provider acceptance.

## Capability and acceptance evidence

The implementation capability ledger is `docs/reviews/2026-10-26-system-ui-implementation/list-capability-ledger.csv`. Unsupported Work/monitoring query or bulk features remain separate work. Reports retain intentional metric layouts, accessibility-only tables and exports; exports remain distinct from mutations. Global users remain read-only and Super Admin has no implicit tenant access.

See the implementation continuation report and current verification record for actual checks and pending gates. Synthetic browser fixtures establish layout/control evidence only. Real PostgreSQL ordering evidence, regular automated suites, browser interaction, and live fiscal/provider acceptance are separate claims. Historical audit reports are planning snapshots, not current acceptance.
