## MODIFIED Requirements

### Requirement: Inactive subscriptions block tenant mutation
Ordinary tenant state-changing requests SHALL require an active subscription in addition to active Account membership. A missing, past-due or canceled subscription SHALL produce 403 with subscription_inactive; authorized read-only tenant requests SHALL remain available. Platform administration, identity, logout and membership-only Account switching SHALL retain their separate access contracts. Subscription edits SHALL not rewrite Account status, memberships or activation, nor introduce payment/collection calls; that preservation SHALL not waive ordinary tenant write gates. A dedicated ERP commercial maintenance boundary SHALL permit real administrators of an active Account to maintain previously accepted seller contracts, including continuing invoices, unchanged-term renewals, price-only amendments, manual receipts/reversals, suspension/resumption and cancellation, even after resale entitlement or the office subscription becomes inactive. This exception SHALL NOT admit new contracts, new ownership invitations, added licences, expanded module/capacity grants or unrelated tenant writes. Account suspension and session/membership checks SHALL remain mandatory.

For existing accepted ERP contracts, bounded maintenance SHALL additionally include pure module reductions and Company-member access servicing, including recipient-confirmed ordinary-member invitations, permission/scope updates, membership removal and authorized credential recovery. These actions SHALL retain their own current-relationship and protected-identity checks and SHALL create no new Company licence or operating permission for the office actor. New Company registration/assignment, module additions and ordinary portfolio edits SHALL retain active-subscription and applicable capacity/creation-limit gates.

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
