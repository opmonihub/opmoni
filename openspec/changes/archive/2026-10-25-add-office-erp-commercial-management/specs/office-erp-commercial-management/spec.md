## Purpose

Enable accounting offices to sell and maintain their own ERP offers and Company licences through an office-scoped commercial workspace with explicit membership and resale admission.

## ADDED Requirements

### Requirement: Commercial administration uses the selected office
The system SHALL expose commercial administration at `/gestao-erp` to a real administrator of the selected active Account. Office 1 SHALL follow the same rules as every other office. Super Admin status SHALL NOT grant office membership, commercial data access or additional licence capacity. Commercial responses SHALL distinguish permission to read, maintain existing contracts, configure settings and sell new licences, with reasons for denied actions. Office context assertions, session ownership, private responses and foreign-resource protection SHALL apply to every request.

#### Scenario: Super Admin has no office membership
- **WHEN** a Super Admin without membership requests an office's commercial data
- **THEN** access is denied without protected data or an implicitly selected office

#### Scenario: Another office's contract identifier is supplied
- **WHEN** an office administrator requests a contract owned by another Account
- **THEN** the resource returns 404 and no foreign commercial metadata

### Requirement: Offices own their retail offers
An eligible office administrator SHALL create, list, inspect, edit and archive that office's ERP retail plans with independent names, exact BRL prices, billing cycles and included resources. Included modules SHALL be limited to the office's explicit resale allowance when publishing a new offer or expanding one. Unsupported modules, foreign related records and invalid money or cycle values SHALL return field validation without partial changes. Archiving SHALL stop new sales without changing existing contracts. Editing SHALL create a new offer revision; existing contracts SHALL retain their agreed revision until an explicit amendment.

#### Scenario: Two offices sell differently priced offers
- **WHEN** two eligible offices publish differently named or priced plans
- **THEN** each sees and manages only its own offers and neither changes the other's catalog

#### Scenario: Existing plan is edited
- **WHEN** an administrator changes a sold plan's price
- **THEN** the new offer revision applies to subsequent sales and existing contract prices remain unchanged

### Requirement: Licence allocation is bounded and race safe
One licensed Company SHALL consume one office licence regardless of its establishment or user count. Draft contracts SHALL consume no licence; unexpired activation invitations SHALL reserve capacity; owner-accepted pending activation, active and suspended licences SHALL retain their allocation until cancellation. Concurrent allocation SHALL never exceed the currently allowed finite capacity or establish two current seller contracts for one Company. Unlimited capacity SHALL require an explicitly configured unlimited allowance. A capacity downgrade below existing usage SHALL preserve existing allocations and prevent further allocation.

#### Scenario: Two requests compete for the last licence
- **WHEN** independent requests attempt to allocate the last available office licence
- **THEN** one allocation succeeds and the other receives a conflict without a partial contract, Company or invoice

#### Scenario: Capacity is reduced below usage
- **WHEN** a platform plan change reduces capacity below occupied licences
- **THEN** current licences remain allocated and new allocations are denied with the actual usage and limit

### Requirement: Owner onboarding preserves identities and Company ownership
An office SHALL onboard an active Client from its own portfolio using an owner invitation and explicit commercial terms. A seven-day, revocable, single-use invitation SHALL disclose only verified invitation metadata and SHALL require the intended person to establish or authenticate their global identity before acceptance. An existing email SHALL never have its credentials overwritten. Matching CNPJ SHALL never attach an existing Company without its current owner's explicit acceptance. Acceptance SHALL atomically establish or bind the Company, its owner, the accepted contract and its one ERP subscription. It SHALL NOT create a dummy office, make the selling office the Company owner or grant accounting delegation automatically. Expiration/reissue SHALL release or replace reservations without double allocation.

#### Scenario: Existing user receives an invitation
- **WHEN** an intended owner already has a global identity
- **THEN** acceptance requires that identity's authentication and preserves its password and existing memberships

#### Scenario: Existing Company matches the Client document
- **WHEN** onboarding finds a Company with the same CNPJ
- **THEN** only the Company's authenticated current owner can confirm commercial association and no access is granted from the match alone

#### Scenario: Acceptance is retried after a lost response
- **WHEN** the same intended owner repeats the same successful acceptance
- **THEN** the original accepted result is recoverable without duplicate Company, membership, contract or licence

### Requirement: Contracts preserve agreed terms throughout their lifecycle
Administrators SHALL draft and activate accepted contracts, schedule plan/price amendments for the next cycle, renew, manually suspend/resume, and cancel immediately or at the current period end. Contracts SHALL retain attributable term revisions, service periods, price, modules, billing schedule and cancellation reasons. Issued invoices SHALL not change when terms change. Cancellation SHALL stop future generation from its effective date, preserve existing debts/history and release the licence only when effective. Resuming a suspended existing contract under unchanged terms SHALL be maintenance; recreating a canceled contract, adding capacity or expanding resource grants SHALL be a new sale. Existing unlinked direct subscriptions SHALL require explicit owner-confirmed adoption and SHALL not be silently imported.

#### Scenario: A scheduled plan change takes effect
- **WHEN** the next billing period begins for a contract with an accepted amendment
- **THEN** that period uses the new revision and prior periods retain their original terms and invoices

#### Scenario: An administrator cancels a contract with unpaid invoices
- **WHEN** cancellation becomes effective
- **THEN** future recurring invoices stop, prior invoices remain collectible, Company data remains and the licence allocation is released

### Requirement: Resale-right loss preserves existing business
Losing resale eligibility, exceeding a reduced capacity or becoming ineligible for new module grants SHALL block new contracts, invitations, allocations and expansions. Existing accepted contracts SHALL retain their agreed resources, recurring invoices, receipts, history and Company access, subject only to their own lifecycle and delinquency rules. Their office administrators SHALL retain bounded maintenance actions, including unchanged-term renewal, price-only amendment, receipt/reversal, manual suspension/resumption and cancellation. Re-enabling resale SHALL not replay historical effects. A suspended Account SHALL retain its separate security block.

#### Scenario: Resale permission is removed
- **WHEN** an office loses the ERP selling right while it has active contracts
- **THEN** existing contracts continue billing and operating under their own terms, while the office cannot sell another licence or expand grants

#### Scenario: A pending invitation outlives resale eligibility
- **WHEN** the intended owner attempts to accept an invitation for a not-yet-accepted sale after the seller lost new-sale eligibility
- **THEN** acceptance is rejected before any Company, owner membership or commercial link is created or changed, the reservation is not converted into an occupied allocation and existing accepted contracts remain unaffected

#### Scenario: An already accepted sale is activated after resale-right loss
- **WHEN** the office activates an owner-accepted contract that already occupied a licence before the selling right was removed
- **THEN** bounded maintenance can complete that sale under its accepted grants without allocating another licence

### Requirement: The commercial workspace preserves the dashboard reference
The `/gestao-erp` office workspace SHALL expose overview, plans, portfolio clients, subscriptions, invoices, receipts and settings through coherent sidebar, search and local navigation. Theme, typography, spacing, tables, forms and overlays SHALL follow the project's `.dashboard/app` reference and retain existing contrast/accessibility improvements. All proposed components SHALL have documented official API/example and installed-version compatibility evidence. The workspace SHALL show one page identity and preserve navigation, keyboard access and readable content in light/dark desktop and mobile layouts. Old `/admin/erp/*` links SHALL not remain the commercial entry for ordinary office administrators.

#### Scenario: Office administrator enters commercial management
- **WHEN** an authorized office administrator opens `/gestao-erp/planos` directly
- **THEN** the office dashboard renders that office's plans without requiring access to an `/admin/*` route or selecting a Company

#### Scenario: Narrow-screen commercial list
- **WHEN** the workspace is used at a 320-pixel width
- **THEN** actions, status explanations and navigation remain reachable and any wide-table scrolling is contained within the table frame

### Requirement: Commercial state and mutation outcomes remain truthful
Unknown, loading, failed, stale, confirmed-empty and successful commercial reads SHALL remain distinguishable. Searches and lists SHALL use authoritative server totals and pagination. Monetary input and payload values SHALL remain exact decimal strings. Duplicate irreversible submissions SHALL be prevented and recoverable by operation identity after an unknown response. Changing Account, session or effective authority SHALL invalidate outstanding reads, close editors and prevent late success/error or protected content from appearing in the new context. Every effective commercial mutation SHALL have an attributable audit record.

#### Scenario: Invoice list fails during refresh
- **WHEN** a commercial list refresh fails after an earlier successful read
- **THEN** an actionable error is shown and retained results are labeled as previously confirmed rather than an empty successful response

#### Scenario: Office switches while a save is pending
- **WHEN** the operator changes Account before an earlier office's save response arrives
- **THEN** that response cannot repopulate the new office's editor or display stale success feedback
