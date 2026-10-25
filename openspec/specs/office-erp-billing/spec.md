# office-erp-billing Specification

## Purpose

Provide complete office-owned ERP contract invoicing, exact balances, manual settlement and delinquency management without a banking or payment-provider integration.

## Requirements

### Requirement: Billing belongs to the selling office
Billing invoices, line items, receipts, allocations, settings and history SHALL belong to the selling Account and SHALL reference only its own ERP contracts and portfolio Clients. They SHALL NOT become sales or receivables inside a client Company's operational finance. Office administrators SHALL manage their own billing under the commercial maintenance guard. Company owners SHALL be able to consult their own contract terms and invoices through the guarded Company contract view without gaining office membership or access to other clients.

#### Scenario: Company owner inspects a billing invoice
- **WHEN** the authenticated owner opens an invoice for their selected Company's contract
- **THEN** only that invoice and its authorized seller/contract details are returned and no office-wide billing list is exposed

#### Scenario: Foreign invoice is used for a receipt
- **WHEN** an office administrator supplies an invoice from another Account
- **THEN** the operation returns 404 or a related-field 422 and creates no receipt or allocation

### Requirement: Recurring generation has explicit calendar semantics
Only an explicitly activated, effective agreement SHALL generate invoices in advance for monthly, quarterly, semiannual or annual periods, including while its licence is temporarily suspended, using its activation date, original calendar anchor, timezone and nonnegative due-date offset. Activation SHALL create the first invoice and schedule atomically. A merely accepted contract SHALL have no recurring generation before activation. Short months SHALL clamp the day without drifting the original anchor. At most one recurring invoice SHALL exist for one contract and service period, including when that invoice was canceled. Repeated, concurrent and catch-up processing SHALL converge on that identity without advancing the schedule after a failed transaction. Canceled contracts SHALL generate no period starting on or after the effective cancellation date. Loss of resale eligibility SHALL not stop existing-contract generation; Account suspension SHALL stop new job effects.

#### Scenario: Accepted contract awaits explicit activation
- **WHEN** a recurring or catch-up job encounters an accepted contract that has not been activated
- **THEN** it creates no invoice, does not initialize or advance a schedule and does not alter the Company's current licence; later explicit activation creates the first invoice exactly once

#### Scenario: Monthly contract begins on January 31
- **WHEN** its February and March cycles are generated
- **THEN** February clamps to its last day and March returns to the original day 31

#### Scenario: Two workers generate the same period
- **WHEN** independent workers process one contract and period concurrently
- **THEN** one invoice is issued and both outcomes identify that same invoice

#### Scenario: Generation fails before commit
- **WHEN** invoice creation fails during catch-up
- **THEN** neither a partial invoice nor an advanced schedule remains and the same period can be retried

### Requirement: Issued invoices preserve their commercial evidence
An invoice SHALL have an office-unique number, seller and bill-to snapshots, contract/revision reference, service period, issue date, due date, currency, described items, discounts and exact totals. Draft invoices SHALL be editable; issuance SHALL freeze those values. Subsequent corrections SHALL use an explicit reasoned adjustment or cancellation rather than rewriting issued evidence. An invoice with effective receipts SHALL not be canceled until those receipts are validly reversed. Canceled invoice numbers and periods SHALL remain reserved. A zero-total invoice SHALL settle without an invented cash receipt.

#### Scenario: Price changes after an invoice was issued
- **WHEN** the contract or retail plan price changes
- **THEN** the issued invoice's amount and snapshots remain unchanged

#### Scenario: Invoice total is zero
- **WHEN** a valid invoice is issued with total `0.00`
- **THEN** its outstanding balance is zero and no money receipt is fabricated

### Requirement: Invoice adjustments preserve exact balances
An authorized maintainer SHALL correct an issued invoice with an immutable debit or credit adjustment, identifying the original invoice, a positive exact BRL magnitude, direction, actor, reason, server posting time and durable operation identity. The original invoice values SHALL remain unchanged. Adjusted charge SHALL equal original total plus debits minus credits; outstanding SHALL equal adjusted charge minus effective receipt allocations. The locked resulting adjusted charge SHALL be at least zero and at least the effective allocations. A credit violating either invariant SHALL be rejected without mutation; a receipt SHALL require explicit valid reversal before a credit can reduce the charge below its allocation. Draft/canceled invoices SHALL not accept adjustments. Adjustments SHALL inherit the invoice's currency, period and due date and SHALL not create another recurring invoice or unallocated-credit wallet. A correction of an adjustment SHALL use a new attributable opposite-direction adjustment linked to it and satisfying the same invariants. Equal operation repeats SHALL return the original result; changed-payload reuse SHALL conflict. Balances, print output, reports and delinquency evaluation SHALL consistently include adjustments without inventing a receipt or bank refund.

#### Scenario: Debit reopens a settled invoice
- **WHEN** a `20.00` debit is posted to an issued `100.00` invoice with `100.00` effectively allocated
- **THEN** the original total remains `100.00`, adjusted charge becomes `120.00`, outstanding becomes `20.00` and due-date/delinquency evaluation uses that balance

#### Scenario: Credit settles a partially received invoice
- **WHEN** a `60.00` credit is posted to an issued `100.00` invoice with `40.00` effectively allocated
- **THEN** adjusted charge and effective receipts both equal `40.00`, outstanding becomes `0.00` and only any automatic debt hold can clear without a synthetic receipt

#### Scenario: Credit exceeds the available outstanding balance
- **WHEN** a credit would reduce an invoice's adjusted charge below its effective receipt allocations or below zero
- **THEN** validation rejects it without changing the invoice, adjustments, receipts or licence state

#### Scenario: Adjustment response is lost
- **WHEN** the same adjustment operation is retried after commit
- **THEN** its original result is returned once and reusing that identity with another amount or direction returns a conflict

#### Scenario: Credit competes with a manual receipt
- **WHEN** independent requests attempt a credit and receipt that together exceed one invoice's available outstanding balance
- **THEN** invoice locking preserves the committed effect and rejects the excess effect without a negative balance or unallocated credit

### Requirement: Invoice and balance states are independently truthful
Draft/issued/canceled document state, unpaid/partial/settled settlement state and overdue condition SHALL be distinct. Overdue SHALL mean an issued invoice has a positive balance after its due date in the billing timezone. Contract and licence states SHALL not be inferred solely from an overdue label. Monetary amounts SHALL cross interfaces as canonical BRL decimal strings with two fractional digits, and exact arithmetic SHALL reject negative receipts, ambiguous amounts, excess precision and over-allocation without mutation.

#### Scenario: Partially paid invoice passes its due date
- **WHEN** an issued invoice retains a positive balance after its due date
- **THEN** it is both partially settled and overdue, with the actual paid and outstanding amounts shown

#### Scenario: Input has excess decimal precision
- **WHEN** a receipt supplies an amount such as `1.001`
- **THEN** field validation rejects it without silently rounding or applying money

### Requirement: Manual receipts support partial and full allocation
An administrator SHALL record an external manual receipt with exact amount, received date, optional reference/proof and allocations to the office's outstanding invoices. Allocations SHALL total the receipt amount, SHALL not exceed the locked current balances and SHALL not create unallocated credit in this version. Recording SHALL identify the operator and explicitly state that it is manual rather than bank-confirmed. An idempotent equal repeat SHALL return the original receipt; changed payload reuse SHALL conflict. Private evidence SHALL require the same office authorization as the receipt.

#### Scenario: Partial receipt is recorded
- **WHEN** an authorized administrator allocates `40.00` to an invoice with `100.00` outstanding
- **THEN** the receipt is recorded once and the authoritative remaining balance is `60.00`

#### Scenario: Concurrent receipts exceed the available balance
- **WHEN** independent requests attempt allocations whose combined value exceeds the current outstanding amount
- **THEN** valid committed allocations are preserved and excess allocation is rejected without a negative balance

### Requirement: Reversal retains the original payment history
Administrators SHALL reverse a recorded receipt with an explicit reason, linking an immutable reversal to the original receipt and restoring only its effective allocations. Repeated reversal SHALL not restore a balance twice. A reversal SHALL be a record correction and SHALL not claim to execute a bank refund. The updated balance SHALL be reconciled against the current delinquency policy without clearing unrelated manual restrictions.

#### Scenario: A settled invoice's receipt is reversed
- **WHEN** an authorized administrator reverses its effective receipt
- **THEN** the original and reversal remain visible, the balance is restored exactly once and overdue/access consequences are recalculated

### Requirement: Delinquency policy is configurable and attributable
Each office SHALL configure manual or automatic suspension and a nonnegative integer grace period, with manual as the initial policy. Automatic suspension SHALL affect operating writes only after the due date plus grace has passed for a positive outstanding amount. Authorized read/contract/invoice access SHALL remain available. A manual suspension SHALL persist until explicitly removed. Settling or validly canceling all qualifying debt SHALL remove only an automatic delinquency hold; it SHALL NOT reactivate a canceled contract, override manual suspension or change Company/Account security suspension. Changing policy SHALL affect subsequent evaluations without altering issued invoice values.

#### Scenario: Invoice becomes overdue during grace
- **WHEN** an automatic-policy invoice is overdue but still within its grace period
- **THEN** its overdue label is shown and operating access remains as previously authorized

#### Scenario: Debt is settled while a manual hold exists
- **WHEN** a manual receipt clears qualifying debt on a manually suspended contract
- **THEN** automatic delinquency restriction is cleared if present but the manual suspension remains

### Requirement: Billing documents are printable and privately accessible
An issued billing invoice SHALL have a print-friendly representation with its actual snapshots, numbering, period and balances, supporting browser printing and saving as PDF. Output SHALL identify a commercial billing invoice and SHALL NOT pretend to be a fiscal document, Pix charge, boleto or bank-confirmed receipt. The print view SHALL be privately authorized, protected from public caching and usable without exposing internal development identifiers or secrets.

#### Scenario: Administrator prints an invoice
- **WHEN** an authorized administrator opens the invoice's print view
- **THEN** the actual issued values are rendered in the existing visual identity with printing controls and without a payment QR code or bank payment line

### Requirement: Billing reports use authoritative totals
The commercial overview SHALL report contracted licences, invoiced amounts, effective manual receipts, outstanding balances and overdue balances with explicit date criteria. Invoiced-amount period totals SHALL include original charges by issue date and debit/credit adjustments by server posting date in the office timezone, excluding canceled invoices and their adjustments. Effective receipt totals SHALL use received date; current outstanding/overdue totals SHALL include adjustments and disclose their due-date filters without claiming historical as-of reconstruction. Reversed receipts SHALL be handled consistently with their effective ledger state. Filters SHALL be office-scoped and server-paginated; unavailable reads SHALL be displayed as unknown or failed rather than fabricated zeros or successful payments.

#### Scenario: A receipt is reversed after a reporting snapshot
- **WHEN** the overview is refreshed for the same reporting period
- **THEN** effective received and outstanding totals reflect the reversal and agree with invoice detail

#### Scenario: Adjustment is posted in a later reporting period
- **WHEN** a credit is posted this month against an invoice issued last month
- **THEN** this month's invoiced-amount movement includes that credit, the original issue date remains unchanged and current balance totals agree with the adjusted invoice

### Requirement: Billing effects remain internal and recoverable
This capability SHALL not create a bank charge, Pix instrument, boleto, automatic collection, payment-provider callback or fiscal issuance. Invoice issuance/adjustment, manual receipt, reversal, cancellation and licence reconciliation SHALL have durable operation identities and attributable history. An unknown request outcome SHALL be recoverable without blindly replaying monetary effects. Background jobs SHALL revalidate the current contract and Account security state and restore tenant context after success or failure.

#### Scenario: Receipt response is lost after commit
- **WHEN** the operator recovers the same receipt operation after a network failure
- **THEN** the recorded result is returned without duplicating allocations or requiring bank/provider verification
