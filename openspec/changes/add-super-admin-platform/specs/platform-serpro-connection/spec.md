> Reconciled completion target (2026-10-17), preserving D009 and existing canonical access contracts. Pending guarantees are not implemented by this artifact revision; canonical synchronization follows reviewed delivery.

## MODIFIED Requirements

### Requirement: Connection metadata has explicit read authorization
GET `/api/v1/serpro/connection` SHALL retain safe `{data:{configured,consumer_key_hint,contracting_document,certificate_subject,certificate_serial,certificate_not_before,certificate_not_after,updated_at}}` metadata and add certificate_source and contracting_account_id plus a safe legacy-source hint where applicable. Reading SHALL permit Super Admin or a member of the currently selected active Account; writing/connectivity and every new platform-vault endpoint SHALL require Super Admin. An absent connection SHALL return 200 configured=false; failed loading or 404 SHALL not become unconfigured success. Complete keys, secrets, private keys, PKCS12 bytes/passwords and provider tokens SHALL never be returned or logged. Consumer key SHALL be encrypted at rest and hidden from generic model serialization through a preserving migration of existing plaintext, retaining masked hint/internal operational reads. No destructive reset or blind encrypted cast over plaintext SHALL occur.

#### Scenario: Unconfigured platform
- **WHEN** an authorized caller reads before any credential is stored
- **THEN** the API returns successful unconfigured metadata

#### Scenario: Nonmember requests metadata
- **WHEN** a non-Super Admin has no active selected membership
- **THEN** the metadata read is denied

#### Scenario: Existing consumer key migration
- **WHEN** historical plaintext keys are migrated
- **THEN** their value remains usable internally while storage is encrypted and generic serialization omits the complete key

### Requirement: Atomic singleton credential configuration and rotation
PUT `/api/v1/serpro/connection` SHALL configure or rotate the singleton and return 200 initially and on updates. Initial configuration SHALL require key, secret and a usable source. Omitted or blank supported key/secret replacement fields SHALL preserve values; omitted certificate source/replacement SHALL preserve the saved certificate. Explicit empty supplied certificate password SHALL remain distinct from omission and SHALL support a valid passwordless container. PKCS12 upload SHALL enforce 2 MiB and real readable matching private key/certificate, current not-before/not-after validity and one unambiguous supported CNPJ. Malformed/wrong-password/expired/future/mismatched/ambiguous/runtime-unsupported material SHALL fail safely before replacement, preserving the prior usable connection. Caller contracting document SHALL be prohibited; derive it from the certificate. Shared parsing SHALL preserve the distinct expired historical client-certificate contract. Credential/source mutation SHALL advance revisions, update attached metadata atomically and invalidate/version operational auth cache so in-flight old authentication cannot populate the new generation. Independent process races SHALL prove first-write singleton, coherent source/metadata/revision and rollback.

#### Scenario: Failed replacement
- **WHEN** a replacement certificate or password is invalid
- **THEN** validation fails and preserves the current usable credential

#### Scenario: Competing initial writes
- **WHEN** two independent processes attempt initial configuration
- **THEN** one coherent singleton persists without mixed credentials or duplicate rows

#### Scenario: Supplied empty password
- **WHEN** a valid passwordless container is uploaded with an explicit empty password
- **THEN** transport and validation preserve the supplied empty value instead of treating it as omitted

#### Scenario: Stale operational authentication
- **WHEN** old-generation operational authentication completes after credential rotation
- **THEN** its tokens cannot overwrite the new generation's operational cache

### Requirement: Certificate sources remain explicit in the current interface
Existing `/serpro/account-certificate` and use_account_certificate=true SHALL retain their authorized legacy tenant-vault behavior and existing links, without automatically copying tenant certificates into a platform vault. Tenant AccountCertificate ownership and tenant-admin term-issuance behavior SHALL remain unchanged. Add certificate_source=upload|account: new explicit account choices SHALL require contracting_account_id of an active Account with the separate platform contracting vault certificate; upload SHALL use saved/new dedicated connection material and clear both tenant and platform attachment references. Existing contracting_account_id SHALL retain office/legacy attachment meaning, and explicit new platform-vault reference/provenance SHALL distinguish the domains. Unchanged legacy connections SHALL remain usable and show a safe legacy-source hint. Simultaneous legacy/new selectors or conflicting uploaded/reused sources SHALL return 422, never silently choose. An invalid/missing/suspended source SHALL fail locally rather than fall back to another Account. The UI SHALL provide paginated Account search, safe source metadata/inline platform upload, source-aware rotation, field errors/retry and duplicate guards without a new general settings module.

#### Scenario: Both certificate sources submitted
- **WHEN** a request includes a new container and asks to reuse the office certificate or mixes legacy and new selectors
- **THEN** the API returns 422 rather than guessing the intended source

#### Scenario: Explicit Account choice with legacy-only certificate
- **WHEN** the administrator explicitly chooses an Account that has only a tenant certificate
- **THEN** validation requires the separate platform certificate without copying or mutating tenant material

#### Scenario: Unchanged legacy connection
- **WHEN** an existing legacy-linked connection is read or used without changing its source
- **THEN** it remains operationally compatible and shows only safe legacy-source metadata

### Requirement: Connectivity is safe authentication only
POST `/api/v1/serpro/connectivity` SHALL require Super Admin and independently enforce six-per-minute actor AND trusted-ingress-IP budgets, returning local 429 with Retry-After. It SHALL use fresh diagnostic auth from one saved coherent snapshot, separate from operational pair()/verify() semantics, and SHALL never remove/overwrite usable operational cached tokens. Authentication SHALL use the server-controlled official provider protocol with Basic key/secret, form grant_type=client_credentials, Role-Type TERCEIROS, mutual TLS, TLS verification, no redirects, 3s connect/8s total, and require both returned tokens before discarding them. No fiscal/monitoring/job/term/sync operation SHALL run. The diagnostic response SHALL preserve `{data:{ok,failed_element,message,checked_at}}`, with failed_element exactly `configuracao|certificado|credencial|provedor` on failure and null on success. An absent connection or incomplete consumer key/secret SHALL yield `configuracao` without network. An unusable certificate/source SHALL yield `certificado` without network; provider credential rejection SHALL yield `credencial`, and upstream 429/5xx/timeout SHALL yield `provedor`, without sensitive upstream payloads. Certificate material SHALL use private random temporary files with 0600 and guaranteed cleanup on success/decrypt/parse/HTTP/exception paths. Before reporting a provider verdict, source identity/revisions/eligibility and connection revision SHALL be rechecked; changed/removed/suspended source or rotated credentials during HTTP SHALL yield 409 connection_changed, not stale success or stale failure.

#### Scenario: Credentials unconfigured
- **WHEN** no connection exists or the saved consumer key/secret is incomplete
- **THEN** the diagnostic returns `{data:{ok:false,failed_element:"configuracao",message,checked_at}}` without a provider call

#### Scenario: Local certificate unusable
- **WHEN** the saved source cannot currently be used or materialized
- **THEN** the diagnostic returns safe local certificado failure without a provider call

#### Scenario: Provider unavailable
- **WHEN** the authentication provider times out or rejects availability
- **THEN** the diagnostic reports provider failure without fiscal/monitoring synchronization or secret payloads

#### Scenario: Diagnostic token isolation
- **WHEN** fresh diagnostic authentication returns both token fields
- **THEN** the diagnostic discards them, preserves usable operational token caching and returns `{data:{ok:true,failed_element:null,message,checked_at}}`

#### Scenario: Source changes during HTTP
- **WHEN** a source is rotated, removed or suspended while diagnostic HTTP is in flight
- **THEN** the result is 409 connection_changed rather than a verdict for the superseded snapshot

#### Scenario: Independent throttles
- **WHEN** either the actor or trusted-IP minute budget is exhausted
- **THEN** the application returns 429 Retry-After before contacting the provider even if the other budget has capacity

## ADDED Requirements

### Requirement: Separate platform contracting-certificate vault
GET/PUT `/api/v1/admin/accounts/{id}/contracting-certificate` SHALL require Super Admin and operate only on a separate platform vault with unique restricted Account reference, encrypted hidden container/password, safe document/X509 metadata and monotonic revision. GET SHALL return safe configured/subject/serial/document/validity/revision metadata; PUT SHALL enforce the same real PKCS12 validation and active Account rule as platform source selection. No download/delete API SHALL be added. Replacing an attached platform source SHALL update its connection metadata/revision atomically and SHALL not call the tenant certificate replacement path, issue terms or dispatch synchronization. The source SHALL reference the platform vault without copying its container to the connection.

#### Scenario: Arbitrary active Account source
- **WHEN** Super Admin uploads a valid certificate for an active non-first Account and selects that source
- **THEN** the connection references its separate platform vault and no tenant certificate or term job changes

#### Scenario: Unauthorized or suspended source
- **WHEN** a non-Super Admin invokes the platform vault API or a suspended Account is selected/replaced as source
- **THEN** authorization or validation rejects the request without secret disclosure or partial mutation

### Requirement: Coherent credential lifecycle snapshots
Credential/source lifecycle work SHALL be serialized consistently across platform and legacy tenant source changes. It SHALL take required Account locks first (ascending if old/new Accounts), then the fixed platform credential advisory transaction lock, then connection/source rows, with session ownership outermost. A no-Account path SHALL never later acquire an Account lock. Discovered source identity SHALL be rechecked under locks and retried if it changed. One immutable encrypted-value/source/revision snapshot SHALL be read under locks; all DB locks SHALL be released before provider HTTP, with no independent certificate/password reads afterward. Legacy source generation changes SHALL be detected through row identity and lifecycle timestamps/version without granting foreign tenant writes. Two independent PostgreSQL/Redis processes SHALL verify attached replacement, tenant legacy replacement versus diagnostic resolution, coherent snapshots and session/rotation fencing.

#### Scenario: Legacy tenant replacement during diagnostic
- **WHEN** an authorized tenant operation replaces a legacy source while diagnostic snapshot resolution runs
- **THEN** consistent lock ordering avoids deadlock and only a coherent generation can produce a verdict, with changed generation returning connection_changed

#### Scenario: Old and new source discovery races
- **WHEN** the connection changes after old/new source Account IDs were discovered
- **THEN** identity is rechecked after ascending Account/lifecycle locks and stale discovery is retried without acquiring Accounts in reverse order

### Requirement: Bounded real multipart ingress and scoped timeouts
Certificate/configuration writes SHALL use POST multipart transport plus _method=PUT through Nuxt and Laravel while retaining the public PUT API. The proxy SHALL preserve original binary bytes, origin/CSRF/session/cookie/trusted-ingress protections and reject forbidden caller headers. Applicable certificate upload SHALL be at most 2 MiB and total multipart request at most 4 MiB, returning 413 even when PHP rejects before validation. Proxy budgets SHALL be 15s for connectivity and certificate/configuration writes and 10s for ordinary access/Admin routes; provider diagnostic budgets SHALL be 3s connect/8s total inside the existing 30s session lock. Session ownership SHALL be asserted before/after work and lost ownership SHALL prevent successful completion. Existing longer operational fiscal/export/bulk/monitoring budgets SHALL remain; a broad /serpro/* reduction SHALL not occur. Real ingress tests SHALL prove byte equality and header/limit behavior, not HTTP fakes alone. Nonsecret runtime examples SHALL document provider auth URL, diagnostic timeouts, private temp path and OpenSSL/cURL prerequisites.

#### Scenario: Real multipart save
- **WHEN** an administrator submits a PKCS12 file through actual Nuxt ingress to Laravel
- **THEN** Laravel receives identical bytes through POST override with required protections and returns only safe metadata

#### Scenario: Oversize before validation
- **WHEN** certificate or applicable request exceeds its bound, including pre-validation PHP rejection
- **THEN** the caller receives 413 without replacement or sensitive payload leakage

#### Scenario: Scoped budget and lock ownership
- **WHEN** diagnostics time out or session lock ownership is lost
- **THEN** bounded failure prevents success while unrelated operational long routes retain their budgets
