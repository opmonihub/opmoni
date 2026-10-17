## Purpose

Maintain company financial obligations, allocations, ledger movements and cashier sessions with auditable, atomic effects shared by purchases and sales.

## ADDED Requirements

### Requirement: Posted financial history is precise and attributable
The system SHALL expose company-scoped financial accounts, obligations, expenses/revenue, payments and allocations with exact decimal amounts and recorded actor/source references. Posted records SHALL be immutable; corrections SHALL create linked reversals rather than erase history. Allocations SHALL not exceed the open amount. Foreign party/account/source references SHALL be rejected and office Account records SHALL not represent financial ledger accounts.

#### Scenario: Concurrent overpayment attempts
- **WHEN** two payment attempts together exceed an obligation remaining amount
- **THEN** only valid allocation commits and the final balance cannot be overpaid by the race

#### Scenario: Reverse an allocation
- **WHEN** an authorized operator corrects a posted payment allocation
- **THEN** the original record remains visible with an attributable compensating reversal and reconciled balance

### Requirement: Business posting and finance commit together
Purchase/sale posting SHALL commit its financial obligations, allocations and applicable cash effects in the same database transaction as the source operation. A stable source-operation key SHALL prevent duplicate effects. A validation or injected failure SHALL roll back all participants; no participant SHALL report success for independently committed partial work.

#### Scenario: Sale posting fails after obligation creation
- **WHEN** stock or cash validation fails before the sale transaction commits
- **THEN** neither the sale posting nor any obligation, payment or movement from that attempt remains committed

#### Scenario: Repeated source posting
- **WHEN** an already committed source operation is submitted again with the same key and payload
- **THEN** the original financial result is reused without another obligation or payment

### Requirement: Cash sessions enforce ownership and explicit reconciliation
Cash opening, movements, withdrawals/deposits and closing SHALL require company/register/establishment authorization and cashier permissions. Closing SHALL reconcile opening amount and posted movements against a declared count, record discrepancies and prevent later posting into the closed session. Concurrent opening/closing SHALL preserve constrained session ownership and repeating an equal completed close SHALL be idempotent.

#### Scenario: Payment races cashier close
- **WHEN** one request closes a cash session while another attempts to add a payment
- **THEN** the operations serialize into a valid committed order and no payment is silently posted into a closed session

#### Scenario: Count differs from expected balance
- **WHEN** the cashier declares an amount different from the recorded expected balance
- **THEN** closing records the difference and its actor without rewriting earlier movements

### Requirement: Financial pages and external outputs are truthful
Finance/cash pages SHALL show actual obligations, balances, movements and statuses with filters, field errors, retry and protected receipt/document downloads. Failed reads SHALL not become zero balances. External financial effects SHALL require durable intent and reconciliation of uncertain outcomes; a timeout SHALL not automatically cause another charge. Secrets and raw provider payloads SHALL not appear in ordinary responses or exports.

#### Scenario: Provider response is unknown
- **WHEN** a payment-related provider request times out after it may have been accepted
- **THEN** the operation remains indeterminate/reconcilable and is not automatically charged again

#### Scenario: Balance query is unavailable
- **WHEN** a financial report request fails
- **THEN** the interface shows a retryable failure and preserves the last confirmed data rather than claiming a zero balance
