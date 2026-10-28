## MODIFIED Requirements

### Requirement: The commercial workspace preserves the dashboard reference
The `/gestao-erp` office workspace SHALL expose overview, plans, portfolio clients, subscriptions, invoices, receipts and settings through coherent sidebar, search and local navigation. Theme, typography, spacing, tables, forms and overlays SHALL follow the project's `.dashboard/app` reference and retain existing contrast/accessibility improvements. Equivalent tasks SHALL use the shared system composition; bounded commercial plan editing SHALL use a responsive modal, and client/subscription detail expansion SHALL preserve complete desktop/mobile operations. Longer enrollment and financial document workflows SHALL retain their appropriate page composition. All proposed components SHALL have documented official API/example and installed-version compatibility evidence. The workspace SHALL show one page identity and preserve navigation, keyboard access and readable content in light/dark desktop and mobile layouts. Old `/admin/erp/*` links SHALL not remain the commercial entry for ordinary office administrators.

#### Scenario: Office administrator enters commercial management
- **WHEN** an authorized office administrator opens `/gestao-erp/planos` directly
- **THEN** the office dashboard renders that office's plans without requiring access to an `/admin/*` route or selecting a Company

#### Scenario: Narrow-screen commercial list
- **WHEN** the workspace is used at a 320-pixel width
- **THEN** actions, status explanations and navigation remain reachable and any wide-table scrolling is contained within the table frame

#### Scenario: Commercial plan editor uses the common modal form
- **WHEN** an authorized office administrator opens an allowed plan edit
- **THEN** a responsive modal retains current module/revision fields, errors, busy state and original-command recovery without changing commercial eligibility


## ADDED Requirements

### Requirement: Commercial resource ordering is server authoritative
Office client, offer, contract, invoice and receipt lists SHALL support documented resource-allowlisted ordering fields and ascending/descending directions before server pagination. The ordering SHALL apply after current selected-office authorization and supported criteria, use a stable identifier tie-breaker and preserve exact monetary/date semantics. Omitted ordering SHALL retain existing defaults and envelopes. Invalid ordering SHALL return 422 field errors without broadening the resource filter allowlist or introducing bulk commercial commands.

#### Scenario: Order invoices by due date across pages
- **WHEN** an authorized office administrator selects due-date ordering for filtered invoices
- **THEN** all matching office invoices are ordered before paging, with stable equal-date ties and unchanged document/balance facts

#### Scenario: Reject unsupported commercial sorting
- **WHEN** an authorized caller requests an ordering field not allowed for that commercial resource
- **THEN** it receives field validation feedback and the interface does not claim that a differently ordered cached page is a successful current query

#### Scenario: Office changes while sorting
- **WHEN** the office or effective read authority changes during a sorted list request
- **THEN** prior results cannot populate the new office and no new read or write permission is inferred

