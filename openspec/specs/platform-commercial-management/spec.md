# platform-commercial-management Specification

## Purpose

Maintain platform catalog and office subscriptions while describing their implemented operational write and record-creation limits.

## Requirements

### Requirement: Additive default subscription provisioning
New Accounts SHALL receive one active Básico subscription and default departments through their creation transaction. Existing Accounts SHALL retain additive initialization compatibility and receive missing subscriptions through repeatable bounded `platform:initialize` backfill. Insert-only catalog initialization SHALL create missing `basico`/Básico users=5 clients=50, `profissional`/Profissional users=20 clients=500 and `empresarial`/Empresarial unlimited without overwriting edits; monitoring defaults SHALL be unlimited. Existing subscriptions, identities, memberships and activations SHALL remain intact, and no demo users/Accounts SHALL be added. Subscription identity SHALL be unique per Account, Plan references restricted and statuses constrained to active/past_due/canceled while preserving existing Account cascade behavior. Provisioning failure SHALL roll back the Account/subscription/activation together. Independent PostgreSQL/Redis processes SHALL establish repeat/race safety rather than SQLite or synchronous simulations.

#### Scenario: Repeat Account default initialization
- **WHEN** defaults are initialized for an office that already has a subscription
- **THEN** the existing subscription is preserved and no duplicate is added

#### Scenario: Edited catalog and concurrent backfill
- **WHEN** initialization is repeated or raced after a catalog/subscription edit
- **THEN** edits remain, only missing defaults are created and each Account retains at most one subscription

#### Scenario: Provisioning rollback
- **WHEN** owner issuance or subscription creation cannot commit
- **THEN** no partial Account, subscription or activation remains

### Requirement: Catalog and subscription editing preserve actual APIs
Super Admin SHALL list/create/show/update plans through `/api/v1/admin/plans` and list/show/update subscriptions through `/api/v1/admin/subscriptions`. Plan listing SHALL return an ID-ordered raw array, and detail/mutations SHALL retain raw Plan resources. Updates SHALL preserve partial top-level input; a supplied name SHALL be 2–255 characters and slug edits SHALL be rejected. A supplied limits object SHALL replace the previous object and normalize missing known keys to null, accepting existing empty/sparse objects for compatibility. Stored/editor limits output SHALL have exactly users,clients,monitorings. ERP resale settings SHALL be a separately validated top-level erp_commercial object, containing enabled, licence_limit and module_keys; omission SHALL preserve its previous value and SHALL NOT alter legacy limits. A supplied resale object SHALL contain all three keys, reject unknown keys, require a boolean enabled, a null or nonnegative integer licence_limit, and distinct known module keys. Null capacity SHALL mean unlimited only with explicitly enabled resale. Existing plans without resale settings SHALL have resale disabled. Each legacy limit value SHALL be null or a nonnegative integer, rejecting coercible floats, fractions, negative values and unknown keys with 422 and no partial update. Null SHALL mean unlimited and clear finite values. The Plan UI SHALL send all three nullable keys and retain local search/columns/edit-only behavior; it SHALL not imply deletion or billing integration. Subscription resource pagination SHALL retain meta.current_page and existing Account/plan search/status filters. Subscription updates SHALL atomically validate existing plan_id and active/past_due/canceled status; forms SHALL retain inputs/errors, use real catalog choices, retry catalog failure and prevent duplicate submits.

#### Scenario: Invalid subscription plan
- **WHEN** an administrator submits a nonexistent plan ID
- **THEN** the API returns 422 and keeps the prior subscription

#### Scenario: Empty or sparse replacement limits
- **WHEN** an existing caller supplies limits={} or a subset of known keys
- **THEN** missing known keys normalize to null and previously finite omitted limits are cleared

#### Scenario: Strict invalid limit rejection
- **WHEN** a limit is negative, fractional, a coercible float or an unknown key
- **THEN** validation returns 422 without changing the Plan

#### Scenario: Explicit unlimited editor output
- **WHEN** the administrator clears a finite limit and saves through the editor
- **THEN** all three known keys are sent with explicit nullable values and reload displays unlimited for cleared limits

#### Scenario: Legacy plan editor omits ERP settings
- **WHEN** a valid existing caller updates only name or the three original limit keys
- **THEN** ERP resale settings remain unchanged and the original raw Plan response shape remains compatible

#### Scenario: Invalid resale allowance
- **WHEN** an administrator supplies an unknown module, fractional capacity or incomplete resale object
- **THEN** validation returns 422 and neither legacy limits nor resale settings change

### Requirement: Inactive subscriptions block tenant mutation
Ordinary tenant state-changing requests SHALL require an active subscription in addition to active Account membership. A missing, past-due or canceled subscription SHALL produce 403 with subscription_inactive; authorized read-only tenant requests SHALL remain available. Platform administration, identity, logout and membership-only Account switching SHALL retain their separate access contracts. Subscription edits SHALL not rewrite Account status, memberships or activation, nor introduce payment/collection calls; that preservation SHALL not waive ordinary tenant write gates. A dedicated ERP commercial maintenance boundary SHALL permit real administrators of an active Account to maintain previously accepted seller contracts, including continuing invoices, unchanged-term renewals, price-only amendments, manual receipts/reversals, suspension/resumption and cancellation, even after resale entitlement or the office subscription becomes inactive. This exception SHALL NOT admit new contracts, new ownership invitations, added licences, expanded module/capacity grants or unrelated tenant writes. Account suspension and session/membership checks SHALL remain mandatory.

#### Scenario: Canceled subscription write
- **WHEN** a member of an active Account with canceled subscription submits an ordinary tenant mutation outside the bounded ERP commercial maintenance operations
- **THEN** the request receives 403 subscription_inactive without mutation

#### Scenario: Read after commercial cancellation
- **WHEN** an otherwise authorized member reads the same Account's business data
- **THEN** the read is not rejected solely because of subscription status and the Account/memberships remain unchanged

#### Scenario: Office loses the right to sell after accepting contracts
- **WHEN** its active Account administrator records a receipt or continues billing for an existing accepted ERP contract
- **THEN** the bounded commercial operation succeeds under its own guard while new sales and unrelated inactive-subscription mutations remain denied

#### Scenario: Suspended office attempts maintenance
- **WHEN** a member of a suspended Account requests the commercial maintenance exception
- **THEN** access is denied and no exception bypasses Account suspension

### Requirement: Users and clients obey plan creation limits
Creating members or clients SHALL enforce the corresponding Plan limit and return 422 when reached. Null or omitted limits SHALL be unlimited. These limits SHALL not be described as payment processing or as enforcement for every monitoring obligation.

#### Scenario: Client limit reached
- **WHEN** an Account has reached its clients limit and an operator creates another client
- **THEN** validation returns 422 without creating the client
