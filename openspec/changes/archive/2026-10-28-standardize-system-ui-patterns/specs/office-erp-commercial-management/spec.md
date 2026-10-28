## MODIFIED Requirements

### Requirement: The commercial workspace preserves the dashboard reference
The `/gestao-erp` office workspace SHALL expose overview, plans, ERP customers, subscriptions, invoices, receipts and settings through coherent sidebar, search and local navigation. Commercial plan management SHALL appear as "Planos" at `/gestao-erp/planos`, and its editor, actions, alerts and overview shortcut SHALL use plan terminology instead of "Ofertas". Existing plan routes/data, command recovery and valid invitation links SHALL remain compatible. The primary customer action SHALL be "Cadastrar cliente ERP", with an alternative to select an existing portfolio Client. Customer rows SHALL show customer identity, plan, subscription/trial situation, next billing date or trial end, and contextual subscription actions. The office SHALL be able to copy its configured ERP trial link. Theme, typography, spacing, tables, forms and overlays SHALL follow the project dashboard reference and existing Nuxt UI conventions. The workspace SHALL show one page identity without repeating the selected-office name in its header, preserve keyboard access and readable light/dark desktop/mobile layouts, and isolate print routes from the office shell. Authentication, Company selection and ordinary office-admin access SHALL not depend on old platform-admin bookmarks. Business eligibility restrictions SHALL name the unmet condition and a relevant next action without generic service-failure text or a useless retry button; transient failures SHALL retain a retry action and non-solid error treatment.

Equivalent tasks SHALL use the shared system composition; bounded commercial plan editing SHALL use a responsive modal, and client/subscription detail expansion SHALL preserve complete desktop/mobile operations. Longer enrollment and financial document workflows SHALL retain their appropriate page composition. All proposed components SHALL have documented official API/example and installed-version compatibility evidence. Existing contrast/accessibility improvements SHALL remain intact.

#### Scenario: Office administrator enters commercial management
- **WHEN** an authorized office administrator opens `/gestao-erp/planos` directly
- **THEN** the office dashboard renders that office's plans, marks "Planos" active in sidebar and local navigation, and offers the "Novo plano" action according to existing commercial eligibility without requiring access to an `/admin/*` route or selecting a Company

#### Scenario: Narrow-screen commercial list
- **WHEN** the workspace is used at a 320-pixel width
- **THEN** actions, status explanations and navigation remain reachable and any wide-table scrolling is contained within the table frame

#### Scenario: Restricted settings are not a loading failure
- **WHEN** the actor lacks real office admin membership or the selected Account is suspended
- **THEN** the workspace explains the actual eligibility restriction, disables unauthorized changes and does not label it a retryable settings-fetch failure

#### Scenario: Customer-first workspace on mobile
- **WHEN** an office admin opens customer management at 320px in either theme
- **THEN** registration, portfolio selection, trial-link copy, plan management and row subscription actions remain keyboard-reachable without duplicate office identification or an Offers entry

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
