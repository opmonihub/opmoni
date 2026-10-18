## MODIFIED Requirements

### Requirement: Account fiscal coverage and document reads
The Fiscal pages SHALL use `/api/v1/fiscal/summary`, `/fiscal/clients`, and `/fiscal/documents` to show persisted coverage, client capture attention, and paginated complete documents. The accounting document list and document aggregates SHALL include only complete document XML records whose private bytes are verified available and consistent with their persisted byte length and SHA-256, with one row per Account, client, source and document key. Stage or a prior completion marker alone SHALL NOT establish readiness; unverified, missing or corrupt artifacts SHALL be excluded from ready results and remain visible in operational attention without deleting historical records or accepted event audits. Storage-service failure SHALL be reported as a read failure rather than successful zero counts or a claim that all artifacts are missing. Summaries and standalone events SHALL NOT appear as accounting document rows or inflate ready-document totals; events SHALL remain available in the corresponding completed document's timeline. Filters SHALL be validated and restricted to the selected Account, including references to its deleted clients. Detail SHALL expose safe document metadata, events, and a bounded display preview rather than certificate secrets or storage paths. Processing and terminally incomplete records SHALL remain inspectable separately from ready documents.

#### Scenario: Foreign document
- **WHEN** a member requests a document owned by another Account
- **THEN** the API returns 404 without exposing XML or metadata

#### Scenario: Invalid document filter
- **WHEN** the requested model, date, client, or value filter is invalid
- **THEN** the API returns 422 rather than applying an unintended broader query

#### Scenario: Summary without complete XML
- **WHEN** distribution has delivered a summary but its complete document XML is absent
- **THEN** the summary is absent from the ready list and ready aggregates and appears once in the separate processing view with its actual state

#### Scenario: Complete document arrives after its summary
- **WHEN** a validated complete XML is committed for a key whose summary and events already exist
- **THEN** the ready list publishes one document row, retains the events in its timeline, and no longer counts that key as processing

#### Scenario: Cancelled note has complete XML
- **WHEN** a completed document has a cancellation event for the same Account, client and key
- **THEN** the ready list retains the complete document with cancelled status rather than publishing the cancellation as another note

### Requirement: XML download preserves original bytes
`GET /api/v1/fiscal/documents/{id}/xml` SHALL authorize the document before private storage access and return the stored complete XML bytes as an attachment. Summary and standalone-event identifiers SHALL NOT produce a successful accounting-document download or normal accounting detail; authorized requests to such identifiers SHALL return 409 with a safe processing-not-ready reason. Foreign identifiers SHALL still return 404. Missing stored XML SHALL return 404 rather than an empty successful file. Complete XML whose stored bytes fail integrity verification SHALL return 409 with a safe integrity-failure reason rather than a successful accounting download. A missing or unreadable preview SHALL not turn the safe metadata view into a fabricated complete document.

#### Scenario: Stored file is missing
- **WHEN** an authorized complete document exists but its XML file is missing
- **THEN** download returns 404, ready results exclude the unavailable artifact, operational attention retains its safe metadata and detail does not invent XML content

#### Scenario: Stored file integrity differs
- **WHEN** an authorized complete document's private bytes differ from its persisted length or SHA-256
- **THEN** the file is excluded from ready results, an integrity attention reason is visible and download returns 409 without serving corrupted XML as valid

#### Scenario: Old summary bookmark
- **WHEN** an authorized member requests the normal detail or XML download for a summary identifier saved before the complete-only change
- **THEN** the API returns 409 with a safe not-ready reason and does not serve a summary as a complete XML

### Requirement: Source rollout and manifestation are explicit
CT-e, NFS-e, and manifestation SHALL retain their separate feature/configuration gates. The CT-e scheduled gate SHALL remain a separate operator decision from the manual gate; neither a fake test nor a single-client canary SHALL be represented as approval for fleet-wide production scheduling. Manifestation and its recovery SHALL run only when enabled, with explicit client/Account identifiers. Automatic NF-e science SHALL use only event `210210`, remain non-conclusive, and never send `210200`, `210220` or `210240`. A queued or locally stored result SHALL not claim live-provider acceptance. The interface SHALL explain in both Processing and completed NF-e detail that science and XML availability do not conclude or confirm the operation. It SHALL state that subsequent conclusive manifestation remains the recipient's responsibility within ninety days of provider authorization under the applicable rules, display the authorization date and calculated conclusive deadline when verifiable, and label an unknown deadline as unverifiable rather than inventing it. Completing XML retrieval SHALL NOT hide this notice or claim a conclusive event without correlated recorded evidence.

#### Scenario: Feature remains disabled
- **WHEN** manifestation or an additional capture source has not been enabled
- **THEN** the UI and operational response do not claim the corresponding external action succeeded

#### Scenario: Science enabled without conclusive automation
- **WHEN** an eligible inbound NF-e is processed with the science gate enabled
- **THEN** the only automatically sent recipient event is `210210` and the office is not told that the operation was confirmed or rejected

#### Scenario: Completed XML still requires a conclusive decision
- **WHEN** XML completion removes a note from active processing
- **THEN** the completed NF-e detail retains the non-conclusive-science notice and the ninety-day authorization-based conclusive deadline without marking the operation confirmed merely because XML is available

## ADDED Requirements

### Requirement: Science eligibility uses provider authorization and current note state
Automatic science SHALL be permitted only for a validated inbound NF-e summary with persisted trusted evidence of authenticated provider delivery to the recipient, a usable client certificate and provider authorization timestamp, within ten days of that authorization. Delivery evidence SHALL identify the queried recipient, environment, receive time and correlated Account/client/source invocation and SHALL originate from the trusted capture boundary, not XML content or user-supplied metadata. A summary root, access key, source field, NSU, captured timestamp or re-parsed historical XML alone SHALL NOT prove authenticated provenance. Historical records without trusted delivery evidence SHALL withhold new automatic science and expose an unverified-delivery attention reason; they SHALL NOT be silently promoted during metadata backfill. Issue time SHALL remain separate and SHALL NOT be substituted for authorization time. Self-issued, cancelled or denied notes, invalid identities, missing or future authorization timestamps, expired science deadlines, and already concluded operations SHALL NOT receive new automatic science. Missing conditions SHALL produce a safe actionable processing reason, not a fabricated provider rejection. Previously accepted science SHALL permit guarded recovery without resending the event merely because its original science deadline has since passed.

#### Scenario: Authorization and issue dates differ
- **WHEN** the issue date is old but the provider authorization timestamp is within the science deadline
- **THEN** eligibility is evaluated from authorization and the displayed issue date remains unchanged

#### Scenario: Historical authorization outside the deadline
- **WHEN** a summary's authorization is more than ten days old and no accepted science is known
- **THEN** no new science is sent and the record is shown as deadline-expired attention rather than indefinitely processing

#### Scenario: Missing authorization date
- **WHEN** a summary does not have a trustworthy provider authorization timestamp
- **THEN** automatic science is withheld and the processing view explains that the deadline cannot be verified

#### Scenario: Historical XML lacks authenticated delivery evidence
- **WHEN** backfill can read a historical summary and recover dates but no trusted correlated delivery evidence exists
- **THEN** provenance remains unverified, no new automatic science is sent and operational attention explains the missing evidence

#### Scenario: Forged provenance metadata
- **WHEN** imported XML or a caller supplies fields claiming authenticated delivery
- **THEN** those claims do not establish eligibility and cannot cause automatic science

#### Scenario: Cancelled or self-issued summary
- **WHEN** the note is cancelled, denied or issued by the same client
- **THEN** no automatic science is sent for that note

### Requirement: XML completion is durable and validated
Accepted or already-registered science SHALL initiate asynchronous XML completion without waiting for the nightly reconciliation alone. Each pending logical note SHALL have a durable state and next eligible attempt time. A recovery response SHALL be considered complete only when it contains a complete NF-e document for the requested key and authorized recipient; a summary, event, wrong key, wrong recipient or failed persistence SHALL NOT mark recovery complete. Cooldowns, locks and the shared CNPJ lookup budget SHALL defer work without charging an attempt that never reached the provider. Transient failures and provider publication delays SHALL use bounded retries; permanent rejection, missing prerequisites or exhausted retries SHALL enter attention instead of a permanent loading state. Normal incremental distribution SHALL also be allowed to complete the same logical note idempotently.

#### Scenario: Science accepted but full XML not yet published
- **WHEN** science has been accepted but recovery returns no complete document
- **THEN** the note remains outside the ready list with a future retry time and a truthful waiting-for-XML state

#### Scenario: Recovery returns summary or different key
- **WHEN** the provider response is not a complete document for the requested logical note
- **THEN** no ready row or successful-recovery marker is created and the unsuccessful attempt is handled according to the bounded retry policy

#### Scenario: Lookup budget exhausted
- **WHEN** the client has exhausted its permitted point-lookup budget
- **THEN** work is deferred to the next permitted window without duplicate calls or consumption of an unexecuted attempt

#### Scenario: Storage failure
- **WHEN** a complete XML response cannot be durably stored
- **THEN** the item remains non-ready with a recoverable or actionable failure and is not reported as a successful completed XML

### Requirement: Processing is visible without polluting ready documents
A separate Account-scoped processing view SHALL show safe identifiers, client, key, authorization date when known, state, reason and next eligible attempt time. It SHALL distinguish active processing, deferred work and attention, including deadline expiry and terminal rejection. Any real member permitted to read Fiscal SHALL be allowed to read these states; the view SHALL preserve existing session and Account checks. Records with verified available complete XML SHALL be excluded from active-processing and XML-completion attention totals. Complete-document records whose file is unverified, missing or corrupt SHALL remain visible once in operational attention, even without a corresponding summary; this SHALL preserve their historical completion and accepted science audits rather than falsely reopening science. Missing or unavailable processing data SHALL be shown as failure, not successful zero. Processing status refresh SHALL NOT trigger provider calls.

#### Scenario: Pending documents but ready list empty
- **WHEN** no complete XML exists but summaries are pending
- **THEN** the ready list explains that no XML is ready yet and exposes the separate processing count instead of claiming no fiscal work exists

#### Scenario: Read-only member
- **WHEN** a read-only member opens the processing view
- **THEN** the member can inspect current Account states but cannot enqueue mutations or see another Account's records or secrets

#### Scenario: Provider rejection
- **WHEN** science or recovery has a definitive rejection
- **THEN** the item shows an actionable attention state and is not labelled queued or successfully recovered

#### Scenario: File disappears after completion
- **WHEN** a previously completed XML is later missing or corrupt in private storage
- **THEN** it is excluded from ready counts and results and appears once in operational attention, with its completion/audit preserved and no automatic resend of accepted science

#### Scenario: Full document has no summary
- **WHEN** a complete-document record has unavailable private XML and no summary record exists
- **THEN** operational attention still shows that document's safe identity and storage reason instead of losing it from every view

### Requirement: Historical reconciliation is safe and repeatable
Existing summaries SHALL be reconciled by Account, client, source and key without deleting summaries, events or XMLs, resetting distribution cursors, or altering provider dates. Dry-run SHALL make no writes or external calls. Applying reconciliation SHALL enqueue only eligible science or recovery, preserve accepted events and completed documents, and classify historical exclusions explicitly. Repeating reconciliation SHALL NOT duplicate logical records, ready rows or external event requests.

#### Scenario: Existing available complete XML alongside summary
- **WHEN** historical reconciliation encounters a summary whose complete XML is verified available and intact
- **THEN** the completed document remains ready and no new science or recovery is enqueued

#### Scenario: Historical full record lacks usable stored bytes
- **WHEN** historical reconciliation encounters a complete-document record whose private XML is missing or corrupt
- **THEN** it preserves the record and accepted event audit, excludes the artifact from ready results and records storage attention without resending accepted science

#### Scenario: Reconciliation preview
- **WHEN** an operator previews historical reconciliation for a specified Account and client
- **THEN** only a safe classification summary is returned and no event, provider lookup or database mutation occurs
