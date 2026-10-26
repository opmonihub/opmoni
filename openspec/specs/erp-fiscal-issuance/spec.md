# erp-fiscal-issuance Specification

## Purpose

Issue and manage company-owned NF-e, NFC-e, CT-e and MDF-e with protected certificates, durable provider outcomes and verified original artifacts.

## Requirements

### Requirement: Fiscal configuration and certificates belong to the issuer
Fiscal actions SHALL validate the authorized company, issuer establishment, supported model/environment, relevant commercial snapshot and model-specific required fields. Company certificate/password material SHALL be encrypted and privately stored; ordinary metadata SHALL omit secrets and storage paths. Upload validation SHALL verify usable PKCS12, supplied password, key/certificate identity and issuer compatibility before replacement. Each fiscal attempt SHALL use one coherent certificate/password/configuration revision; rotation SHALL not mix old and new material within an attempt. A foreign company, arbitrary office certificate or SERPRO singleton SHALL not supply issuer authority.

#### Scenario: Wrong issuer certificate
- **WHEN** a permitted user uploads certificate material incompatible with the selected issuer
- **THEN** replacement returns validation failure and preserves the prior usable configuration

#### Scenario: Ordinary viewer transmits
- **WHEN** a company viewer directly calls the fiscal transmission action
- **THEN** the API returns 403 without creating a provider effect

#### Scenario: Certificate rotates during an attempt
- **WHEN** certificate configuration is replaced while a fiscal attempt is being prepared or executed
- **THEN** the attempt uses its coherent authorized revision or stops before dispatch, and never signs or authenticates with mixed revisions

### Requirement: Transmission outcomes are durable and idempotent
Generation/signature, queued transmission, submitting, authorization, rejection and indeterminate outcomes SHALL remain distinguishable. A stable operation identity and document number/key SHALL be recorded before provider dispatch. An unknown timeout/crash outcome SHALL be reconciled using the same key/receipt before another transmission. Duplicate responses SHALL not create another authorized document or reuse its number for different content. Queued/local XML alone SHALL not claim acceptance.

#### Scenario: Provider accepted before timeout
- **WHEN** the provider may have authorized a document but the response was lost
- **THEN** the document remains indeterminate until correlated consultation resolves it and blind duplicate transmission is withheld

#### Scenario: Competing document number
- **WHEN** independent requests allocate the same issuer/model/series number
- **THEN** only one logical document can claim that number and the competing attempt cannot overwrite it

### Requirement: Ready fiscal artifacts are complete private and verifiable
Authorized publication SHALL require complete model-correct XML, correlated key/environment/issuer/protocol and durably available original bytes with saved length/SHA-256. XML and auxiliary documents SHALL be privately served only after company/action authorization. Missing/corrupt files or storage outages SHALL produce failure/attention rather than a fabricated successful artifact. Temporary signing material SHALL be cleaned on success and exceptions; secrets SHALL not enter logs or public rendering state.

#### Scenario: Authorization artifact cannot persist
- **WHEN** provider acceptance is known but XML storage fails
- **THEN** acceptance history is retained and the artifact remains non-ready/recoverable rather than a successful empty download

#### Scenario: Cross-company XML download
- **WHEN** an operator guesses a foreign fiscal document identifier
- **THEN** the API returns 404 without reading or returning its bytes

### Requirement: Events and contingency preserve actual model state
Supported cancellation, correction, return, invalidation, CT-e complement/substitution and MDF-e closure SHALL be explicit model-valid permitted actions with required input, durable attempts and correlated outcomes. Failed events SHALL not rewrite accepted history. Source-supported NFC-e contingency SHALL remain distinguishable from provider authorization and require later reconciliation. Automatic recipient science or conclusive manifestation SHALL not be triggered by self-issued ERP documents.

#### Scenario: Cancellation refused
- **WHEN** a cancellation request receives a provider rejection
- **THEN** the original document remains authorized with a recorded rejected cancellation attempt

#### Scenario: Local contingency output
- **WHEN** NFC-e is generated under an explicitly enabled supported contingency flow
- **THEN** the interface labels its real contingency/reconciliation state and does not claim provider authorization

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
