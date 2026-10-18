# erp-reports Specification

## Purpose

Present reconciled company commercial, inventory, financial, cash and fiscal reports and protected snapshot exports from authoritative posted records.

## Requirements

### Requirement: Core reports reconcile authoritative posted records
Reports SHALL cover sales/purchases, stock quantity/cost/movements, receivables/payables/aging, payments/cash and fiscal statuses using their owning posted ledgers and immutable snapshots. Filtered detail and totals SHALL reconcile using consistent company/establishment/period/currency/status definitions and exact decimals. Draft, rejected, contingency and indeterminate fiscal outcomes SHALL not inflate authorized totals. Invalid or foreign filters SHALL be rejected.

#### Scenario: Drafts and authorized notes coexist
- **WHEN** a fiscal report spans draft, rejected and authorized documents
- **THEN** authorized totals count only verified authorized outcomes and other states remain separately labeled

#### Scenario: Report data is unavailable
- **WHEN** a financial or inventory report query fails
- **THEN** the UI reports failure/retry and does not present fabricated zero values

### Requirement: Exports remain private and bound to their request
Report exports SHALL be bounded authorized snapshots tied to company, actor and validated filters. Export execution and download SHALL recheck current authorization and release scope. Artifacts SHALL be private and expire; guessed IDs, revoked membership or changed context SHALL not expose a different company export. Export errors SHALL remain inspectable without marking an empty or partial file complete.

#### Scenario: Permission revoked while export runs
- **WHEN** a user loses company read permission before export download
- **THEN** download is denied even if the export was originally authorized

#### Scenario: Stale tab requests export
- **WHEN** a tab sends a company assertion inconsistent with its session selection
- **THEN** the request returns 409 before starting work or exposing cached export bytes
