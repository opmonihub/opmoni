# frontend-navigation-lifecycle Specification

## Purpose

Keep navigation, initial rendering, list state and asynchronous reads coherent across the existing frontend without weakening session, Account or Company isolation.

## Requirements

### Requirement: Ordinary internal navigation preserves its owning shell
Internal links, module tabs and browser history SHALL update the destination and active navigation without reloading the document or remounting an unchanged owning shell. Nested destinations SHALL identify their owning navigation entry. Existing authentication, logout and selected-authority transitions SHALL retain their security-driven reload behavior.

#### Scenario: Commercial tabs change without a document reload
- **WHEN** an authorized user follows Faturas, Assinaturas and Planos within the office workspace and then uses Back
- **THEN** the document and owning shell remain mounted, the destination content follows the route, and exactly the matching entry is identified as current

#### Scenario: A context transition requires a fresh document
- **WHEN** the existing session flow changes the selected office or company
- **THEN** its deliberate reload and private-state invalidation remain effective rather than being replaced by ordinary tab navigation

### Requirement: Initial rendering and later navigation preserve truthful data
Hydration SHALL preserve the authorized server-rendered result, including populated, empty, absent and failed states, without substituting default values as a successful result or issuing a duplicate initial read. Later navigation and explicit retry SHALL retain each page's existing freshness requirements. Initial read failure SHALL remain distinguishable from a confirmed empty or absent result.

#### Scenario: Hydrate a populated settings resource
- **WHEN** an authorized direct entry includes an existing office certificate or WhatsApp channel in its server-rendered result
- **THEN** the hydrated page retains that resource and does not claim it is absent or disconnected because of a default value

#### Scenario: Hydrate an absent or failed resource
- **WHEN** the initial result is an allowed absence or a service failure
- **THEN** hydration preserves the appropriate absent or retryable-error state without fabricating a successful resource

### Requirement: Safe reads have bounded lifecycle ownership
An unchanged logical read SHALL have one initialization owner, and concurrent consumers of the same logical read SHALL share its pending result. An obsolete safe read owned only by a departed page or superseded filter SHALL be cancelled before dispatch when queued, or signalled for cancellation when already running. Its cancellation SHALL NOT replace newer results, emit failure feedback for the departed view, or cancel another current consumer's work. Existing session serialization, authority assertions and mutation recovery SHALL remain intact; navigation SHALL NOT infer that aborting a mutation reversed its server effects.

#### Scenario: Leave a page with a queued secondary catalog read
- **WHEN** an administrator leaves Assinaturas for Usuários while the former page has an unneeded catalog read queued
- **THEN** the obsolete catalog read is removed before dispatch and does not delay the current user-list read, while the transport retains its serialized admission rules

#### Scenario: Authority changes while a shared read is pending
- **WHEN** the actor, selected Account, selected Company, session or effective grants change during a read
- **THEN** the prior result cannot populate current protected state and cancellation cannot clear a newer authority's loading state

#### Scenario: A command has an unknown outcome
- **WHEN** navigation occurs after a mutating request may have reached the server
- **THEN** the existing command identifier and recovery flow remain authoritative and navigation does not blindly resend or declare rollback

### Requirement: Existing list navigation state survives same-authority round trips
Real portfolio, Fiscal, Monitoring, Work, office-commercial, Admin and company ERP list surfaces SHALL retain their supported search, filters, page size and selected page when the user leaves and returns within the same authority and browser application session. Explicit valid URL parameters and browser history SHALL take precedence over remembered state. A changed filter SHALL reset pagination once, and server metadata SHALL bound the restored page. Invalid query values SHALL use existing validated defaults. Remembered state SHALL NOT restore bulk selections, financial commands, credentials or mutation drafts, and SHALL be discarded on authority changes or logout.

#### Scenario: Return to a filtered list
- **WHEN** an office administrator filters Clientes ERP, visits Planos and returns under the same authority
- **THEN** the prior list criteria are restored, the server query agrees with them, and counts remain authoritative

#### Scenario: An explicit URL conflicts with remembered criteria
- **WHEN** valid direct URL or history parameters specify criteria different from the remembered list state
- **THEN** the displayed controls and requested data follow those explicit parameters without duplicate reset-driven queries

#### Scenario: A remembered page no longer exists
- **WHEN** current server pagination reports fewer pages than the restored selection
- **THEN** the list recovers to a valid server page and does not present obsolete rows or a fabricated successful empty state

#### Scenario: Another authority opens the same list
- **WHEN** the actor, session, Account, Company or effective permissions change
- **THEN** previous private list state and results are cleared before the new authority can display or act on them

### Requirement: Unrelated same-context updates preserve editor drafts
An unchanged editor SHALL retain unsaved fields and named validation feedback through unrelated same-context resource updates and query/hash changes that do not change its edited entity. A successful save SHALL reconcile the fields it owns without overwriting another editor's dirty fields. Conflicting updates to dirty fields SHALL retain the draft and require an explicit review rather than silently accepting a remote value. Authority loss SHALL still clear private drafts and prevent stale feedback or writes.

#### Scenario: Navigation changes only an anchor
- **WHEN** an authorized user has an unsaved settings field and changes only a query or hash that does not select another editor entity
- **THEN** the same editor and draft remain available without an automatic reload or silent replacement

#### Scenario: An unrelated settings card saves
- **WHEN** the company profile contains dirty fields and another card successfully updates the default product tax profile
- **THEN** the other card's confirmed value is reflected while dirty company-profile fields and their feedback remain unchanged

#### Scenario: A protected editor loses authority
- **WHEN** the selected tenant or effective write authority changes while the editor is dirty or a save is pending
- **THEN** the prior private draft is cleared or disabled under the existing access contract and late results cannot restore it
