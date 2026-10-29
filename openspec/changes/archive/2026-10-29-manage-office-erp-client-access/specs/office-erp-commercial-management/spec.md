## MODIFIED Requirements

### Requirement: Commercial administration uses the selected office
The system SHALL expose ERP customer and subscription administration at `/gestao-erp` to a real administrator of the selected active Account. Office 1 SHALL follow the same rules as every other office. Super Admin status SHALL NOT grant office membership, commercial data access or additional licence capacity. Responses SHALL distinguish reading, maintaining existing subscriptions, managing policy settings, provisioning customers and publishing legacy retail offers. Core administration and policy configuration SHALL NOT depend on the legacy resale-offer enablement flag; new licensed subscription assignment and resource expansion SHALL still require an active office subscription and explicit licence/module capacity. Unassigned Company registration SHALL require an active office subscription and applicable Client creation limits but SHALL occupy no licence. Office context assertions, session ownership, private responses and foreign-resource protection SHALL apply to every request.

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
One licensed Company SHALL consume one office licence regardless of its establishment or user count. Registered but commercially unassigned Companies and unprovisioned draft contracts SHALL consume no licence; legacy unexpired commercial invitations SHALL reserve capacity. Administratively provisioned, owner-accepted pending activation, active, suspended and unexpired trial Companies SHALL retain one occupied allocation until effective cancellation or trial expiry. Converting a trial SHALL reuse its existing occupied allocation; converting after expiry SHALL acquire available capacity atomically. Concurrent allocation SHALL never exceed an explicitly configured finite capacity or establish two current office subscriptions for one Company. Unlimited capacity SHALL require an explicit unlimited allowance; zero SHALL deny new allocation. A capacity downgrade below existing usage SHALL preserve current paid allocations and unexpired trial allocations and prevent further allocation.

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
An office administrator SHALL be able to register a new company Client or select an active company Client from its own portfolio and assign an ERP subscription with explicit plan and commercial terms without a published retail offer or mandatory owner invitation. New Companies SHALL be provisioned without fictitious identities or assigning the office or its administrator as owner. Owner setup SHALL be a separate, optional, verified access operation and SHALL NOT activate a second subscription or alter its agreed terms. A still-valid legacy commercial invitation SHALL retain its revocable, single-use, seven-day owner-confirmed acceptance flow and disclose only verified metadata. Invitation acceptance SHALL never overwrite an existing identity's credentials; only the separately authorized recovery requirements in auth can change them. Matching CNPJ SHALL never attach an existing Company without its current owner's explicit authorization. The compatible combined enrollment command SHALL retain atomic provisioning, allocation and initial paid billing; the new separate registration, assignment and activation commands SHALL each be atomic and recoverable within their explicit effect boundaries; owner setup SHALL grant neither office membership nor accounting delegation automatically. Expiration/reissue of legacy invitations SHALL release or replace reservations without double allocation.

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

### Requirement: The commercial workspace preserves the dashboard reference
The `/gestao-erp` office workspace SHALL expose overview, plans, ERP customers, subscriptions, invoices, receipts and settings through coherent sidebar, search and local navigation. Commercial plan management SHALL appear as "Planos" at `/gestao-erp/planos`, and its editor, actions, alerts and overview shortcut SHALL use plan terminology instead of "Ofertas". Existing plan routes/data, command recovery and valid invitation links SHALL remain compatible. The customer section SHALL be "Empresas" at `/gestao-erp/empresas`, and "Novo cliente" SHALL offer portfolio selection or new registration. The separate "Assinaturas" Company portfolio SHALL open per-Company details rather than treat every contract as a separate company row. Company registry rows SHALL show identity, registration date and contextual profile/assignment actions; subscription portfolio rows SHALL show Company identity, plan, subscription/trial situation, next billing date or trial end, and contextual subscription actions. The office SHALL be able to copy its configured ERP trial link. Theme, typography, spacing, tables, forms and overlays SHALL follow the project dashboard reference and existing Nuxt UI conventions. The workspace SHALL show one page identity without repeating the selected-office name in its header, preserve keyboard access and readable light/dark desktop/mobile layouts, and isolate print routes from the office shell. Authentication, Company selection and ordinary office-admin access SHALL not depend on old platform-admin bookmarks. Business eligibility restrictions SHALL name the unmet condition and a relevant next action without generic service-failure text or a useless retry button; transient failures SHALL retain a retry action and non-solid error treatment.

Equivalent tasks SHALL use the shared system composition; bounded commercial plan editing SHALL use a responsive modal, and client/subscription detail expansion SHALL preserve complete desktop/mobile operations. Longer enrollment and financial document workflows SHALL retain their appropriate page composition. All proposed components SHALL have documented official API/example and installed-version compatibility evidence. Existing contrast/accessibility improvements SHALL remain intact.

Company details SHALL expose current terms, lifecycle controls, modules, faturas, history and "Usuários e acessos". Existing customer and contract bookmarks SHALL remain usable. Companies without an applied contract SHALL remain visible in Empresas and SHALL NOT be presented as applied subscriptions. The two sections SHALL retain their own search/order/page state on same-office round trips.

#### Scenario: Office administrator enters commercial management
- **WHEN** an authorized office administrator opens `/gestao-erp/planos` directly
- **THEN** the office dashboard renders that office's plans, marks "Planos" active in sidebar and local navigation, and offers the "Novo plano" action according to existing commercial eligibility without requiring access to an `/admin/*` route or selecting a Company

#### Scenario: Narrow-screen commercial list
- **WHEN** the workspace is used at a 320-pixel width
- **THEN** actions, status explanations and navigation remain reachable and any wide-table scrolling is contained within the table frame

#### Scenario: Restricted settings are not a loading failure
- **WHEN** the actor lacks real office admin membership or the selected Account is suspended
- **THEN** the workspace explains the actual eligibility restriction, disables unauthorized changes and does not label it a retryable settings-fetch failure

#### Scenario: Customer-first workspace on mobile
- **WHEN** an office admin opens customer management at 320px in either theme
- **THEN** registration, portfolio selection, trial-link copy, plan management and row subscription actions remain keyboard-reachable without duplicate office identification or an Offers entry

#### Scenario: Commercial plan editor uses the common modal form
- **WHEN** an authorized office administrator opens an allowed plan edit
- **THEN** a responsive modal retains current module/revision fields, errors, busy state and original-command recovery without changing commercial eligibility

### Requirement: Contracts preserve agreed terms throughout their lifecycle
Administrators SHALL draft and activate attributable administrative subscriptions or owner-accepted legacy contracts, schedule plan/price amendments for the next cycle, renew, manually suspend/resume, and cancel immediately or at the current period end. Administrative assignments SHALL record their office administrator, selected ERP plan and exact terms independently of optional owner access setup. Contracts SHALL retain attributable term revisions, service periods, price, modules, billing schedule and cancellation reasons. Issued invoices SHALL not change when terms change. Cancellation SHALL stop future generation from its effective date, preserve existing debts/history and release the licence only when effective. Resuming an existing suspended contract under unchanged terms SHALL be maintenance; recreating a canceled contract, adding capacity or expanding resources SHALL require current provisioning eligibility. Existing unlinked direct subscriptions SHALL require explicit owner-confirmed adoption and SHALL not be silently imported.

A separate administrative assignment SHALL consume one occupied allocation but SHALL remain pending until explicit activation; it SHALL create no invoice or operating entitlement. Activation SHALL atomically activate that contract and its existing subscription and issue the first invoice once. Existing effective contracts SHALL never be reset to pending by migration or navigation. An immutable replacement of a pending terms revision SHALL require the expected revision identity and retain the superseded revision in history.

#### Scenario: A scheduled plan change takes effect
- **WHEN** the next billing period begins for a contract with an accepted amendment
- **THEN** that period uses the new revision and prior periods retain their original terms and invoices

#### Scenario: An administrator cancels a contract with unpaid invoices
- **WHEN** cancellation becomes effective
- **THEN** future recurring invoices stop, prior invoices remain collectible, Company data remains and the licence allocation is released

#### Scenario: Lost administrative activation response
- **WHEN** an office repeats or looks up a direct subscription assignment with its original operation key after losing the response
- **THEN** the original Company, subscription, allocation and first invoice are recovered without another activation or charge

## ADDED Requirements

### Requirement: ERP company registration precedes commercial assignment
An active-office administrator with an active office subscription SHALL register a new company Client or an active company Client from that office's portfolio without selecting a commercial plan, creating a human identity, assigning ownership, occupying licence capacity or issuing a commercial invoice. The registered Company SHALL be unclaimed and ineligible for operation until its independent identity and subscription requirements are met. A technical pending subscription SHALL NOT imply an applied contract. Assignment SHALL require current provisioning/module/capacity eligibility, explicit exact-value terms and an existing registered Company. At most one current office assignment SHALL exist for a Company.

#### Scenario: Register without a plan
- **WHEN** an authorized administrator registers an eligible portfolio Client in ERP
- **THEN** the Company appears once in Empresas without a commercial contract, allocation or invoice and does not appear in Assinaturas until a plan is applied

#### Scenario: Assignment loses the last available licence
- **WHEN** two independent valid assignments compete for the last available licence
- **THEN** at most one pending assignment acquires capacity and the other fails without a partial contract or invoice

#### Scenario: Existing foreign Company matches CNPJ
- **WHEN** registration encounters a Company not already managed through the selected office's authorized relationship
- **THEN** it returns a generic association-required conflict without attaching that Company or exposing its owner or commercial data

#### Scenario: Registered Company has no operating identity
- **WHEN** an office administrator has only commercial authority over a registered Company
- **THEN** company selection and operating APIs still deny access without a separate direct membership or explicit delegation

### Requirement: Company portfolios and histories preserve resource identity
Empresas SHALL paginate registered ERP Companies scoped to the selected office, including unassigned, unclaimed, suspended, expired and canceled history. Assinaturas SHALL paginate Companies with applied office contracts, one Company row regardless of history length. Counts, ordering and filters SHALL be applied server-side before pagination; current contracts SHALL be resolved from the current authoritative link rather than highest identifier. A Company's detail SHALL retain paginated contract history, actual current/pending terms, trial situation, faturas and member access. Historical-only office relationships SHALL remain read-only for a Company currently managed elsewhere; legacy contracts without a Company SHALL remain reachable separately.

#### Scenario: Several contracts for one Company
- **WHEN** a Company has an effective contract and older canceled contracts
- **THEN** its subscription list contains one Company row, identifies the actual current contract and retains older contracts in its history

#### Scenario: Foreign Company detail
- **WHEN** an administrator requests a Company or nested contract belonging to another office
- **THEN** the API returns 404 without exposing foreign Company, user, invoice or contract data

#### Scenario: Registration ordering
- **WHEN** the administrator orders Companies by name or registration date in either direction
- **THEN** server pagination is stable with an identifier tie-breaker and returning from details restores the same permitted list criteria

### Requirement: Module amendments distinguish increases from reductions
An administrator SHALL schedule additions, removals or a combined change to the Company's supported modules for the next service period. Current grants SHALL remain unchanged until the effective boundary. Pure removal SHALL be maintenance of an existing accepted contract and SHALL NOT require resale eligibility; additions SHALL require current new-provisioning eligibility and only the added keys must be within the office's allowance. Unchanged historical grants SHALL remain valid when an allowance is reduced. Unsupported keys SHALL return 422; forbidden expansion SHALL return 403. All module changes SHALL preserve business data, issued invoices, role checks and operating/fiscal release gates.

#### Scenario: Remove a contracted module
- **WHEN** an active office administrator schedules only removal from an accepted contract
- **THEN** a next-period revision is recorded, the module remains usable until that boundary, and afterward its operations are denied without deleting its data or changing past invoices

#### Scenario: Add and remove together without expansion eligibility
- **WHEN** an amendment removes one module and adds another while the office cannot expand grants
- **THEN** the whole amendment is rejected without applying only its reduction or changing the pending revision

#### Scenario: Retain a previously sold grant
- **WHEN** the office adds an allowed module while retaining a historically agreed module no longer sold to new customers
- **THEN** admission validates the net addition without silently removing the historical grant

#### Scenario: Concurrent pending-revision replacement
- **WHEN** two changes reference the same pending revision
- **THEN** one immutable replacement succeeds and the stale replacement receives 409 without erasing either revision's history

### Requirement: Sensitive commercial commands retain safe recovery
Every effective registration, assignment, member, invitation, module and credential command SHALL have a validated operation identity and attributable secret-free audit. Replays and lookups SHALL reauthorize the current actor, selected Account, current management relationship and action-specific target. Passwords, credential hashes, raw invitation/recovery tokens and bearer URLs SHALL NOT appear in ordinary reads, durable operation payload/outcome, audit metadata or client recovery storage. Unknown outcomes SHALL be reconciled by operation identity without automatically repeating passwords, issuing new links or sending another email. Service failures SHALL remain distinct from empty lists, expired links and confirmed success.

#### Scenario: Password response is lost after commit
- **WHEN** a direct-reset response is lost after the new password and authentication revision committed
- **THEN** the UI clears sensitive inputs and can recover only a sanitized authorized receipt without sending another password

#### Scenario: Actor loses authority before lookup
- **WHEN** the actor's office role, membership or management relationship is revoked before operation recovery
- **THEN** lookup discloses no protected outcome and performs no replayed effect

#### Scenario: Account changes while an editor is open
- **WHEN** the selected office, actor or session changes before a late read/save response arrives
- **THEN** protected editors close, sensitive drafts are cleared and the old response cannot populate the new context
