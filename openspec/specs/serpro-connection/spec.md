# serpro-connection Specification

## Purpose
Permite ao opmoni, na posição de software house, manter uma única credencial de plataforma junto ao SERPRO, apresentar cada cliente por meio do próprio termo de autorização e agir em seu nome apenas onde já exista procuração e-CAC válida, sem expor segredos ao navegador.

## Requirements

### Requirement: Credencial única de plataforma
The system SHALL store the Integra Contador consumer key and consumer secret as one platform-level credential shared by every Account, SHALL require the contracting document in that credential to match the document presented to the provider, and SHALL NOT require, hold or forward a platform credential per Account. The contracting certificate SHALL be either the office e-CNPJ already stored for account 1, without a second copy of that file, or a distinct certificate stored only when a super_admin supplies a different file.

#### Scenario: Credencial de plataforma cadastrada
- **WHEN** a super_admin saves the consumer key and consumer secret and selects the office e-CNPJ already stored for account 1
- **THEN** the credential is stored once, is writable only from the global panel, uses that certificate without duplicating it, and is available to every Account

#### Scenario: Certificado da integração diferente do da conta 1
- **WHEN** a super_admin uploads a contracting certificate that is not the office e-CNPJ of account 1
- **THEN** that file becomes the contracting certificate, the office e-CNPJ of account 1 is unchanged, and no second copy of the account 1 file is stored

#### Scenario: Credencial é rotacionável
- **WHEN** a super_admin submits a new consumer secret, leaving the certificate and the contracting document untouched
- **THEN** only the secret is replaced and the rest of the credential is preserved

#### Scenario: Escritório não informa credencial de plataforma
- **WHEN** a member of an Account configures its monitoring without providing any consumer key, consumer secret or contracting certificate
- **THEN** the configuration is accepted and the Account uses the platform credential

#### Scenario: Membro não grava credencial de plataforma
- **WHEN** a member of an Account, whatever the role, attempts to store a consumer key, consumer secret or contracting certificate
- **THEN** the system responds 403 and persists nothing

#### Scenario: Documento divergente do certificado
- **WHEN** the configured contracting document does not match the document of the stored certificate
- **THEN** the system reports the mismatch as a configuration fault before any provider call is attempted, and does not retry

### Requirement: Segredos nunca expostos pela API
The system SHALL never return the consumer secret, certificate contents, certificate password, encryption material, storage path, an access token or a signed authorization document through the API, SHALL expose only non-secret connection metadata, SHALL NOT write any of those values to logs or recorded failures, and SHALL discard the in-memory certificate password as soon as the certificate has been opened.

#### Scenario: Consulta da conexão
- **WHEN** a member requests the platform connection
- **THEN** the response contains a configured flag and non-secret certificate metadata but no secret, file content, password or storage path

#### Scenario: Segredo preservado na atualização
- **WHEN** a super_admin updates a non-secret field of the connection without resubmitting the secret
- **THEN** the stored secret is preserved and is not cleared by the omission

#### Scenario: Termo de autorização consultado
- **WHEN** a member requests the current Account's authorization term
- **THEN** the response contains its state, expiry and validity but not the signed document itself

#### Scenario: Falha sem segredo
- **WHEN** opening a certificate, signing or a provider call fails
- **THEN** the recorded failure contains no secret, no certificate content, no password and no signed document

### Requirement: Conectividade verificável sob demanda
The system SHALL provide an authorized connectivity check that exercises authentication without creating, modifying or deleting any client data, and SHALL keep a usable token rather than obtaining a new one per call.

#### Scenario: Conexão íntegra
- **WHEN** an authorized member requests the connectivity check and the platform credential authenticates successfully
- **THEN** the system reports a successful authentication with the moment it was verified

#### Scenario: Credencial ausente ou inválida
- **WHEN** the connectivity check runs without a consumer key, consumer secret or usable certificate
- **THEN** the system reports the connection as incomplete or invalid, names the missing or rejected credential element, and performs no synchronization

#### Scenario: Provedor indisponível
- **WHEN** the connectivity check cannot reach the Serpro gateway or the gateway returns a server error
- **THEN** the system reports a recoverable provider failure distinct from an invalid credential, and no client data changes

### Requirement: Token derivado e reaproveitado
The system SHALL derive the access token and the authorization token together from the platform credential in a single call, SHALL reuse them while valid, SHALL obtain a new pair when no longer valid, and SHALL NOT require any member to supply a token.

#### Scenario: Reaproveitamento do token
- **WHEN** two requests occur in a row and the derived tokens are still valid
- **THEN** only one token acquisition is performed for the two requests

#### Scenario: Token expirado
- **WHEN** a request is rejected because the derived token is no longer valid
- **THEN** the system obtains a new pair once and repeats the request, and does not loop indefinitely

#### Scenario: Credencial recusada na emissão do token
- **WHEN** the Serpro token endpoint rejects the platform credential
- **THEN** the system marks the connection as invalid, records the rejection reason and does not start a synchronization

#### Scenario: Par de tokens incompleto
- **WHEN** the token response omits the authorization token that the provider requires on gateway calls
- **THEN** the system reports the incomplete response and does not issue a request without it, even though the provider's demonstration environment does not demand it

### Requirement: Certificado do escritório armazenado com disciplina de segredo
The system SHALL accept the office's e-CNPJ certificate once per Account, SHALL validate that the supplied password opens a parseable certificate, SHALL store the certificate and its password encrypted in the database following the same encrypt-then-base64 convention already applied to other secrets, SHALL NOT keep a filesystem path for it, and SHALL NOT return certificate contents, password, storage path or signing material through the API. Storage in the database is deliberate: the container filesystem is ephemeral in production, so a file-based office certificate would be lost on every deploy and the office would be asked to authorize again. Only a member whose role in the current Account is `admin`, or a super_admin acting as `admin` in that Account per `support-access`, SHALL upload or remove that certificate. Any member of the current Account SHALL read its non-secret metadata. A client certificate SHALL NOT be accepted as the office certificate and SHALL NOT be required to issue the authorization term.

#### Scenario: Certificado do escritório válido
- **WHEN** a member with role `admin` in the current Account uploads a valid certificate
- **THEN** the encrypted copy is stored, only non-secret metadata is returned, and the office becomes able to authorize the integration

#### Scenario: Senha incorreta
- **WHEN** the supplied password cannot unlock the uploaded certificate
- **THEN** the system responds with a validation error and stores neither a record nor a file

#### Scenario: Certificado sobrevive à recriação do container
- **WHEN** the office certificate is read by a backend instance that did not store it
- **THEN** the certificate and its password open from the database, with no dependency on a file existing in that instance

#### Scenario: Nenhum caminho de certificado de escritório é mantido
- **WHEN** an office certificate is stored
- **THEN** the record carries no filesystem path, and the response exposes no such field

#### Scenario: Substituição do certificado
- **WHEN** a member with role `admin` in the current Account uploads a new valid certificate for an office that already has one
- **THEN** the new certificate becomes current, the previous encrypted copy is deleted and its non-secret metadata is retained

#### Scenario: Remoção do certificado
- **WHEN** a member with role `admin` in the current Account removes the office certificate
- **THEN** the encrypted copy is deleted, the office stops being able to authorize the integration, and previously recorded runs remain readable

#### Scenario: Material de assinatura fora de log
- **WHEN** signing or a provider call fails
- **THEN** the recorded failure contains no certificate content, no password and no signed document

#### Scenario: Operador tenta gravar o e-CNPJ
- **WHEN** a member whose role in the current Account is `operador` uploads or removes the office certificate
- **THEN** the system responds 403 and the stored certificate is unchanged

#### Scenario: Membro lê os metadados
- **WHEN** a member of the current Account requests the office certificate metadata
- **THEN** the system responds 200 with the non-secret metadata, or reports that none is stored, and the response contains no certificate content, password or storage path

### Requirement: Termo de autorização emitido uma vez por escritório
The system SHALL build, sign, submit and renew the authorization term on behalf of an office, using that office's stored certificate, SHALL treat the term as belonging to the office rather than to an individual client, and SHALL NOT require any further action from the office once its certificate is stored. The client's procuração e-CAC SHALL remain the per-client gate and SHALL NOT be replaced by a certificate of the client.

#### Scenario: Escritório sem certificado não tem termo
- **WHEN** an office has no stored certificate
- **THEN** the system reports the missing certificate as an action for the account `admin` on the current Account, and does not attempt to produce a term

#### Scenario: Emissão automática do termo
- **WHEN** an office stores a valid certificate and the recorded proof covers the current term document format, as read by the named predicate in the requirement on the proof's address below
- **THEN** the system builds the authorization document naming the office as the recipient, signs it with that certificate, submits it, and stores the resulting authorization token and its expiry

#### Scenario: Escritório não assina nada
- **WHEN** an office has a stored certificate and a valid term
- **THEN** no further action is required from the office for the term to remain in force, and the system does not ask the office to sign anything

#### Scenario: Renovação diária
- **WHEN** the authorization token has reached its expiry
- **THEN** the system obtains a new token by resubmitting the term already on file, and the office takes no action

#### Scenario: Envio repetido do mesmo termo
- **WHEN** the stored term is submitted again while the provider still considers it valid
- **THEN** the system obtains the authorization token from the provider's not-modified response without re-signing the document

#### Scenario: Validade do termo vencida
- **WHEN** the stored term's own validity has lapsed
- **THEN** the office is reported as no longer authorized, the reason names the expired term, and replacing the certificate is an account `admin` action

#### Scenario: Termo rejeitado
- **WHEN** the provider rejects the generated term
- **THEN** the system records a non-retryable rejection naming the cause, does not retry automatically, and does not present the office as authorized

### Requirement: Documento do termo segue o modelo de referência do provedor
The system SHALL build the term document following the provider's published layout, with the element named `finalidade` (no trailing space), SHALL place the contracting platform in `destinatario` with the role `contratante` and the signing office in `assinadoPor`, SHALL compute the validity period as thirty days from signing as a date in `America/Sao_Paulo`, and SHALL canonicalize the digest the way the reference model does. The thirty-day period is unconfirmed by the provider and SHALL be settled by the contract test before any proof is recorded. The system SHALL NOT emit a term until a recorded proof covers the current term format.

#### Scenario: Nome do elemento sem espaço
- **WHEN** the term document is built
- **THEN** the element is named `finalidade`, with no trailing space, in the signed document

#### Scenario: Vigência de trinta dias
- **WHEN** the term document is built
- **THEN** `vigencia` is thirty days after the signing date, written as `AAAAMMDD` in `America/Sao_Paulo`

#### Scenario: Canonicalização sem namespace
- **WHEN** the term document is signed
- **THEN** the term's root element declares no namespace and is not nested inside an element that does, so the exclusive and inclusive canonical forms coincide, and a test over the real term document asserts that coincidence

#### Scenario: Emissão bloqueada sem prova de contrato
- **WHEN** issuance of a term is requested and `term_format_proven_at` is null, or `term_format_sha256` differs from the current format digest
- **THEN** nothing is submitted, the term is reported as blocked pending that proof, and the office is not reported as authorized

#### Scenario: Emissão liberada pela prova de contrato
- **WHEN** a recorded proof covers the current format digest
- **THEN** issuance proceeds with exactly the document the proof covered

### Requirement: A prova de contrato tem endereço, e é ela que abre o gate
The system SHALL keep the proof that the provider accepts the term format only in `serpro_connections.term_format_sha256` and `serpro_connections.term_format_proven_at`. The format digest SHALL hash the canonicalized term template, with every per-office and per-term value replaced by a fixed placeholder, together with the format constants (validity period length, canonicalization algorithm, invisible-Unicode normalization rule and timezone), joined unambiguously and in a stable order, so that two offices' terms hash alike. Issuance SHALL be permitted if and only if `term_format_proven_at` is not null and `term_format_sha256` equals the current format digest. The columns SHALL NOT be mass-assignable and SHALL be written only by the operator-invoked command `serpro:record-term-proof`, which writes both together from the digest it computes and records an audit entry naming it. A boolean flag SHALL NOT replace the digest. The gate covers the template and the four constants and SHALL NOT be taken as covering the signature envelope.

#### Scenario: Mudança no modelo do documento reabre o gate
- **WHEN** the term template changes
- **THEN** the format digest no longer equals the recorded one and issuance is blocked again without any operator action

#### Scenario: Mudança nas constantes do formato reabre o gate
- **WHEN** the validity period, the canonicalization algorithm, the normalization rule or the timezone changes
- **THEN** the format digest changes and issuance is blocked again without any operator action

#### Scenario: Prova gravada pelo comando, a partir da medida
- **WHEN** an operator runs `serpro:record-term-proof` after a contract test has proven the document is accepted
- **THEN** both columns are written together, the digest comes from the value computed at that moment and never from operator input, and the recording is audited with the stored digest

### Requirement: Procuração e-CAC como condição para agir pelo cliente
The system SHALL request Serpro data for a client only when, for the service family in question, the provider has confirmed an authorized service family that is established and whose expiration date has not elapsed, SHALL treat a client without such a family as not eligible without issuing any request on its behalf, and SHALL NOT consider any date, code or note typed by a member when deciding eligibility.

#### Scenario: Cliente com procuração válida
- **WHEN** the provider has confirmed an established authorized service family for a client and its expiration date has not elapsed
- **THEN** the client is eligible for that family and the system may request data for it

#### Scenario: Cliente sem procuração
- **WHEN** the provider has never confirmed the service family for a client, has rejected it, or its expiration date is earlier than the current date
- **THEN** the client is reported as not eligible for that family, no request is issued, and it is never presented as regular

#### Scenario: Procuração de uma família não vale para outra
- **WHEN** a client holds an established authorized service family for one monitored service and none for another
- **THEN** the client is eligible only for the first family and is reported as not eligible for the second

#### Scenario: Procuração que vence em breve
- **WHEN** an established authorized service family of a client expires within thirty calendar days from the current date
- **THEN** the client remains eligible and the approaching expiration is returned alongside it

#### Scenario: Dado digitado não altera a elegibilidade
- **WHEN** a client's eligibility for a family is evaluated
- **THEN** only the provider-confirmed authorized service family is read, and no member-typed start date, expiration date or code can make the client eligible or not eligible

### Requirement: Consulta de procuração ao salvar o cliente e em rotina diária
The system SHALL refresh a company client's authorized service families from the provider in the background when the client is created or its CPF/CNPJ changes, and in a daily routine for every company client of every enabled Account, in addition to the refresh performed inside a synchronization. The system SHALL perform the refresh only when the Account is enabled, holds a valid authorization term and an office certificate; SHALL record each provider call for billing audit; SHALL skip a client whose families were verified within the last 20 hours unless its CPF/CNPJ changed; SHALL write the result under the client's own Account regardless of the worker's current tenant; and SHALL NOT make the save of the client wait for or fail because of the provider.

#### Scenario: Cliente pessoa jurídica criado
- **WHEN** an `admin` or `operador` member creates a company client in an enabled Account with a valid authorization term
- **THEN** the system responds 201 without waiting for the provider, and a background refresh records the client's authorized service families under that Account

#### Scenario: Escritório não habilitado ou sem termo
- **WHEN** a company client is created in an Account that is not enabled or has no valid authorization term
- **THEN** the client is saved, no provider call is made and no authorization row is written

#### Scenario: Cliente pessoa física
- **WHEN** a natural-person client is created or updated
- **THEN** no procuração refresh is queued and no provider call is made

#### Scenario: Rotina diária
- **WHEN** the daily routine runs
- **THEN** each company client of each enabled Account whose families were not verified within the last 20 hours is refreshed once, and clients of Accounts that are not enabled are not queried

#### Scenario: Gravação na Account do cliente
- **WHEN** a background refresh runs in a worker whose current tenant belongs to another Account or is empty
- **THEN** the authorized service families are written with the client's own Account and are not visible from any other Account

#### Scenario: Falha do provedor não desfaz o cadastro
- **WHEN** the provider fails or rejects the procuração query triggered by a save
- **THEN** the client remains saved, the previously recorded families are unchanged, and the failure record contains no token, certificate content or password

### Requirement: Habilitação da integração por escritório
The system SHALL require an Account to be explicitly enabled before any of its clients is synchronized, SHALL allow only a super_admin to enable or disable it for the current Account, SHALL let any member of the current Account read that flag, and SHALL keep already recorded runs and synchronized data when it is disabled.

#### Scenario: Escritório ainda não habilitado
- **WHEN** a synchronization is requested for an Account that is not enabled
- **THEN** the system refuses the request, reports that the office is not enabled, and creates no run

#### Scenario: Habilitação pelo administrador
- **WHEN** a super_admin enables the integration for the current Account and the platform connection is usable
- **THEN** the office becomes enabled and a synchronization can be requested

#### Scenario: Membro sem papel de administrador
- **WHEN** an `admin`, `operador` or `user` member who is not a super_admin attempts to enable or disable the integration
- **THEN** the system responds 403 and the enablement state is unchanged

#### Scenario: Escritório desabilitado
- **WHEN** a super_admin disables the integration for an Account
- **THEN** new synchronization requests for that Account are refused while previously recorded runs and synchronized data remain readable

#### Scenario: Membro lê a habilitação
- **WHEN** a member of the current Account requests the integration flag
- **THEN** the system responds 200 with the flag and no secret

### Requirement: Escrita restrita a papéis autorizados
The system SHALL permit only a super_admin to create, update or remove the platform connection, SHALL expose no write path for an authorization term to any member of an Account, and SHALL let every member of the current Account read the term. Issuing the term is the platform's, renewing it is the scheduler's, and reading it is every member's.

#### Scenario: Nenhum Membro grava o termo
- **WHEN** a member of an Account, whatever the role, attempts to store or replace an authorization term
- **THEN** no such route exists and the term is unchanged

#### Scenario: Membro com qualquer papel lê o termo
- **WHEN** a member whose role in the current Account is `user`, `operador` or `admin` requests the Account's authorization term
- **THEN** the system responds 200 with the term's state, validity and signature moment, and with no signed document, token or encrypted column

#### Scenario: Termo de outra conta
- **WHEN** a member of one Account requests the term while another Account is the current tenant
- **THEN** the system reports that the current Account's term is absent and reveals nothing about the other Account's term

#### Scenario: super_admin grava a conexão
- **WHEN** a super_admin changes a non-secret connection field
- **THEN** the change is applied and recorded

#### Scenario: Membro de Account não altera a conexão
- **WHEN** a member of an Account, whatever the role, attempts to change the platform connection
- **THEN** the system responds 403 and the connection is unchanged

### Requirement: Probes reutilizam a conexão de plataforma com gate de ambiente
The system SHALL let homologation probes obtain access through the same platform credential, token reuse and authorization envelope as production synchronization, SHALL require homologation as the default target for probes, and SHALL NOT log consumer secrets, certificate passwords, access tokens or signed authorization documents during a probe.

#### Scenario: Autenticação compartilhada
- **WHEN** a homologation probe runs with a configured platform credential
- **THEN** token acquisition follows the same reuse rules as an ordinary provider call and at most one new token pair is obtained while tokens remain valid

#### Scenario: Prova de contrato em homologação
- **WHEN** a probe runs and the Account lacks a valid authorization term
- **THEN** the probe stops with the same class of term problem used for synchronization and performs no PGDAS call

#### Scenario: Falha sem vazamento de segredo
- **WHEN** authentication, signing or a provider call fails during a probe
- **THEN** the recorded outcome contains no consumer secret, certificate content, password, token or signed document
