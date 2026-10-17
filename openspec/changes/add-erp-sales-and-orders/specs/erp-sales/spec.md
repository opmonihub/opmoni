## Purpose

Provide complete company quote, order, counter-sale and return workflows with authoritative totals and atomic stock, financial and cash posting.

## ADDED Requirements

### Requirement: Quotes orders and sales preserve authoritative totals
The system SHALL distinguish quotes, orders, draft sales and posted sales. Quotes/orders SHALL not claim posting, stock reservation or provider authorization. Prices, discounts, units, taxes and totals SHALL be calculated/validated server-side and saved as snapshots; unsupported precision or unauthorized discounts SHALL return field errors. Numbering SHALL remain unique for its company/establishment/document scope. Company foreign references SHALL not be accepted.

#### Scenario: Browser changes a total
- **WHEN** a sale form submits a total inconsistent with authoritative line calculations
- **THEN** the API rejects the discrepancy and no sale, stock or financial posting occurs

#### Scenario: Order precedes stock change
- **WHEN** an order exists while available stock later changes
- **THEN** its form does not promise a reservation and stock is revalidated at sale posting

### Requirement: Posting and returns preserve commercial stock and money consistency
Posting SHALL commit the sale, stock consumption, obligations, allocations and applicable cash effects atomically. Duplicate equal requests SHALL reuse the effective operation and unequal idempotency-key reuse SHALL conflict. Returns SHALL reference actual posted lines and remaining quantities, atomically compensating stock and finance without erasing history. Commercial correction SHALL not claim that a fiscal document was canceled.

#### Scenario: Competing sale posts
- **WHEN** separate requests attempt to post the same draft sale
- **THEN** one effective sale posts and no duplicate stock, finance or cashier effect occurs

#### Scenario: Return for fiscally authorized sale
- **WHEN** a permitted commercial return is recorded for a sale with an authorized invoice
- **THEN** commercial compensation is attributable and the fiscal document retains its actual provider state until a separate accepted fiscal action

### Requirement: Commercial screens expose complete core workflows
The Nuxt workspace SHALL provide quotes, orders, counter-sale, sale details, payments and returns with company permissions, confirmation before posting and input preservation on failure. Sale status and fiscal status SHALL remain distinct. The fiscal consumer SHALL receive the approved immutable sale snapshot instead of recalculating from later catalog edits. Failed requests SHALL not show fabricated paid or authorized states.

#### Scenario: Network fails during post
- **WHEN** the user loses the posting response after the server may have committed
- **THEN** the interface can reconcile/retry using the same operation identity without creating another sale

#### Scenario: Catalog changes before fiscal consumption
- **WHEN** a posted sale is invoiced after its catalog prices changed
- **THEN** the fiscal request uses the posted sale snapshot and not the newer product prices
