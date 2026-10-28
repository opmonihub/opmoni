# erp-office-trials Specification

## Purpose

Allow accounting offices to share an attributable ERP evaluation link so companies can self-register, try the ERP for seven days and later retain their data when explicitly converted to a paid subscription.

## Requirements

### Requirement: Share links identify the sponsoring office without granting membership
A real administrator of the selected active office with an active office subscription SHALL be able to configure, copy, rotate and disable its ERP trial link. The link SHALL resolve server-side to exactly one office and an explicitly selected allowed ERP plan; caller-supplied office identifiers SHALL not change attribution. The public landing SHALL expose only sponsor display identity, plan summary, trial duration and current availability. Possessing the link SHALL grant no Account membership, Company membership, portfolio read or configuration access. Invalid, disabled or rotated links SHALL disclose no protected office data. Registration SHALL require explicit office-trial release enablement; the global registration gate SHALL remain independent.

#### Scenario: Office shares its link
- **WHEN** an office administrator copies its configured link and an anonymous prospect opens it
- **THEN** the prospect sees the sponsoring office, plan and seven-day terms without having to join or select that office

#### Scenario: Link is rotated before submission
- **WHEN** the office rotates or disables a link after its signup form was loaded
- **THEN** registration using the old link is denied before identity, Client, Company, licence or subscription changes

### Requirement: Trial registration is atomic and attributable
Registration SHALL validate company data and CNPJ, establish a new intended owner identity or require login for an existing email, and create or explicitly reuse the sponsoring office's active company Client. It SHALL atomically establish the owned Company, one trial subscription and one occupied office licence. It SHALL not require a retail offer, make the office the owner or create automatic delegation. Existing owned Companies SHALL require authenticated owner authorization and SHALL be ineligible when already trialed or holding an active paid/foreign office subscription. The backend SHALL recheck office status, active office subscription, explicit licence/module/client capacity, link revision and trial-release eligibility before commit. Provider, persistence or pre-commit failure SHALL leave no partial identity, Client, Company, subscription or allocation. A lost response SHALL be reconciled through authentication/current registration outcome without another password POST or duplicated business effects.

#### Scenario: New prospect registers successfully
- **WHEN** a valid office link is used for an eligible new Company and the complete transaction commits
- **THEN** the client appears only in the sponsoring office's portfolio and the authenticated owner receives its one ERP trial subscription

#### Scenario: Two prospects compete for the last licence
- **WHEN** independent registrations compete for the sponsoring office's final licence
- **THEN** exactly one commits and the loser receives an actionable capacity conflict without partial Client, identity or Company

#### Scenario: Registration response is lost after commit
- **WHEN** registration committed but its response or subsequent identity refresh is lost
- **THEN** the interface offers recovery or login without blindly resubmitting a password, and the original trial timestamps and records remain unchanged

### Requirement: Trial eligibility is seven days and cannot be restarted
An ERP trial SHALL start at the server timestamp of successful registration and end exactly 168 hours later. The original normalized Company CNPJ and first trial grant SHALL remain durably attributable even if the company is later archived or its owner email changes. A Company SHALL receive at most one trial globally; switching offices, repeating registration, changing plan, rotating a link or retrying a request SHALL not restart or extend it. Trial UI and API SHALL expose start, end and actual situation to authorized Company users and sponsoring office administrators without disclosing unrelated identities. A trial SHALL grant only the selected ERP plan's allowed modules and SHALL not activate office Fiscal, Monitoring/SERPRO, WhatsApp or other office products or bypass independent fiscal/release gates.

#### Scenario: Trial clock crosses a daylight-saving or timezone boundary
- **WHEN** a trial starts before a local date or timezone transition
- **THEN** its expiration remains exactly 168 elapsed hours later and the interface presents the correctly localized end time

#### Scenario: Same CNPJ tries a different office link
- **WHEN** a Company with a previous trial attempts a second signup through another office
- **THEN** a second grant is refused without reassigning its office or exposing foreign Company data

### Requirement: Expiry blocks writes and preserves data without billing
At or after the recorded trial end, the ERP SHALL block effective business writes and queued effects with `erp_trial_expired` while preserving authorized consultation, subscription history and a path to paid conversion. Trial use SHALL not create commercial invoices, outstanding debt, payment confirmation or automatic collection. Expiry SHALL release its office licence exactly once without deleting Company or portfolio records or clearing a security/manual hold. Access enforcement SHALL not depend on scheduler timing; reconciliation SHALL be idempotent and race safe with conversion. The interface SHALL display "Teste encerrado" and the preserved end date rather than pretend that payment failed.

#### Scenario: Read and write at exact trial expiry
- **WHEN** an authorized owner reads existing ERP data and attempts a business write exactly at expiration
- **THEN** the read remains available, the write has no effect and returns `erp_trial_expired`, and no overdue invoice is invented

#### Scenario: Expiry and conversion run concurrently
- **WHEN** independent processes expire a trial and convert it to paid
- **THEN** they serialize to one truthful final subscription with at most one allocation and first invoice, and expiry cannot deactivate the committed paid subscription

### Requirement: Paid conversion is explicit and preserves the Company
Only an authorized sponsoring-office administrator SHALL confirm conversion with an eligible ERP plan, exact price, billing cycle and start date. Conversion SHALL retain the same Client, Company, owner, operational records, subscription and original trial history. It SHALL use the original occupied licence while the trial remains valid or acquire currently available capacity after expiry. Paid terms and the first invoice SHALL begin at the explicitly confirmed paid start date, never retroactively charge trial days, and be recoverable through the original operation key without duplicate billing. Prior suspension/security holds SHALL remain effective. Expired or canceled trial history SHALL not be deleted to regain eligibility.

#### Scenario: Office converts an expired trial
- **WHEN** the sponsoring-office admin confirms valid paid terms after expiry and capacity is available
- **THEN** the existing subscription becomes paid from its confirmed start date, preserves all Company data and trial timestamps, and issues exactly one first invoice

#### Scenario: Conversion lacks capacity
- **WHEN** an expired trial is converted while the sponsoring office has no free licence
- **THEN** the operation reports the actual capacity restriction and preserves the expired trial and operational data without creating a charge
