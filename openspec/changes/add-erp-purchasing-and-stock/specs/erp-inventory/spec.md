## Purpose

Maintain traceable company/establishment stock, valuation, allocations, transfers and reversals with deterministic concurrent posting behavior.

## ADDED Requirements

### Requirement: Stock movements preserve balances and source allocations
Receipts, issues, adjustments, transfers and reversals SHALL produce attributable stock movements bound to company, establishment, variant and source operation. The source-supported FIFO/LIFO company setting SHALL be preserved, defaulting to FIFO for a new company; posted valuation SHALL follow recorded receipt/lot allocations and SHALL not change retroactively. Source-supported overselling SHALL default disabled and require explicit configuration/permission before negative stock is allowed. Adjustments SHALL record actor and reason.

#### Scenario: Two sales compete for final stock
- **WHEN** concurrent sales request more combined stock than available with overselling disabled
- **THEN** only permitted quantities commit and no negative stock or duplicate allocation occurs

#### Scenario: Accounting method changes
- **WHEN** a company changes FIFO/LIFO after outgoing movements were posted
- **THEN** future allocations follow the new setting and past allocations/valuation remain unchanged

### Requirement: Transfers and reversals are atomic and idempotent
Stock transfer SHALL atomically debit and credit valid establishments within the same company. Stock participants SHALL join the caller purchase/sale transaction; repeating an equal source-operation key SHALL not post duplicate movements. Reversals SHALL retain original allocation references. Foreign establishment/variant references, insufficient permitted stock and partial write failures SHALL leave no one-sided movement.

#### Scenario: Transfer credit fails
- **WHEN** the destination movement cannot persist during a stock transfer
- **THEN** the origin debit is rolled back and both balances remain unchanged

#### Scenario: Replay stock reversal
- **WHEN** an authorized reversal is retried using its original operation key
- **THEN** the existing reversal is returned and stock is not credited twice
