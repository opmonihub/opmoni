## MODIFIED Requirements

### Requirement: ERP registration preserves platform initialization and global identities
Public company registration SHALL be available only after platform bootstrap and explicit ERP release enablement, using the existing CSRF/origin and rate protections. A new-owner registration SHALL atomically create the global identity, company, owner membership and pending ERP subscription; it SHALL create no office Account. Existing-email attempts SHALL require authenticated login rather than overwrite credentials or silently grant membership. Authenticated users SHALL be able to request a new company without replacing their identity. Duplicate company identity SHALL be rejected without implicit association. A guarded seller invitation SHALL provide a separate owner-confirmed setup path without opening anonymous public registration or requiring an already selected Company. An existing global identity SHALL authenticate before acceptance; a new identity SHALL be established only by the intended owner. Existing Companies SHALL require their current owner to confirm association. No office administrator SHALL acquire Company ownership by issuing an invitation.

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
The system SHALL keep company plans, subscriptions and known core-module entitlements separate from office plans/subscriptions. At most one current subscription SHALL exist per company. Pending, past_due, suspended, canceled or missing company subscriptions SHALL deny ordinary operating writes with 403 `erp_subscription_inactive`, while authorized setup/contract reads retain narrow guards. Disabled modules SHALL deny module operations without granting membership based on subscription. Company suspension SHALL deny company operations independently of contract status.

For a seller-managed Company, its accepted commercial terms SHALL supply its retail plan identity and agreed module snapshot while the Company retains one current ERP subscription. Losing seller resale eligibility or changing the office subscription SHALL NOT remove that accepted licence, alter its invoices, clear its module snapshot or cancel Company access. The Company's own contract cancellation, manual suspension and configured delinquency hold SHALL govern operating-write eligibility. An overdue invoice alone SHALL NOT set an access-denying status before the configured policy and grace apply. Accounting delegation SHALL remain separate from a commercial licence.

#### Scenario: Office subscription changes
- **WHEN** an accounting-office subscription is edited or canceled
- **THEN** the associated company contract is not rewritten and independent company members retain their own authorized ERP contract behavior

#### Scenario: Company subscription becomes inactive
- **WHEN** an operator attempts an ordinary company write after company subscription cancellation
- **THEN** the request returns 403 without a business mutation

#### Scenario: Seller plan no longer permits the sold module
- **WHEN** a platform change removes a module from the office's allowance for future sales
- **THEN** existing accepted contract grants remain intact and selling or amending a contract to add that module is denied

#### Scenario: Automatic debt hold is cleared
- **WHEN** all qualifying debt is settled while no manual or security hold exists
- **THEN** operating-write eligibility is restored for the existing contract without creating a new licence or requiring resale eligibility

### Requirement: Commercial provisioning is repeatable and truthful
Company catalog initialization SHALL be insert-only and preserve edited plans and existing subscriptions. New companies SHALL receive pending subscriptions to the basic core-module plan without an invented price, payment confirmation or automatic free trial. Unlinked legacy direct subscriptions SHALL retain platform-authorized activation. Eligible office administrators SHALL activate only their own owner-accepted seller contracts through the office commercial boundary and SHALL NOT require the Super Admin role. Owners SHALL read contract status but cannot self-activate it. Maintenance of an existing accepted seller contract SHALL use the separate maintenance admission rule when new-sale eligibility is absent. Public signup, owner invitation acceptance, company contract pages and commercial forms SHALL preserve failed inputs, reject invalid module/status values and show retryable service errors.

#### Scenario: Repeated catalog initialization
- **WHEN** the initializer runs twice after a platform administrator customized a company plan
- **THEN** customizations and current contracts remain unchanged and no duplicate subscription or demo company is created

#### Scenario: Owner attempts activation
- **WHEN** a company owner directly submits active status to the platform subscription API
- **THEN** the API denies the change and the company page continues to show the actual pending status

#### Scenario: Ordinary office administrator activates an accepted sale
- **WHEN** an eligible real office administrator activates an owner-accepted contract within the office's capacity and allowed grants
- **THEN** its existing Company subscription is activated atomically, the first invoice is issued once, and no platform role or accounting delegation is granted

#### Scenario: Platform catalog is initialized with seller data present
- **WHEN** the legacy insert-only initializer runs after office retail plans and contracts exist
- **THEN** office prices, snapshots, invoices and current Company subscriptions remain unchanged
