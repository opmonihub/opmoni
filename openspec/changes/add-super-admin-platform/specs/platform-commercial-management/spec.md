## Purpose

Provide persistent administrative plan and subscription data so the corresponding Super Admin pages and Account plan column work for both existing and newly provisioned Accounts.

## ADDED Requirements

### Requirement: Idempotent initial catalog and subscriptions
The catalog SHALL initialize `basico`/Básico with users=5 and clients=50, `profissional`/Profissional with users=20 and clients=500, and `empresarial`/Empresarial with unlimited values; absent monitoring limits SHALL be unlimited. Reinitialization SHALL create missing defaults without overwriting edited plans. Each existing Account without a subscription SHALL receive one active Básico subscription. Initial onboarding and new platform Account creation SHALL create one active Básico subscription atomically with the Account. An Account SHALL have at most one subscription. Repeated initialization/backfill SHALL preserve existing subscriptions, activation records, identities, and memberships and SHALL not introduce demonstration users or Accounts.

#### Scenario: Repeat initialization after plan editing
- **WHEN** the catalog/backfill is rerun after an administrator edits a plan and subscription
- **THEN** those edits remain, missing records are created, and no duplicate subscription appears

#### Scenario: Account provisioning fails
- **WHEN** Account creation or owner activation issuance cannot commit
- **THEN** no orphan subscription or partially created Account remains

### Requirement: Plan list and complete limit editing
GET `/api/v1/admin/plans` SHALL return `{data:[{id,slug,name,limits}]}` ordered by ID. PUT `/api/v1/admin/plans/{id}` SHALL accept a 2–255 character name and a complete limits object with exactly `users,clients,monitorings`, each a nonnegative integer or null. Null SHALL explicitly remove a previous finite limit and display as unlimited. Slugs SHALL remain unique and immutable through this editor. Unknown keys, fractional/negative limits, and invalid payloads SHALL return 422 without partial updates. The page SHALL provide local name/slug search, column controls, an edit slideover, and filtered/total counts; it SHALL not add plan creation/deletion UI.

#### Scenario: Change a finite limit to unlimited
- **WHEN** the administrator clears the clients limit and saves null
- **THEN** the stored limit is cleared and subsequent loads display “Ilimitado”

### Requirement: Subscription list and editing
GET `/api/v1/admin/subscriptions` SHALL provide paginated resources with ID, status, Account ID/name, and plan ID/slug/name, searchable by Account/plan name and filterable by `active|past_due|canceled`. PUT `/api/v1/admin/subscriptions/{id}` SHALL accept an existing plan ID and a valid status and update both atomically. `/admin/assinaturas` SHALL show ID, Account, plan, status, and edit actions; its slideover SHALL use real plan choices and preserve failed form inputs. Missing plan/catalog data SHALL produce retry feedback rather than an enabled invalid form.

#### Scenario: Invalid plan selection
- **WHEN** a submitted plan no longer exists
- **THEN** the API returns field validation errors and preserves the previous subscription

### Requirement: Administrative commercial metadata only
Plan limits and subscription statuses SHALL be administrative metadata in this change. Changing subscription to past_due or canceled SHALL not implicitly suspend an Account, invalidate memberships, block owner activation, or introduce billing/collection/provider calls. Account suspension SHALL remain the explicit operational access control. No user/client/monitoring quota enforcement or payment gateway SHALL be introduced.

#### Scenario: Mark a subscription canceled
- **WHEN** an administrator changes an active Account's subscription to canceled
- **THEN** the commercial status changes while operational Account access remains governed by its own status and memberships
