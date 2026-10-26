# erp-sales Specification

## Purpose

Provide complete company quote, order, counter-sale and return workflows with authoritative totals and atomic stock, financial and cash posting.

## Requirements

### Requirement: Quotes orders and sales preserve authoritative totals
The system SHALL distinguish quotes, orders, draft sales and posted sales. Quotes/orders SHALL not claim posting, stock reservation or provider authorization. Prices, discounts, units, taxes and totals SHALL be calculated/validated server-side and saved as snapshots; unsupported precision or unauthorized discounts SHALL return field errors. Numbering SHALL remain unique for its company/establishment/document scope. Company foreign references SHALL not be accepted. Inherited product tax facts and applicable operational CFOP facts SHALL resolve under coherent configuration revisions before snapshotting; a changed profile, nature default or exception SHALL require an explicit reviewed refresh for a saved draft rather than silently alter authoritative totals or classifications.

#### Scenario: Browser changes a total
- **WHEN** a sale form submits a total inconsistent with authoritative line calculations
- **THEN** the API rejects the discrepancy and no sale, stock or financial posting occurs

#### Scenario: Order precedes stock change
- **WHEN** an order exists while available stock later changes
- **THEN** its form does not promise a reservation and stock is revalidated at sale posting

#### Scenario: Profile changes after a draft is reviewed
- **WHEN** the operator continues a saved draft whose inherited profile/default has a newer revision
- **THEN** the interface shows the existing facts and a reviewable refresh path, and does not silently replace its taxes, classifications or totals

### Requirement: Posting and returns preserve commercial stock and money consistency
Posting SHALL commit the sale, stock consumption, obligations, allocations and applicable cash effects atomically. Duplicate equal requests SHALL reuse the effective operation and unequal idempotency-key reuse SHALL conflict. Returns SHALL reference actual posted lines and remaining quantities, atomically compensating stock and finance without erasing history. Commercial correction SHALL not claim that a fiscal document was canceled.

#### Scenario: Competing sale posts
- **WHEN** separate requests attempt to post the same draft sale
- **THEN** one effective sale posts and no duplicate stock, finance or cashier effect occurs

#### Scenario: Return for fiscally authorized sale
- **WHEN** a permitted commercial return is recorded for a sale with an authorized invoice
- **THEN** commercial compensation is attributable and the fiscal document retains its actual provider state until a separate accepted fiscal action

### Requirement: Commercial screens expose complete core workflows
The Nuxt workspace SHALL provide quotes, orders, counter-sale, sale details, payments and returns with company permissions, confirmation before posting and input preservation on failure. Sale status and fiscal status SHALL remain distinct. The fiscal consumer SHALL receive the approved immutable sale snapshot instead of recalculating from later catalog edits. Failed requests SHALL not show fabricated paid or authorized states. Customer and product selection SHALL support bounded searchable results and preserve selected references outside the current result page, using meaningful product identity rather than an ambiguous repeated variant name. Native default customer, commercial numbering and layout SHALL be applied only under their explicit same-company contracts and SHALL not fabricate fiscal recipient facts or authorization.

#### Scenario: Network fails during post
- **WHEN** the user loses the posting response after the server may have committed
- **THEN** the interface can reconcile/retry using the same operation identity without creating another sale

#### Scenario: Catalog changes before fiscal consumption
- **WHEN** a posted sale is invoiced after its catalog prices changed
- **THEN** the fiscal request uses the posted sale snapshot and not the newer product prices

#### Scenario: Search a large catalog and edit a saved selection
- **WHEN** an operator searches by product name or SKU in a multi-page catalog or opens an existing draft whose selection is outside the visible result page
- **THEN** bounded matching results and the hydrated selection remain usable without loading the entire catalog or replacing the existing selection

#### Scenario: Reference search fails
- **WHEN** a customer/product search fails during commercial editing
- **THEN** current selections and input remain available with retry, no failed lookup is treated as an empty catalog, and no first-option substitution occurs

### Requirement: Fiscal intent is explicit before immutable commercial posting
An operator SHALL explicitly distinguish a commercial sale without declared fiscal intent from a sale intended to supply a supported NF-e/NFC-e source. Fiscal intent SHALL select the applicable model and trigger model-specific readiness checks before the sale becomes immutable. Missing required classifications, party facts or operation facts SHALL return actionable field feedback without posting stock/finance effects. A basic product or non-fiscal sale SHALL not acquire universal fiscal requirements merely because a tax profile exists. Changing intent or refreshing inherited tax facts SHALL require review and preserve current pricing/discount authority and idempotent posting.

#### Scenario: Fiscal-intended sale has incomplete source facts
- **WHEN** an operator attempts to post a sale declared for a fiscal model with missing required tax/recipient facts
- **THEN** the operation returns field-level readiness feedback before immutable posting and retains the draft for correction

#### Scenario: Basic commercial operation has no fiscal intent
- **WHEN** an otherwise valid authorized sale has no declared fiscal intent and uses a tax-incomplete product
- **THEN** existing commercial validation governs posting without falsely claiming that the sale is ready for fiscal issuance

#### Scenario: Historical posted source lacks fiscal classifications
- **WHEN** an operator later requests issuance from a historical posted sale lacking required frozen classifications
- **THEN** the interface explains the source limitation and supported correction path, and does not repair the snapshot from today's product/profile or bypass source-equality checks

### Requirement: Operational CFOP resolution is deterministic and reviewable
The server SHALL resolve each eligible goods Sales line from an authorized explicit line choice, then an active applicable product exception within the selected nature, then that nature's explicit default, otherwise remain unresolved. Every used candidate SHALL belong to the active selected nature and satisfy the supported company, establishment, direction, origin/destination and document-model context. An invalid explicit choice or an active exception with an invalid target SHALL produce field-level feedback rather than fall through to another code. An explicit valid higher-priority choice SHALL not be replaced by a lower-priority rule. Product tax profiles SHALL remain a separate source of tax facts and SHALL not determine operational CFOP precedence.

Resolution SHALL expose effective code, rule source, nature/rule identity and configuration revision. Missing required context SHALL remain unresolved without invented fiscal facts. Fiscal-intended posting SHALL require resolved applicable CFOP facts before stock/finance effects; an otherwise valid basic operation without declared fiscal intent SHALL retain its existing contract. No automatic purchase-line resolution or new fiscal operation kind SHALL be introduced by this requirement.

#### Scenario: Authorized line choice overrides an exception
- **WHEN** an operator explicitly selects a valid allowed CFOP for a line whose product has another applicable exception
- **THEN** the explicit choice is retained and the resolution identifies it as the source

#### Scenario: Product exception overrides the nature default
- **WHEN** a line has no explicit choice and its product has an active valid exception in the selected nature
- **THEN** the exception's CFOP is resolved and its identity and revision are returned instead of the nature default

#### Scenario: No exception applies
- **WHEN** a line has no explicit choice and no active product exception applies
- **THEN** the valid nature default is resolved without depending on allowed-choice list order

#### Scenario: Explicit choice or active exception target is invalid
- **WHEN** a selected line code is foreign, archived or incompatible, or an applicable active exception targets an invalid choice
- **THEN** the operation retains its draft and returns field errors without silent default substitution or posting effects

#### Scenario: Required fiscal context is incomplete
- **WHEN** a fiscal-intended line lacks the selected nature or origin/destination facts required for supported CFOP applicability
- **THEN** readiness reports the unresolved fields before posting instead of inferring a classification from the product or tax profile

#### Scenario: Rule configuration changes after review
- **WHEN** an operator posts or refreshes a draft reviewed under an older nature/default/exception revision
- **THEN** the unchanged reviewed facts remain available and stale relevant preconditions produce a recoverable conflict before effects, requiring an explicit reviewed refresh

#### Scenario: Effective exception differs from the catalog default
- **WHEN** an eligible line using a valid product exception is posted
- **THEN** its effective CFOP and resolution provenance are frozen with the operation and later consumers do not replace them with the nature's current default
