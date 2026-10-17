> Reconciliation status (2026-10-13): this artifact records the earlier platform target, not the delivered system baseline. See [system reconciliation](../../../../../docs/architecture/system-spec-reconciliation.md) and `reconcile-system-specs`. D009 supersedes foreign support entry/elevation. Other stronger API, catalog, certificate-source, and concurrency guarantees remain pending where absent or unverified. Reconcile this artifact against the canonical specs before implementation, sync, or archive; do not mark its unchecked tasks complete from the import or documentation sync.

## Purpose

Provide the Serpro administration page with a genuine global credential lifecycle, secure certificate sources, and an authentication-only connectivity result without importing the legacy monitoring subsystem.

## ADDED Requirements

### Requirement: Singleton connection and safe metadata
GET `/api/v1/serpro/connection` SHALL be Super Admin-only and return `{data:{configured,consumer_key_hint,contracting_document,certificate_source,contracting_account_id,certificate_subject,certificate_serial,certificate_not_before,certificate_not_after,updated_at}}`. A successfully unconfigured platform SHALL return 200 with `configured=false` and null credential metadata. Missing/failed endpoints SHALL not be interpreted as an unconfigured success. Only one platform connection SHALL exist. Consumer secrets, complete consumer keys, private keys, certificate containers, certificate passwords, and provider tokens SHALL never be returned or logged.

#### Scenario: Fresh platform has no credentials
- **WHEN** an administrator loads Serpro without a saved connection
- **THEN** the real API returns unconfigured metadata and the page presents an initial credential form

### Requirement: Private contracting-office certificate source
The certificate selector SHALL support a dedicated platform upload and an explicitly chosen Account's stored contracting certificate, without assuming database ID 1. GET `/api/v1/admin/accounts/{id}/contracting-certificate` SHALL return safe configured/subject/serial/document/validity metadata only. PUT of the same route SHALL accept a real `.pfx/.p12` container up to 2 MiB and a password, validate a readable private-key/certificate pair, current validity, and one unambiguous supported CNPJ, and store encrypted material privately. Only Super Admin SHALL read this metadata or replace it; suspended Accounts SHALL not be accepted as new sources. The Serpro page SHALL provide an Account selector and a certificate-upload action for this source, without requiring an unrelated settings module. No certificate download/delete endpoint SHALL be added.

#### Scenario: Use a non-first Account certificate
- **WHEN** the administrator uploads a valid contracting certificate for a chosen active Account and selects that source
- **THEN** the platform connection uses that Account certificate without creating another copy of the container or relying on Account ID 1

### Requirement: Validated atomic credential creation and rotation
PUT `/api/v1/serpro/connection` SHALL accept `consumer_key`, optional replacement `consumer_secret`, `certificate_source=upload|account`, a contracting Account ID for account source, and certificate/password for upload source. Initial configuration SHALL require key, secret, and a complete valid source. Subsequent updates SHALL preserve omitted/blank replacement key/secret fields and preserve the certificate when no source change or replacement is requested. Uploaded bytes SHALL be validated beyond filename/MIME; wrong passwords, malformed/expired/not-yet-valid certificates, mismatched key/certificate, missing source, and ambiguous identity SHALL return field errors without altering the previous usable connection. Contracting document SHALL be derived from the certificate rather than editable input. Credential writes SHALL be encrypted at rest and atomic under concurrent updates, and SHALL invalidate any credential-version-dependent state. Replacing an Account source certificate SHALL update affected connection metadata atomically. Empty password in an upload request SHALL remain a valid supplied password for passwordless PKCS#12, distinct from an omitted password.

#### Scenario: Partial secret rotation
- **WHEN** an administrator supplies only a new nonempty consumer secret
- **THEN** the key and certificate remain unchanged, the encrypted secret is replaced, and the updated response contains only safe metadata

#### Scenario: Invalid replacement certificate
- **WHEN** a replacement file fails certificate validation
- **THEN** the API returns 422 and preserves the existing working credential and metadata

### Requirement: Real authentication-only connectivity
POST `/api/v1/serpro/connectivity` SHALL freshly authenticate with saved key/secret and the selected client certificate, with certificate/TLS verification enabled and server-controlled provider configuration. It SHALL return `{data:{ok,failed_element,message,checked_at}}`, where failed element is null on success or `configuracao|certificado|credencial|provedor` on failure. Unconfigured/invalid certificates SHALL fail locally before a network call; authentication refusals SHALL be classified as credential failures; transport failures, provider throttling/5xx, and incomplete token responses SHALL be provider failures. Success SHALL require both access token and JWT token in the provider response without exposing them. The check SHALL not call fiscal services, synchronize data, create tasks, or send declarations. It SHALL be limited to six calls per minute per actor and trusted client IP; exceeding the application limit SHALL return 429 with retry information. Provider network timeouts SHALL return named failure feedback rather than an indefinite spinner.

#### Scenario: Provider authentication succeeds
- **WHEN** the provider accepts the stored credential and returns both required tokens
- **THEN** connectivity reports success and neither token appears in API output, audit, logs, or client state

#### Scenario: Provider is temporarily unavailable
- **WHEN** authentication times out, is throttled, or returns a server error
- **THEN** connectivity reports a provider failure without claiming the credential is invalid or altering it

#### Scenario: Credential changes while testing
- **WHEN** a connection or attached certificate is rotated while a connectivity request is in flight
- **THEN** the request returns 409 `connection_changed` with retry feedback instead of presenting the old credential's result as current

### Requirement: Credential interface and multipart transport
`/admin/serpro` SHALL show configured metadata, update controls, a source-aware form, and a connectivity result card. Inputs SHALL never be hydrated with saved secrets, and supplied secret/password fields SHALL clear after success/cancel/navigation. Save SHALL clear an old connectivity result. Initial failure SHALL provide retry, loading SHALL be explicit, and saving/testing SHALL prevent duplicate submissions. Real binary upload SHALL work through the existing Nuxt ingress with origin/CSRF/session headers, bounded upload size, and a timeout budget compatible with the provider check. Upload validation and request failure SHALL not leak sensitive payloads.

#### Scenario: Save through the frontend proxy
- **WHEN** an administrator submits a real PKCS#12 file through the application's multipart transport
- **THEN** the backend receives the original bytes and security headers, validates/stores them, and returns safe metadata without corrupting the file
