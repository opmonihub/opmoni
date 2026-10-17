## Purpose

Provide company-owned commercial, product, price, fiscal and logistics reference records with safe validation for every included ERP operating module.

## ADDED Requirements

### Requirement: Commercial catalogs stay within company scope
Authorized operators SHALL manage company customers/suppliers, goods/services, variants, units, categories, brands and prices through validated APIs and Nuxt forms. Company commercial Contacts SHALL remain distinct from office Clients and tenant Companies. Related records SHALL belong to the same company; foreign resource reads SHALL return 404 and foreign relationships SHALL return 422. Archived referenced records SHALL remain readable in history without authorizing new invalid operations.

#### Scenario: Same tax identifier in two domains
- **WHEN** an office Client and a company customer have the same normalized CNPJ
- **THEN** editing the commercial customer does not edit or authorize access to the office Client

#### Scenario: Archive a used product
- **WHEN** an authorized user archives a product referenced by posted sales
- **THEN** historical line snapshots remain readable and new invalid use is rejected without cascade-deleting history

### Requirement: Catalog precision and transaction snapshots are preserved
Prices, quantities and unit-conversion values SHALL remain lossless decimal strings through API, persistence and forms. Posted consumers SHALL retain the price, description, tax/reference and conversion snapshots used at posting. Catalog updates SHALL not retroactively change historical amounts or reference meaning. Invalid precision, duplicated scoped identifiers and incompatible units SHALL return field validation errors with no partial multi-field update.

#### Scenario: Price changes after sale
- **WHEN** a product price is edited after a sale was posted
- **THEN** the posted sale and its fiscal/financial snapshots retain their original values

#### Scenario: Invalid unit conversion
- **WHEN** a multi-field catalog update includes an invalid conversion factor
- **THEN** the update returns 422 and all previous saved fields remain unchanged

### Requirement: Fiscal and logistics setup supports the included documents
Authorized configuration SHALL expose the source core establishment, tax regime, operation nature, classification, party address, carrier, vehicle and driver references required by the supported document models. Configuration changes SHALL be attributable and applicable to future operations without rewriting posted snapshots. Lookup/import previews SHALL require explicit confirmation before changing saved records; malformed documents, unavailable providers and invalid references SHALL preserve existing data and entered form values.

#### Scenario: Lookup provider fails
- **WHEN** a catalog lookup cannot complete because its provider is unavailable
- **THEN** saved data and entered edits remain available and the interface offers retry without inventing registration fields

#### Scenario: Foreign carrier in fiscal setup
- **WHEN** an operator selects a carrier belonging to another company
- **THEN** validation rejects the relationship and no configuration is persisted
