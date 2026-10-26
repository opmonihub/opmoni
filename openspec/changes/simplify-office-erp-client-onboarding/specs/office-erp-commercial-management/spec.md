## MODIFIED Requirements

### Requirement: Commercial administration uses the selected office
The system SHALL expose ERP customer and subscription administration at `/gestao-erp` to a real administrator of the selected active Account. Office 1 SHALL follow the same rules as every other office. Super Admin status SHALL NOT grant office membership, commercial data access or additional licence capacity. Responses SHALL distinguish reading, maintaining existing subscriptions, managing policy settings, provisioning customers and publishing legacy retail offers. Core administration and policy configuration SHALL NOT depend on the legacy resale-offer enablement flag; new customer provisioning SHALL still require an active office subscription and explicit licence/module capacity. Office context assertions, session ownership, private responses and foreign-resource protection SHALL apply to every request.

#### Scenario: Super Admin has no office membership
- **WHEN** a Super Admin without membership requests an office's commercial data
- **THEN** access is denied without protected data or an implicitly selected office

#### Scenario: Another office's contract identifier is supplied
- **WHEN** an office administrator requests a contract owned by another Account
- **THEN** the resource returns 404 and no foreign commercial metadata

#### Scenario: Administrator configures policy without retail resale
- **WHEN** an active selected office with an active subscription and real admin membership has retail resale disabled
- **THEN** its administrator can read and explicitly update the office commercial policy without publishing an offer or receiving a generic permission-loading error

### Requirement: Licence allocation is bounded and race safe
One licensed Company SHALL consume one office licence regardless of its establishment or user count. Unprovisioned draft contracts SHALL consume no licence; legacy unexpired commercial invitations SHALL reserve capacity. Administratively provisioned, owner-accepted pending activation, active, suspended and unexpired trial Companies SHALL retain one occupied allocation until effective cancellation or trial expiry. Converting a trial SHALL reuse its existing occupied allocation; converting after expiry SHALL acquire available capacity atomically. Concurrent allocation SHALL never exceed an explicitly configured finite capacity or establish two current office subscriptions for one Company. Unlimited capacity SHALL require an explicit unlimited allowance; zero SHALL deny new allocation. A capacity downgrade below existing usage SHALL preserve current paid allocations and unexpired trial allocations and prevent further allocation.

#### Scenario: Two requests compete for the last licence
- **WHEN** independent requests attempt to allocate the last available office licence
- **THEN** one allocation succeeds and the other receives a conflict without a partial contract, Company or invoice

#### Scenario: Capacity is reduced below usage
- **WHEN** a platform plan change reduces capacity below occupied licences
- **THEN** current licences remain allocated and new allocations are denied with the actual usage and limit

#### Scenario: Trial converts without consuming a second licence
- **WHEN** the office converts an unexpired trial to a paid subscription
- **THEN** the same Company, subscription and allocation are reused and the occupied count does not increase

### Requirement: Owner onboarding preserves identities and Company ownership
An office administrator SHALL be able to register a new company Client or select an active company Client from its own portfolio and assign an ERP subscription with explicit plan and commercial terms without a published retail offer or mandatory owner invitation. New Companies SHALL be provisioned without fictitious identities or assigning the office or its administrator as owner. Owner setup SHALL be a separate, optional, verified access operation and SHALL NOT activate a second subscription or alter its agreed terms. A still-valid legacy commercial invitation SHALL retain its revocable, single-use, seven-day owner-confirmed acceptance flow and disclose only verified metadata. An existing email SHALL never have its credentials overwritten. Matching CNPJ SHALL never attach an existing Company without its current owner's explicit authorization. Provisioning, allocation and initial paid billing SHALL be atomic and recoverable; owner setup SHALL grant neither office membership nor accounting delegation automatically. Expiration/reissue of legacy invitations SHALL release or replace reservations without double allocation.

#### Scenario: Existing user receives an invitation
- **WHEN** an intended owner already has a global identity
- **THEN** acceptance requires that identity's authentication and preserves its password and existing memberships

#### Scenario: Existing Company matches the Client document
- **WHEN** onboarding finds a Company with the same CNPJ
- **THEN** only the Company's authenticated current owner can confirm commercial association and no access is granted from the match alone

#### Scenario: Acceptance is retried after a lost response
- **WHEN** the same intended owner repeats the same successful acceptance
- **THEN** the original accepted result is recoverable without duplicate Company, membership, contract or licence

#### Scenario: Administrator selects an existing portfolio client
- **WHEN** an eligible office admin selects an active company Client and supplies an allowed ERP plan, explicit price, cycle and start date
- **THEN** the office creates the Company association and paid subscription once without requiring a retail offer or waiting for a commercial invitation acceptance

#### Scenario: New-client lookup service fails
- **WHEN** the office registers a new company Client for ERP and the required CNPJ provider cannot confirm its data
- **THEN** the form retains its inputs, no fabricated official fields are persisted and no partial Company, subscription, allocation or invoice is created

#### Scenario: Company is provisioned before user access
- **WHEN** an office admin registers a new ERP customer without establishing the customer owner identity
- **THEN** the subscription can be administered, owner access is visibly pending and neither the office admin nor an unrelated user gains operating access

### Requirement: Contracts preserve agreed terms throughout their lifecycle
Administrators SHALL draft and activate attributable administrative subscriptions or owner-accepted legacy contracts, schedule plan/price amendments for the next cycle, renew, manually suspend/resume, and cancel immediately or at the current period end. Administrative assignments SHALL record their office administrator, selected ERP plan and exact terms independently of optional owner access setup. Contracts SHALL retain attributable term revisions, service periods, price, modules, billing schedule and cancellation reasons. Issued invoices SHALL not change when terms change. Cancellation SHALL stop future generation from its effective date, preserve existing debts/history and release the licence only when effective. Resuming an existing suspended contract under unchanged terms SHALL be maintenance; recreating a canceled contract, adding capacity or expanding resources SHALL require current provisioning eligibility. Existing unlinked direct subscriptions SHALL require explicit owner-confirmed adoption and SHALL not be silently imported.

#### Scenario: A scheduled plan change takes effect
- **WHEN** the next billing period begins for a contract with an accepted amendment
- **THEN** that period uses the new revision and prior periods retain their original terms and invoices

#### Scenario: An administrator cancels a contract with unpaid invoices
- **WHEN** cancellation becomes effective
- **THEN** future recurring invoices stop, prior invoices remain collectible, Company data remains and the licence allocation is released

#### Scenario: Lost administrative activation response
- **WHEN** an office repeats or looks up a direct subscription assignment with its original operation key after losing the response
- **THEN** the original Company, subscription, allocation and first invoice are recovered without another activation or charge

### Requirement: Resale-right loss preserves existing business
Losing the legacy retail-offer resale flag SHALL block new legacy retail offers and commercial invitations, but SHALL NOT itself block core customer administration, policy configuration or eligible direct subscription provisioning. Losing active office-subscription eligibility, exceeding explicit licence capacity or lacking allowed modules SHALL block new direct provisioning, new trial registrations and resource expansion. Existing subscriptions SHALL retain agreed resources, invoices, receipts, history and Company access subject to their own lifecycle, trial deadline and delinquency rules. Real office admins SHALL retain bounded maintenance including unchanged-term renewal, price-only amendment, receipt/reversal, suspension/resumption and cancellation. Re-enabling a right SHALL not replay historical effects. A suspended Account SHALL retain its separate security block.

#### Scenario: Resale permission is removed
- **WHEN** an office loses eligibility to allocate new ERP licences through office-subscription or capacity restrictions while it has active contracts
- **THEN** existing contracts continue billing and operating under their own terms, while the office cannot sell another licence or expand grants

#### Scenario: A pending invitation outlives resale eligibility
- **WHEN** the intended owner attempts to accept an invitation for a not-yet-accepted sale after the seller lost new-sale eligibility
- **THEN** acceptance is rejected before any Company, owner membership or commercial link is created or changed, the reservation is not converted into an occupied allocation and existing accepted contracts remain unaffected

#### Scenario: An already accepted sale is activated after resale-right loss
- **WHEN** the office activates an owner-accepted contract that already occupied a licence before the selling right was removed
- **THEN** bounded maintenance can complete that sale under its accepted grants without allocating another licence

#### Scenario: Direct administration remains independent of Offers
- **WHEN** an office with an active subscription and available explicit licence/module capacity has the legacy resale flag disabled
- **THEN** it can assign an administrative ERP subscription while the deferred legacy offer-publication flow stays unavailable

### Requirement: The commercial workspace preserves the dashboard reference
The `/gestao-erp` office workspace SHALL prioritize overview, ERP customers, subscriptions, invoices, receipts and settings through coherent sidebar, search and local navigation. The primary customer action SHALL be "Cadastrar cliente ERP", with an alternative to select an existing portfolio Client. Customer rows SHALL show customer identity, plan, subscription/trial situation, next billing date or trial end, and contextual subscription actions. The office SHALL be able to copy its configured ERP trial link. Offers SHALL be removed from primary navigation and search for this phase while existing offer routes/data and valid invitation links remain compatible. Theme, typography, spacing, tables, forms and overlays SHALL follow the project dashboard reference and existing Nuxt UI conventions. The workspace SHALL show one page identity without repeating the selected-office name in its header, preserve keyboard access and readable light/dark desktop/mobile layouts, and isolate print routes from the office shell. Authentication, Company selection and ordinary office-admin access SHALL not depend on old platform-admin bookmarks. Business eligibility restrictions SHALL name the unmet condition and a relevant next action without generic service-failure text or a useless retry button; transient failures SHALL retain a retry action and non-solid error treatment.

#### Scenario: Office administrator enters commercial management
- **WHEN** an authorized office administrator opens `/gestao-erp/planos` directly
- **THEN** the office dashboard renders that office's plans without requiring access to an `/admin/*` route or selecting a Company

#### Scenario: Narrow-screen commercial list
- **WHEN** the workspace is used at a 320-pixel width
- **THEN** actions, status explanations and navigation remain reachable and any wide-table scrolling is contained within the table frame

#### Scenario: Restricted settings are not a loading failure
- **WHEN** the actor lacks real office admin membership or the selected Account is suspended
- **THEN** the workspace explains the actual eligibility restriction, disables unauthorized changes and does not label it a retryable settings-fetch failure

#### Scenario: Customer-first workspace on mobile
- **WHEN** an office admin opens customer management at 320px in either theme
- **THEN** registration, portfolio selection, trial-link copy and row subscription actions remain keyboard-reachable without duplicate office identification or an Offers entry
