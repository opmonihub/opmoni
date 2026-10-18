# erp-purchasing Specification

## Purpose

Provide supplier purchase drafting, posting and returns with validated snapshots and atomic inventory/financial effects in the company workspace.

## Requirements

### Requirement: Purchases have validated immutable posting and returns
Authorized users SHALL create/edit purchase drafts and explicitly post them against same-company supplier, establishment, product/unit and price/tax references. Posting SHALL preserve immutable snapshots and commit purchase, stock and finance effects together. Posted correction SHALL use linked returns/reversals; quantities SHALL not exceed the remaining returnable amounts or the applicable stock policy. Reads/writes SHALL enforce company action permissions.

#### Scenario: Purchase post fails in finance
- **WHEN** financial validation fails while posting a receipt
- **THEN** no posted purchase, stock receipt or obligation from the attempt commits

#### Scenario: Excess supplier return
- **WHEN** an operator requests a return greater than the remaining eligible purchase quantity
- **THEN** validation rejects the request and preserves all stock and financial balances

### Requirement: Purchase imports are explicit previews
Source-supported purchase XML upload SHALL be bounded, authenticated and company-scoped, producing a preview of parsed supplier/items/keys and field errors before confirmation. Malformed XML, wrong company/party references and duplicate fiscal keys SHALL not silently create or overwrite posted purchases. Forms SHALL preserve edits and distinguish service failure from invalid user data.

#### Scenario: Duplicate purchase XML
- **WHEN** a previously confirmed purchase XML key is uploaded again
- **THEN** the preview identifies the duplicate and confirmation cannot create a second effective receipt

#### Scenario: XML preview fails
- **WHEN** parsing or lookup fails before a purchase import is confirmed
- **THEN** no stock or financial effect occurs and the operator receives specific retry or validation feedback
