# erp-commercial-access Specification

## Purpose

Allow companies to register and obtain separately governed ERP commercial access without requiring an accounting-office subscription or automatic payment gateway.

## Requirements

### Requirement: ERP registration preserves platform initialization and global identities
Public company registration SHALL be available only after platform bootstrap and explicit ERP release enablement, using the existing CSRF/origin and rate protections. A new-owner registration SHALL atomically create the global identity, company, owner membership and pending ERP subscription; it SHALL create no office Account. Existing-email attempts SHALL require authenticated login rather than overwrite credentials or silently grant membership. Authenticated users SHALL be able to request a new company without replacing their identity. Duplicate company identity SHALL be rejected without implicit association.

#### Scenario: Existing email attempts company signup
- **WHEN** an unauthenticated registration supplies an already existing normalized email
- **THEN** registration does not change the user or create company membership and returns a login-required validation response

#### Scenario: Provisioning fails before commit
- **WHEN** subscription or membership creation fails during registration
- **THEN** no partial new identity, company, owner membership or contract remains

#### Scenario: Platform is not initialized
- **WHEN** an anonymous company signup arrives before platform bootstrap
- **THEN** it is refused and cannot disable initial administrative setup by creating the first ordinary user

### Requirement: Company entitlements are independent of office contracts
The system SHALL keep company plans, subscriptions and known core-module entitlements separate from office plans/subscriptions. At most one current subscription SHALL exist per company. Pending, past_due, canceled or missing company subscriptions SHALL deny ordinary operating writes with 403 `erp_subscription_inactive`, while authorized setup/contract reads retain narrow guards. Disabled modules SHALL deny module operations without granting membership based on subscription. Company suspension SHALL deny company operations independently of contract status.

#### Scenario: Office subscription changes
- **WHEN** an accounting-office subscription is edited or canceled
- **THEN** the associated company contract is not rewritten and independent company members retain their own authorized ERP contract behavior

#### Scenario: Company subscription becomes inactive
- **WHEN** an operator attempts an ordinary company write after company subscription cancellation
- **THEN** the request returns 403 without a business mutation

### Requirement: Commercial provisioning is repeatable and truthful
Company catalog initialization SHALL be insert-only and preserve edited plans and existing subscriptions. New companies SHALL receive pending subscriptions to the basic core-module plan without an invented price, payment confirmation or automatic free trial. Only platform-authorized administration SHALL activate commercial access; owners SHALL read contract status but cannot activate it. Public signup, company contract pages and platform forms SHALL preserve failed inputs, reject invalid module/status values and show retryable service errors.

#### Scenario: Repeated catalog initialization
- **WHEN** the initializer runs twice after a platform administrator customized a company plan
- **THEN** customizations and current contracts remain unchanged and no duplicate subscription or demo company is created

#### Scenario: Owner attempts activation
- **WHEN** a company owner directly submits active status to the platform subscription API
- **THEN** the API denies the change and the company page continues to show the actual pending status
