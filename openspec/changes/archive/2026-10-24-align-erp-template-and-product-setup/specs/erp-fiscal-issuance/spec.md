## MODIFIED Requirements

### Requirement: Fiscal interfaces and provider release are explicit
The ERP SHALL provide configuration, draft, submission, uncertain/attention, document, event and print/download screens for the included models. Real multipart/binary transport SHALL preserve ingress/session/CSRF/company checks. Production model/environment/company gates SHALL default disabled; local fake success SHALL not enable them. Missing credentials or unsupported live prerequisites SHALL be reported as unverified and block that model production acceptance. NFS-e issuance SHALL not be introduced by this capability.

Fiscal browsing SHALL not render the full creation workflow until an authorized explicit action opens it. The selected navigation area/model SHALL agree with the editor context. Model-specific required fields and errors SHALL identify the actual rejected facts, preserve entered input and provide a reachable correction path. Fiscal readiness and local generation SHALL remain distinct from provider authorization.

#### Scenario: Homologation checks lack credentials
- **WHEN** local model tests pass but the scoped provider canary cannot run
- **THEN** the result records unverified live acceptance and leaves production transmission disabled

#### Scenario: Invalid fiscal form
- **WHEN** a user submits missing model-specific mandatory fields
- **THEN** the server returns field errors without transmitting and the form preserves edits and exposes the corresponding invalid controls

#### Scenario: Browse documents before creating
- **WHEN** an authorized operator opens the document list
- **THEN** browsing and detail remain the resting task and an explicit new-draft action opens the relevant permitted editor without an overlapping creation surface

#### Scenario: Nested CT-e or MDF-e fact is invalid
- **WHEN** party, route, tax, vehicle or driver validation fails for the selected model
- **THEN** the actual field path maps to a meaningful label/control or linked error summary, entered data remains present and dispatch is blocked

## ADDED Requirements

### Requirement: Fiscal preparation consumes resolved historical sources
Issuance linked to a posted commercial source SHALL use its approved frozen tax facts, effective line CFOPs and configuration provenance. Preparation SHALL retain source identity, classification, unit, quantity, recipient and total correspondence checks; it SHALL not fall back to today's profile, nature default, product exception or company settings to fill or alter historical facts. Current issuer/configuration compatibility SHALL still be checked without substituting a different nature or code for the frozen source. An incomplete or incompatible source SHALL fail with actionable feedback before dispatch. Profile completeness SHALL not replace the selected model's own validation, builders, certificate/configuration revision or production gates. Existing snapshot readers, purchase-line contracts and return-specific source checks SHALL remain compatible; absence of new CFOP provenance SHALL not trigger historical backfill or make incomplete old sources fiscally ready. Goods CFOP exception resolution SHALL not replace the typed CT-e/MDF-e or existing return workflows.

#### Scenario: Tax profile changes after commercial posting
- **WHEN** a posted sale is prepared for issuance after its product profile or company defaults changed
- **THEN** preparation uses the original resolved source facts and preserves the posted values and provenance

#### Scenario: Historical source is incomplete
- **WHEN** required frozen source facts are missing or differ from the proposed document
- **THEN** preparation rejects the mismatch without dispatch, catalog-based repair or alteration of the posted sale

#### Scenario: Current profile is complete but document is invalid
- **WHEN** a profile supplies product fields but the chosen document lacks other mandatory model facts or authorized configuration
- **THEN** model-specific validation still fails and no provider-authorized state is claimed

#### Scenario: Product exception changes after commercial posting
- **WHEN** a posted sale's effective line CFOP came from an exception that is later changed or archived
- **THEN** preparation consumes the frozen effective code and provenance without resolving the current exception or nature default again

#### Scenario: Current fiscal configuration selects a different nature
- **WHEN** current issuer configuration is incompatible with a posted sale's frozen nature or effective CFOP
- **THEN** preparation reports the incompatibility without replacing the source, dispatching or changing the posted operation

#### Scenario: Historical source has the previous single-CFOP shape
- **WHEN** an existing source has its valid legacy operation-nature snapshot and no new resolution metadata
- **THEN** its established correspondence checks remain applicable without requiring fabricated provenance or reclassifying it from today's catalog
