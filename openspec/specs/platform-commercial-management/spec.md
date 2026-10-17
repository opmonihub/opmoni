# platform-commercial-management Specification

## Purpose

Maintain platform catalog and office subscriptions while describing their implemented operational write and record-creation limits.

## Requirements

### Requirement: Additive default subscription provisioning
New Accounts SHALL receive one active Básico subscription and default departments through their creation transaction. Existing Accounts SHALL receive missing defaults through the additive initialization migration without resetting their current subscriptions. Básico defaults SHALL have users=5 and clients=50; Profissional defaults SHALL have users=20 and clients=500; missing/null limits SHALL mean unlimited. Subscription identity SHALL be unique per Account.

#### Scenario: Repeat Account default initialization
- **WHEN** defaults are initialized for an office that already has a subscription
- **THEN** the existing subscription is preserved and no duplicate is added

### Requirement: Catalog and subscription editing preserve actual APIs
Super Admin SHALL list/create/show/update plans through `/api/v1/admin/plans` and list/show/update subscriptions through `/api/v1/admin/subscriptions`. Plan listing SHALL return an ID-ordered array; updates SHALL accept name and limits using the existing partial update contract. Subscription updates SHALL validate existing `plan_id` and `active|past_due|canceled` status. The plan edit page SHALL not imply plan deletion or billing-provider integration.

#### Scenario: Invalid subscription plan
- **WHEN** an administrator submits a nonexistent plan ID
- **THEN** the API returns 422 and keeps the prior subscription

### Requirement: Inactive subscriptions block tenant mutation
Tenant state-changing requests SHALL require an active subscription in addition to active Account membership. A missing, past-due, or canceled subscription SHALL produce 403 with `subscription_inactive`; authorized read-only tenant requests SHALL remain available. Platform administration, identity, logout, and membership-only Account switching SHALL retain their separate access contracts.

#### Scenario: Canceled subscription write
- **WHEN** a member of an active Account with canceled subscription submits a tenant mutation
- **THEN** the request receives 403 `subscription_inactive` without mutation

#### Scenario: Read after commercial cancellation
- **WHEN** an otherwise authorized member reads the same Account's business data
- **THEN** the read is not rejected solely because of subscription status

### Requirement: Users and clients obey plan creation limits
Creating members or clients SHALL enforce the corresponding plan limit and return 422 when reached. Null or omitted limits SHALL be unlimited. These limits SHALL not be described as payment processing or as enforcement for every monitoring obligation.

#### Scenario: Client limit reached
- **WHEN** an Account has reached its clients limit and an operator creates another client
- **THEN** validation returns 422 without creating the client
