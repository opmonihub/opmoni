# erp-core-catalogs Specification

## Purpose

Provide company-owned commercial, product, price, fiscal and logistics reference records with safe validation for every included ERP operating module.

## Requirements

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

### Requirement: Basic product creation is independent of fiscal completeness
An authorized operator SHALL be able to create a product with a name and valid active same-company unit without supplying tax classifications, rates, brand, category, warranty or explicit variants. A verified native default unit SHALL be usable without entering an opaque legacy identifier. Fiscal, commercial-detail and variant sections SHALL be optional, discoverable and preserve saved values when closed. Goods/service defaults and the rule preventing stock control for services SHALL remain intact. Save/cancel and field-error recovery SHALL remain reachable at desktop and mobile widths without forcing every advanced section into the initial task.

#### Scenario: Create a basic product
- **WHEN** an authorized operator supplies a name and valid company unit and leaves fiscal data unset
- **THEN** the product is saved with supported native defaults and no fabricated classification or tax rate

#### Scenario: No default unit is available
- **WHEN** a product form has no verified native default unit or the lookup fails
- **THEN** it requires a valid unit selection or offers an authorized setup/retry path without substituting a legacy same-number record

#### Scenario: Edit without opening advanced sections
- **WHEN** an operator changes a basic field on a product containing fiscal values and multiple variants
- **THEN** unedited tax values, variant identities, nullable price inheritance and archive history remain unchanged

#### Scenario: Basic product is not ready for issuance
- **WHEN** a tax-incomplete product is saved and later selected for an operation requiring fiscal facts
- **THEN** catalog save remains valid while the applicable operation reports missing required facts before fiscal dispatch

### Requirement: Catalog address and reference editing preserve coherent facts
Contact address editing SHALL distinguish a basic address from fiscal-required municipality/state/code completeness. Changing visible geographic facts SHALL not leave contradictory hidden fiscal codes treated as authoritative. Unrelated supported address extension keys SHALL remain preserved. Verified code/reference controls SHALL display meaningful labels and preserve existing unavailable selections; failed lookups SHALL not clear data or invent a compatible match. Product fiscal codes and decimal rates SHALL retain their exact types and values.

#### Scenario: Change municipality on a contact with a stored code
- **WHEN** an operator changes the municipality or state of a contact containing an earlier geographic code
- **THEN** the interface resolves a verified coherent code or explicitly requires review, and does not submit the old code as confirmed for the new place

#### Scenario: Save a minimal contact
- **WHEN** an operator creates an otherwise valid commercial contact without a fiscally complete address
- **THEN** the basic contact is accepted and model-specific completeness is checked only when the relevant fiscal operation requires it

#### Scenario: Lookup fails during an edit
- **WHEN** a reference or address lookup fails while an existing record is edited
- **THEN** entered values, unresolved existing references and unrelated address keys remain available with retry, without same-number namespace conversion

### Requirement: Operation natures have compatible CFOP choices and one default
Authorized operators SHALL extend the existing company-owned operation nature with active allowed CFOP choices and one explicit default, retaining its identity, optional establishment scope, description, direction, status and existing single-CFOP API contract. The default SHALL belong to the effective allowed set; list order SHALL not select it. Newly configured choices SHALL use canonical string codes from a verified reference version and satisfy supported applicability checks. Existing single-CFOP records SHALL remain readable as a one-choice nature without automatic reassignment, normalization or rewriting historical snapshots. Unverified legacy values SHALL remain visible for deliberate review; unrelated edits SHALL not silently certify or replace them.

#### Scenario: Existing client supplies only one CFOP
- **WHEN** an authorized existing client creates or updates a nature through the supported single-CFOP payload
- **THEN** the valid code remains its explicit default and an allowed choice, without requiring a new child-collection payload or changing historical references

#### Scenario: Default differs from the first allowed choice
- **WHEN** a nature has several allowed choices and an explicit default outside the first list position
- **THEN** resolution uses the configured default and preserves the ordered list only as presentation

#### Scenario: A choice update would invalidate the default
- **WHEN** an operator removes or archives the default choice without supplying a valid replacement in the same update
- **THEN** the update returns field errors and preserves the entire previous configuration

#### Scenario: Legacy code has no verified canonical mapping
- **WHEN** a nature contains an existing code that cannot be verified against the supported reference
- **THEN** its value and historical references remain readable while new fiscal preparation reports the unresolved fact rather than guessing a replacement

### Requirement: Product CFOP exceptions remain scoped to an operation nature
An authorized operation-nature configuration writer SHALL manage at most one active exception per nature and product. The product and nature SHALL belong to the selected company, the nature's establishment restrictions SHALL remain authoritative, and the exception SHALL target an active allowed choice of that nature. An exception SHALL apply to that product's variants only within the selected nature; it SHALL not assign a universal product CFOP or modify product tax-profile inheritance. Product-write authority alone SHALL not grant nature administration. Direct foreign reads SHALL return 404 and invalid or foreign related references SHALL return 422 without partial updates. Archival SHALL retain historical references and block invalid new use.

#### Scenario: One product has different operation contexts
- **WHEN** the same product has an exception in one nature and is used in another
- **THEN** only the selected nature's exception participates and the other operation retains its own valid choices and default

#### Scenario: Exception references a foreign product or another nature's choice
- **WHEN** an operator submits a foreign product or a CFOP choice outside the selected nature
- **THEN** the relationship is rejected without revealing protected foreign data or persisting part of the update

#### Scenario: A used exception is archived
- **WHEN** an authorized operator archives an exception already used by posted operations
- **THEN** the historical facts remain readable, new resolutions exclude the archived exception, and existing reviewed drafts do not silently switch to another rule

### Requirement: Operation-rule changes are atomic revisioned and free of business effects
Default, allowed-choice, exception, status and applicability changes SHALL be attributable and committed as a coherent nature configuration revision. Writes SHALL serialize on that nature and revalidate current company, establishment and write authority. New multi-choice and exception writes SHALL supply the reviewed revision; a stale revision SHALL return a recoverable 409 without partial changes. Supported legacy writes SHALL participate in the same revision and locking mechanism. Configuration changes SHALL not themselves post stock, create financial effects, emit fiscal documents or reprocess historical operations.

#### Scenario: Concurrent default and exception updates
- **WHEN** independent writers change a nature's default and an exception from the same reviewed revision
- **THEN** one coherent update commits and a stale writer receives a recoverable conflict without duplicate exceptions or a partially updated allowed set

#### Scenario: Nature administration loses authority
- **WHEN** write authority or establishment access is revoked before a pending configuration update takes effect
- **THEN** the mutation is denied and neither its revision nor any business effect is created

#### Scenario: Configuration changes after a sale is posted
- **WHEN** a nature default or product exception changes after an operation is posted
- **THEN** the catalog revision changes while the posted classification, stock, finance and fiscal source remain unchanged

### Requirement: CFOP references and product exceptions use bounded authorized lookup
CFOP reference lookup SHALL expose verified code, description, reference version and supported applicability metadata without assigning a tax treatment automatically. Nature choices and product exceptions SHALL support bounded search, filtering and pagination under existing company catalog authorization. Selected references outside the current result page SHALL remain recoverable by an authorized identity lookup. Lookup failure SHALL preserve entered data and existing selections and SHALL not be treated as an empty allowed set or permission to select another code.

#### Scenario: Search a large product-exception collection
- **WHEN** an authorized operator searches a nature's exceptions or a CFOP code outside the current page
- **THEN** bounded results and authorized selected-record hydration remain available without loading all products or leaking another company's exceptions

#### Scenario: CFOP lookup is unavailable
- **WHEN** reference lookup fails during configuration or draft review
- **THEN** the failure remains explicit and retryable, current selections remain available and no unverified replacement is saved

### Requirement: Catalog ordering applies to the authorized result set
Company catalogs SHALL support ascending/descending ordering by resource-allowlisted visible identity fields and record identifier. Authorized search/status filters and ordering SHALL apply before server pagination with a stable identifier tie-breaker. Omitted ordering SHALL preserve the existing default and response envelope. Invalid fields or directions SHALL return field validation errors without allowing arbitrary query expressions, changing archived-reference behavior or broadening Company/establishment scope.

#### Scenario: Order contacts across catalog pages
- **WHEN** an authorized operator orders matching company contacts by their visible name
- **THEN** every returned page follows that ordering across the matching company/establishment result set with stable ordering for equal names

#### Scenario: A catalog uses a different visible identity
- **WHEN** an authorized operator orders a vehicle catalog by its visible registration identity
- **THEN** the documented identity ordering is applied instead of assuming that every catalog exposes a name field

#### Scenario: Reject an unsupported ordering request
- **WHEN** an authorized catalog request contains an unsupported sort field or direction
- **THEN** it returns 422 field errors without executing that requested ordering or modifying stored records

#### Scenario: Legacy caller omits ordering
- **WHEN** an existing catalog caller submits its supported search/page query without ordering
- **THEN** its default result ordering, pagination envelope and authorized scope remain compatible

#### Scenario: Ordering does not select a CFOP default
- **WHEN** an operator changes catalog ordering or a caller omits an explicit CFOP selection
- **THEN** the existing explicit/default CFOP resolution contract remains authoritative and list position does not select a default

#### Scenario: Catalog ordering does not bypass authority
- **WHEN** an actor lacks the current company or establishment catalog read right
- **THEN** adding ordering criteria does not grant access, disclose another company's records or authorize catalog writes
