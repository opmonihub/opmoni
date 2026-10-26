## Purpose

Reduce repeated product tax entry through company-owned reusable configurations, explicit field inheritance and revisioned resolution that preserves immutable commercial and fiscal history.

## ADDED Requirements

### Requirement: Tax profiles are company-owned reusable configurations
Authorized operators SHALL manage named active/archived tax profiles within the selected company under explicit granular grants. Profiles SHALL cover the currently supported ICMS, PIS, COFINS, IPI, Simples credit and IBS/CBS classification fields with applicable regime/context constraints and attributable revisions. Codes SHALL remain strings preserving leading zeroes; rates SHALL remain exact decimal strings. Profiles SHALL not imply a universally valid tax treatment, infer NCM/CEST for all products or replace operation-specific CFOP/calculation rules. Operational CFOP choices, defaults and product exceptions SHALL belong to the selected operation nature rather than profile-field inheritance. Foreign reads SHALL return 404 and foreign relationships SHALL return 422.

#### Scenario: Two products reuse one configuration
- **WHEN** two company products explicitly select the same compatible profile
- **THEN** their inherited fields resolve from that profile revision without requiring duplicate manual tax entry

#### Scenario: Foreign profile reference
- **WHEN** an operator assigns a profile belonging to another company
- **THEN** the relationship is rejected without reading protected foreign details or changing the product

#### Scenario: Read-only or revoked authority
- **WHEN** a viewer or an operator whose write grant/delegation was revoked attempts profile mutation
- **THEN** the server denies the mutation and no new profile revision or operation effect is created

#### Scenario: Product profile and operational exception both apply
- **WHEN** a product inherits tax fields from a profile and the selected nature supplies a valid product CFOP exception
- **THEN** profile inheritance supplies only its supported tax fields and the separate operational resolver determines CFOP with its own provenance

### Requirement: Product field intent distinguishes inheritance from explicit values
Each supported tax field SHALL distinguish an explicit product value, inheritance and an explicit unresolved value. Resolution for an inherited field SHALL prefer an applicable selected profile, then an explicitly configured compatible company default, then remain unresolved. Explicit zero SHALL never trigger fallback. Existing API omission SHALL retain unchanged behavior and explicit null SHALL retain clear behavior; inheritance SHALL use explicit intent rather than reinterpret null. Existing products and legacy-null fields SHALL not opt into inheritance without deliberate adoption.

#### Scenario: Override only PIS
- **WHEN** an operator gives a product an explicit PIS override while other fields inherit
- **THEN** only the chosen PIS field differs and the remaining fields retain their applicable profile/default provenance

#### Scenario: Preserve zero and leading zeroes
- **WHEN** a product uses an explicit zero rate and an inherited code `01`
- **THEN** the zero remains zero and the code remains the exact string rather than falling back or becoming numeric 1

#### Scenario: Existing product has null tax fields
- **WHEN** profile functionality is introduced or an unrelated product field is edited
- **THEN** existing nulls remain explicitly unresolved and do not acquire company defaults without adoption

#### Scenario: New product uses a configured company profile
- **WHEN** a company has an explicitly configured default profile and an operator opens new-product creation
- **THEN** the form offers visible company-default inheritance with an effective preview and an explicit option to save without tax setup, rather than silently filling hidden fields

### Requirement: Effective tax resolution is explicit and failure-aware
Product configuration and operational preparation SHALL expose effective tax facts, unresolved fields, applicability failures and value sources. An explicitly selected profile that is archived, incompatible or unavailable SHALL produce a visible resolution failure rather than silently fall back to the company default. Choosing company-default inheritance without an explicit profile SHALL remain distinguishable from choosing a profile that cannot be resolved. Basic product save without tax setup SHALL remain possible; an invalid explicitly supplied relationship SHALL still be rejected.

#### Scenario: Selected profile becomes unavailable
- **WHEN** a selected profile is archived, denied or fails to load
- **THEN** the interface retains the selection for review, reports the failure and does not substitute another tax configuration or clear overrides

#### Scenario: No configuration is selected
- **WHEN** a basic product is saved with no profile and unresolved tax facts
- **THEN** product creation succeeds and later model-specific readiness identifies the facts required for that operation

### Requirement: Profile revisions resolve coherently before immutable snapshots
Tax facts and provenance used by a commercial/fiscal operation SHALL come from one coherent applicable profile/default revision. Where operational CFOP resolution participates, its nature/default/exception revision SHALL be captured coherently with those tax facts and identified separately. A concurrent configuration edit SHALL not produce mixed-revision facts. Effective values, explicit overrides and source/revision provenance SHALL be frozen with the relevant operation before it becomes immutable. Profile/default and operation-rule changes SHALL apply to newly resolved or explicitly refreshed eligible drafts, never silently to reviewed drafts or posted history. Product profile resolution SHALL not double-apply standalone commercial tax rates, override operational CFOP precedence or bypass authoritative totals.

#### Scenario: Profile changes while an operation is saved
- **WHEN** independent requests edit a profile and resolve/save or post an operation concurrently
- **THEN** the operation records one coherent authorized revision or returns a recoverable conflict before effects, without a mixture of revisions

#### Scenario: Refresh an existing draft
- **WHEN** a saved draft's inherited configuration has changed
- **THEN** current frozen draft facts remain visible until an explicit refresh/recalculation is reviewed, and posting cannot silently substitute newer facts

#### Scenario: Profile changes after posting
- **WHEN** a profile is updated or archived after a related sale is posted
- **THEN** the posted sale and its financial/fiscal consumers retain their original values and revision provenance

### Requirement: Profile adoption and archive preserve recoverability
Profile selection, company defaults and per-product intent changes SHALL preserve current Company/Account/session authority, field errors and entered values on failure. Adopting inheritance for existing products SHALL be explicit and review its effective changes. Archived profiles SHALL retain historical references and block invalid new selection without deleting revisions used by posted operations. Lookup errors SHALL not trigger adoption, clearing or automatic mass updates.

#### Scenario: Adoption changes an existing product
- **WHEN** an authorized operator deliberately adopts a profile for an existing product
- **THEN** the effective changes and retained overrides are reviewable before save, while posted operations remain unchanged

#### Scenario: Late save after company switch
- **WHEN** a profile/product response arrives after the selected Company, delegated Account or effective authority changed
- **THEN** it cannot repopulate the new context, display stale success or authorize a retry under the former context
