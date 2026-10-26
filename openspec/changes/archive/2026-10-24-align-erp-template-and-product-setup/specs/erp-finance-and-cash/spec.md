## MODIFIED Requirements

### Requirement: Financial pages and external outputs are truthful
Finance/cash pages SHALL show actual obligations, balances, movements and statuses with filters, field errors, retry and protected receipt/document downloads. Failed reads SHALL not become zero balances. External financial effects SHALL require durable intent and reconciliation of uncertain outcomes; a timeout SHALL not automatically cause another charge. Secrets and raw provider payloads SHALL not appear in ordinary responses or exports.

Dependent financial account/session choices SHALL remain valid for the current currency, establishment and payment method. A context change that makes a retained selection incompatible SHALL expose the incompatibility and require correction before submission, rather than hide the selected value while submitting its old identifier. Reference-fetch failure SHALL retain the selection for recovery without treating it as confirmed compatible or silently clearing it. Server authorization and compatibility validation SHALL remain authoritative.

#### Scenario: Provider response is unknown
- **WHEN** a payment-related provider request times out after it may have been accepted
- **THEN** the operation remains indeterminate/reconcilable and is not automatically charged again

#### Scenario: Balance query is unavailable
- **WHEN** a financial report request fails
- **THEN** the interface shows a retryable failure and preserves the last confirmed data rather than claiming a zero balance

#### Scenario: Currency changes after choosing an account
- **WHEN** an operator chooses an account/session and then changes currency, establishment or method to an incompatible context
- **THEN** the retained choice is visibly invalid and submission requires a compatible correction without posting an effect

#### Scenario: Compatible references cannot be refreshed
- **WHEN** a reference refresh fails or returns after the company/authority changed
- **THEN** it cannot substitute or validate stale IDs, entered values remain recoverable in their authorized context, and backend checks cannot be bypassed
