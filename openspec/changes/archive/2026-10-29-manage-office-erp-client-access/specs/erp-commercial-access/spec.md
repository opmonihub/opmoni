## MODIFIED Requirements

### Requirement: Commercial provisioning is repeatable and truthful
Company catalog initialization SHALL remain insert-only and preserve edited plans and existing subscriptions. General direct registrations SHALL receive pending subscriptions to the basic plan without invented price, payment confirmation or automatic trial. Only an explicitly eligible office-link registration SHALL create a seven-day ERP trial; only an authorized office administrative assignment or explicit conversion SHALL create paid terms. Existing unlinked legacy direct subscriptions SHALL retain platform-authorized activation until owner-confirmed office adoption. Eligible real office administrators SHALL assign and activate their own administrative subscriptions without a retail offer or mandatory commercial invitation, and SHALL retain activation of owner-accepted legacy contracts without Super Admin status. Owners SHALL read actual subscription/trial status but SHALL NOT self-activate a paid subscription. Maintenance SHALL use its distinct admission rule when new provisioning is unavailable. Signup, owner access setup and subscription forms SHALL preserve failed inputs, reject invalid values, recover uncertain outcomes without repeating sensitive payloads and distinguish business denial from service failure.

In the new administrative UI, registration and plan assignment SHALL not activate paid service or issue an invoice; explicit activation SHALL perform those commercial effects once on the already linked Company/subscription/allocation. The existing combined enrollment API SHALL retain its established atomic activation/billing contract for compatibility. Registration without assignment SHALL never open an operating entitlement. Existing paid/trial subscriptions, trial expiry/conversion, grants and immutable financial history SHALL be preserved during backfill.

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

## ADDED Requirements

### Requirement: Applied terms and module changes become effective truthfully
Pending assignments SHALL expose their intended start date, exact price, interval and due terms without claiming activation or payment. Activation SHALL use the intended service anchor rather than silently reset it to response time. Scheduled module/term changes SHALL apply at their actual next service boundary and every operation or queued effect SHALL resolve the effective grants against server time, including when a scheduler has not reconciled yet. Module removal SHALL deny only the removed capabilities and SHALL NOT delete their data or override ordinary role, subscription, security or fiscal-release checks.

#### Scenario: Activation is replayed
- **WHEN** activation is retried or recovered using its original operation identity
- **THEN** the same subscription/contract/first-invoice result is returned without another allocation or charge

#### Scenario: Scheduler has not applied the module deadline
- **WHEN** a module's removal boundary has passed before scheduled reconciliation
- **THEN** new synchronous and queued uses of that module are denied according to the effective revision without granting a grace bypass
