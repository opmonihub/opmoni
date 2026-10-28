## MODIFIED Requirements

### Requirement: ERP registration preserves platform initialization and global identities
General public Company registration SHALL remain available only after platform bootstrap and explicit ERP registration enablement, using existing CSRF/origin and rate protections. An eligible office-specific trial link SHALL authorize only its narrowly scoped ERP registration path after platform bootstrap and explicit office-trial release enablement; it SHALL NOT open general public office or Company registration. Successful office-trial registration SHALL atomically create or authenticate the global identity, establish its owned Company, create or explicitly reuse the sponsoring office Client and start its one ERP trial subscription. General direct registration SHALL retain its pending-subscription behavior. Neither path SHALL create a dummy office Account. Existing-email attempts SHALL require authenticated login rather than overwrite credentials or silently grant membership. Existing Companies SHALL require their current authenticated owner's explicit authorization for commercial association. An office may separately provision a new unclaimed Company and assign its subscription; that commercial operation SHALL NOT establish owner identity or grant Company operating access. Legacy invitation acceptance SHALL remain available without requiring an already selected Company.

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
The system SHALL keep Company plans, subscriptions and core-module entitlements separate from office plans/subscriptions. At most one current subscription SHALL exist per Company. Active paid subscriptions and unexpired seven-day ERP trial subscriptions SHALL permit authorized operating writes, subject to explicit module quotas, current Company authorization, manual/security holds and operating/fiscal release gates. Trial eligibility SHALL be checked against server time on every effective write and queued effect; at or after trial end, writes SHALL return 403 `erp_trial_expired` even before scheduled reconciliation. Pending, expired, past_due, suspended, canceled or missing subscriptions SHALL otherwise deny operating writes while authorized setup/contract/history reads remain available. Disabled modules SHALL deny module operations without granting membership based on a subscription. Company suspension SHALL deny Company operations independently of subscription status.

For an office-managed Company, its frozen administrative terms or accepted legacy retail terms SHALL supply plan identity and module grants while retaining one current ERP subscription. Losing the office legacy resale flag or changing its own subscription SHALL NOT remove existing paid licences, rewrite invoices, clear module snapshots or cancel Company access. The Company's own cancellation, manual suspension, trial deadline and delinquency policy SHALL govern operating eligibility. An overdue invoice alone SHALL NOT block access before configured policy and grace apply. Accounting delegation SHALL remain independent of the licence.

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

#### Scenario: Trial expires before scheduler runs
- **WHEN** an otherwise authorized user submits an operating write exactly at its trial end timestamp before reconciliation
- **THEN** the API returns 403 `erp_trial_expired`, no mutation or external effect occurs and authorized data reads remain available

### Requirement: Commercial provisioning is repeatable and truthful
Company catalog initialization SHALL remain insert-only and preserve edited plans and existing subscriptions. General direct registrations SHALL receive pending subscriptions to the basic plan without invented price, payment confirmation or automatic trial. Only an explicitly eligible office-link registration SHALL create a seven-day ERP trial; only an authorized office administrative assignment or explicit conversion SHALL create paid terms. Existing unlinked legacy direct subscriptions SHALL retain platform-authorized activation until owner-confirmed office adoption. Eligible real office administrators SHALL assign and activate their own administrative subscriptions without a retail offer or mandatory commercial invitation, and SHALL retain activation of owner-accepted legacy contracts without Super Admin status. Owners SHALL read actual subscription/trial status but SHALL NOT self-activate a paid subscription. Maintenance SHALL use its distinct admission rule when new provisioning is unavailable. Signup, owner access setup and subscription forms SHALL preserve failed inputs, reject invalid values, recover uncertain outcomes without repeating sensitive payloads and distinguish business denial from service failure.

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
