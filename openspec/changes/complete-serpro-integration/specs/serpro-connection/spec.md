## Purpose

Permite ao opmoni, na posição de software house, manter uma única credencial de plataforma junto ao SERPRO, apresentar cada cliente por meio do próprio termo de autorização e agir em seu nome apenas onde já exista procuração e-CAC válida, sem expor segredos ao navegador.

## ADDED Requirements

### Requirement: Credencial única de plataforma
The system SHALL store the Integra Contador consumer key, consumer secret and contracting e-CNPJ certificate as one platform-level credential shared by every Account, SHALL require the contracting document in that credential to match the document presented to the provider, and SHALL NOT require, hold or forward a Serpro credential per office.

#### Scenario: Credencial de plataforma cadastrada
- **WHEN** a platform operator saves the consumer key, consumer secret and contracting certificate for the platform
- **THEN** the credential is stored once, is reachable only from the platform operator's area, and is available to every Account without being duplicated per office

#### Scenario: Credencial é rotacionável
- **WHEN** the platform operator submits a new consumer secret, leaving the certificate and the contracting document untouched
- **THEN** only the secret is replaced and the rest of the credential is preserved

#### Scenario: Escritório não informa credencial própria
- **WHEN** a member of an office configures its monitoring without providing any Serpro key, secret or certificate
- **THEN** the configuration is accepted and the office uses the platform credential, because the integration is operated by the platform and not by the office

#### Scenario: Credencial de escritório é recusada
- **WHEN** a member attempts to store a Serpro consumer key, consumer secret or certificate as belonging to a single office
- **THEN** the system responds with a validation error and does not persist it

#### Scenario: Documento divergente do certificado
- **WHEN** the configured contracting document does not match the document of the stored certificate
- **THEN** the system reports the mismatch as a configuration fault before any provider call is attempted, and does not retry

### Requirement: Segredos nunca expostos pela API
The system SHALL never return the consumer secret, certificate contents, certificate password, encryption material, storage path, an access token or a signed authorization document through the API, and SHALL expose only non-secret connection metadata.

#### Scenario: Consulta da conexão
- **WHEN** an authorized member requests the platform connection
- **THEN** the response contains a configured flag and non-secret certificate metadata but no secret, file content, password or storage path

#### Scenario: Segredo preservado na atualização
- **WHEN** an authorized member updates a non-secret field of the connection without resubmitting the secret
- **THEN** the stored secret is preserved and is not cleared by the omission

#### Scenario: Termo de autorização consultado
- **WHEN** an authorized member requests a client's authorization term
- **THEN** the response contains its state, expiry and validity but not the signed document itself

#### Scenario: Cliente de outra conta
- **WHEN** a member of one Account addresses the connection of the platform
- **THEN** the response reveals no secret and no connection detail beyond the non-secret metadata permitted for that Account

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
The system SHALL accept the office's e-CNPJ certificate once per Account, SHALL validate that the supplied password opens a parseable certificate, SHALL store the certificate and its password encrypted in the database following the same encrypt-then-base64 convention already applied to other secrets, SHALL NOT keep a filesystem path for it, and SHALL NOT return certificate contents, password, storage path or signing material through the API. Storage in the database is deliberate: the container filesystem is ephemeral in production, so a file-based office certificate would be lost on every deploy and the office would be asked to authorize again.

#### Scenario: Certificado do escritório válido
- **WHEN** an authorized member uploads a valid certificate for the current Account
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
- **WHEN** an authorized member uploads a new valid certificate for an office that already has one
- **THEN** the new certificate becomes current, the previous encrypted copy is deleted and its non-secret metadata is retained

#### Scenario: Remoção do certificado
- **WHEN** an authorized member removes the office certificate
- **THEN** the encrypted copy is deleted, the office stops being able to authorize the integration, and previously recorded runs remain readable

#### Scenario: Material de assinatura fora de log
- **WHEN** signing or a provider call fails
- **THEN** the recorded failure contains no certificate content, no password and no signed document

### Requirement: Termo de autorização emitido uma vez por escritório
The system SHALL build, sign, submit and renew the authorization term on behalf of an office, using that office's stored certificate, SHALL treat the term as belonging to the office rather than to an individual client, and SHALL NOT require any further action from the office once its certificate is stored.

#### Scenario: Escritório sem certificado não tem termo
- **WHEN** an office has no stored certificate
- **THEN** the system reports the missing certificate as an action belonging to the office, and does not attempt to produce a term

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
- **THEN** the office is reported as no longer authorized, the reason names the expired term, and the office is asked to act

#### Scenario: Termo rejeitado
- **WHEN** the provider rejects the generated term
- **THEN** the system records a non-retryable rejection naming the cause, does not retry automatically, and does not present the office as authorized

### Requirement: Documento do termo segue o modelo de referência do provedor
The system SHALL build the term document following the provider's published layout, and SHALL record explicitly which of the reference model's three suspicious points were kept and which was corrected, together with what the provider's own documentation confirms and what it still does not settle. **The provider's term documentation is reachable and was read on 2026-09-28**, at `…/autenticaprocurador/padroes_tecnicos_assinatura_xml/` and `…/autenticaprocurador/servicos/envio_de_xml_assinado/`; the earlier statement in this change that it answers `500` on every plausible URL no longer holds, and the reading below supersedes it. Three of the points are now **documented rather than inferred**: the layout table names the element `finalidade` with no trailing space, so the correction of the model's `finalidade ` is confirmed by the provider and not merely tolerated; the declared `CanonicalizationMethod` and the `c14n` transform are `http://www.w3.org/TR/2001/REC-xml-c14n-20010315`, the **inclusive** form, which is what the `Reference` declares; and the published example places SERPRO — the contracting platform, not a client — in `destinatario` with the role `contratante` and the signing office in `assinadoPor`, which is the reading this system already builds. The corrected element name is the model's `finalidade `, and the trailing space **cannot survive into the signed document**: the model's `SimpleXMLElement::addChild('finalidade ')` does not throw, but it emits `<finalidade  texto="…"/>`, whose `nodeName` is `finalidade` without the space, and the `loadXML`/`saveXML` round trip the signing routine performs removes the space entirely; `DOMDocument::createElement('finalidade ')` refuses outright with a `DOMException`, and a name with a space is unreachable by XPath. The space is therefore dropped because it does not survive signing and because the provider's own layout omits it, not because it was judged unimportant; a later change that tries to restore fidelity to the model would break the document. What the documentation still does **not** settle is the **validity period**: it declares only that `vigencia` is "a data de validade deste termo de autorização, no formato AAAAMMDD", publishes no schema and states no number of days, while both of its own examples run far longer than thirty days — the layout example spans `20220614` to `20221231` and the service example `20220808` to `20221231`, that is 200 and 145 days, both ending on the same date, which is consistent with samples rather than with any rule. The `+30 days` of the reference model is therefore kept **as the only arithmetic of a period in the provider's material and as an unconfirmed value**, and changing it is a format change that re-opens the gate on its own; it SHALL be settled by the contract test before any proof is recorded, not by picking one of the two sample spans. The signature digest computed with exclusive XML canonicalization while the `Reference` declares the inclusive one is kept verbatim, because the provider's technical page names the inclusive form and the two coincide for a document that declares no namespace. The system SHALL NOT emit a term until a contract test against the provider has proven that the document is accepted, that the roles are the ones the gateway expects, and that a still-valid term resubmission answers not-modified with the token.

#### Scenario: Nome do elemento sem o espaço, porque o espaço não sobrevive à assinatura
- **WHEN** the term document is built
- **THEN** the element is named `finalidade`, with no trailing space, and the space is absent from the signed document because the parser consumes it and the signing round trip drops it

#### Scenario: Vigência preservada como o modelo a traz, e marcada como não confirmada
- **WHEN** the term document is built
- **THEN** the validity period is the reference model's thirty days, computed as a date in `America/Sao_Paulo`, and the system substitutes no other period for it, because the provider's documentation declares only the `AAAAMMDD` **format** of `vigencia`, publishes no schema and states no number of days, while its own two examples run far longer — `20220614` to `20221231`, 200 days, and `20220808` to `20221231`, 145 days — and both end on the same date, which is what hand-written samples look like and not what a rule looks like, so neither 145 nor 200 is inferable and promoting either would be choosing a sample as a rule. What is preserved is the period, not the reference model's `date()` call, which cannot run. Thirty days is therefore recorded as **unconfirmed**, chosen because it is the only period arithmetic anywhere in the provider's material **and because it is the risk-asymmetric value**: a term shorter than the provider's real maximum is likely still accepted, a term longer than it is rejected immediately, and a rejection is late and recoverable through the daily refresh while an over-long term is not. The contract test is what has to settle the value, and it has to do so **before any proof is recorded**, because the period is inside `formatDigest()` and changing it re-opens the gate by itself

#### Scenario: Canonicalização preservada como o modelo a traz
- **WHEN** the term document is signed
- **THEN** the digest is canonicalized the way the reference model canonicalizes it, and the coincidence between the exclusive and inclusive forms is asserted by a test over the real term document; the real trigger of divergence is any namespace declaration at all, used or unused, because exclusive canonicalization renders a declaration on the element that uses it while inclusive renders it where it was declared, so the guarantee is that the term's root element declares no namespace and the term is not nested inside an element that does

#### Scenario: Emissão bloqueada sem prova de contrato
- **WHEN** issuance of a term is requested and `term_format_proven_at` is null, or `term_format_sha256` differs from `SerproTermSigner::formatDigest()`
- **THEN** nothing is submitted, the term is reported as blocked pending that proof rather than as authorized, and the office is not reported as authorized

#### Scenario: Emissão liberada pela prova de contrato
- **WHEN** a contract test has proven that the provider accepts the document with the `finalidade` name without its space, the validity period **in the value the test used** and the exclusive digest canonicalization
- **THEN** issuance proceeds without changing any of them, so the document the test proved is the document that is sent; the thirty days is what the pending contract test is expected to confirm, and a test that proved a different period would leave the digest it recorded different from `SerproTermSigner::formatDigest()`, so the gate would stay closed rather than open on a document the test did not prove

### Requirement: A prova de contrato tem endereço, e é ela que abre o gate
The fact that a contract test proved the provider accepts the term document SHALL live in exactly one named place, so that the gate has a predicate two implementers cannot read differently: the platform connection row, in `serpro_connections.term_format_sha256` and `serpro_connections.term_format_proven_at`. The digest identifies the document **format**, not an instance, and its input is **the canonicalized term template with every per-office and per-term value replaced by a fixed plain-ASCII placeholder, concatenated with the format constants**: the validity period length, the canonicalization algorithm, the invisible-Unicode normalization rule, and the timezone the term's dates are written in. `SerproTermSigner::formatDigest()` SHALL hash exactly that, in a stable order, with the template and the constants joined by a separator or a length prefix that cannot occur in either part, so that a template ending in the same bytes a constant begins with is not ambiguous, and so that two offices' terms hash alike. The constants are inside the hashed input because the template alone cannot see them: the period never appears in the document, since the model writes only the computed date and that date is a per-term placeholder, so moving 30 days to 60 leaves the template bytes identical; and removing the normalization step changes no template byte either; and the timezone does not appear in the document at all, though it decides which calendar day `dataAssinatura` falls on near midnight. Three of the four values this gate covers are therefore invisible to a template-only digest, which is why a digest built from the template alone would have kept issuance open on a document nobody tested — and a recorded proof that survives the edit it should have invalidated reads as a guarantee, which is worse than having no gate. Issuance SHALL be permitted if and only if `term_format_proven_at` is not null **and** `term_format_sha256` equals `SerproTermSigner::formatDigest()`. The columns SHALL be written by no request, no job and no scheduler, and SHALL NOT be `Fillable` on the model; the single sanctioned writer is the operator-invoked command `serpro:record-term-proof`, which is the declared exception to that rule, writes both columns together, and records an audit entry naming the digest it stored, so that the proof is evidence with an author rather than a flag somebody flipped. A boolean flag SHALL NOT be used in its place, because a flag cannot be invalidated by a change to the format and would keep authorizing a document nobody tested. **The gate's scope is the term template and the four constants, and it does not cover the signature envelope.** The provider validates the signed document, so a change to the transform list, to the `Reference` URI, to the signature algorithm or to the signing routine itself changes the bytes the provider sees while leaving `formatDigest()` untouched, and such a change re-opens the gate for nobody. A reader SHALL NOT take this gate as covering the envelope; the envelope is covered by the provenance tests of `SerproSigner`, which assert its structure and its signature against the certificate's public key and are a different kind of evidence from a contract test.

#### Scenario: Mudança no modelo do documento reabre o gate
- **WHEN** the term template changes, so that `SerproTermSigner::formatDigest()` no longer equals the recorded `term_format_sha256`
- **THEN** issuance is blocked again without any operator action, and the recorded proof no longer authorizes the new document

#### Scenario: Mudança nas constantes do formato reabre o gate
- **WHEN** the validity period, the canonicalization algorithm, the normalization rule or the timezone changes
- **THEN** `SerproTermSigner::formatDigest()` changes with it, and issuance is blocked again without any operator action, because those constants are part of the hashed input and not only of the document

#### Scenario: Prova gravada pelo comando, a partir da medida e não da afirmação
- **WHEN** an operator runs `serpro:record-term-proof` after a contract test has proven that the provider accepts the document
- **THEN** both columns are written together, `term_format_sha256` from the value `SerproTermSigner::formatDigest()` computes at that moment and never from a value the operator supplies, `term_format_proven_at` from that moment, and the recording is auditable with the digest it stored; a proof that recorded an operator's assertion instead of a measurement would prove nothing

### Requirement: Procuração e-CAC como condição para agir pelo cliente
The system SHALL request Serpro data for a client only when that client has, for the service family in question, a procuração currently valid, and SHALL treat a client without a valid procuração as not eligible without issuing any request on its behalf.

#### Scenario: Cliente com procuração válida
- **WHEN** a client has a procuração for a service family whose start date has passed and whose expiration date has not elapsed
- **THEN** the client is eligible for that family and the system may request data for it

#### Scenario: Cliente sem procuração
- **WHEN** a client has no procuração registered for a service family, or its expiration date is earlier than the current date
- **THEN** the client is reported as not eligible for that family, no request is issued, and it is never presented as regular

#### Scenario: Procuração de uma família não vale para outra
- **WHEN** a client holds a valid procuração for one monitored service family and none for another
- **THEN** the client is eligible only for the first family and is reported as not eligible for the second

#### Scenario: Procuração que vence em breve
- **WHEN** a client has a procuração expiring within thirty calendar days from the current date
- **THEN** the client remains eligible and the approaching expiration is returned alongside it

### Requirement: Habilitação da integração por escritório
The system SHALL require an Account to be explicitly enabled before any of its clients is synchronized, SHALL allow only `admin` members to enable or disable it, and SHALL keep already recorded runs and synchronized data when it is disabled.

#### Scenario: Escritório ainda não habilitado
- **WHEN** a synchronization is requested for an Account that is not enabled
- **THEN** the system refuses the request, reports that the office is not enabled, and creates no run

#### Scenario: Habilitação pelo administrador
- **WHEN** an `admin` member enables the integration for the current Account and the platform connection is usable
- **THEN** the office becomes enabled and a synchronization can be requested

#### Scenario: Membro sem papel de administrador
- **WHEN** an `operador` or `user` member attempts to enable or disable the integration
- **THEN** the system responds 403 and the enablement state is unchanged

#### Scenario: Escritório desabilitado
- **WHEN** an `admin` member disables the integration for an Account
- **THEN** new synchronization requests for that Account are refused while previously recorded runs and synchronized data remain readable

### Requirement: Escrita restrita a papéis autorizados
The system SHALL permit only a platform operator to create, update or remove the platform connection, SHALL expose **no write path for an authorization term to any member of an Account**, and SHALL let every member of the current Account read the term without modifying it. The platform connection is a single credential owned by the platform rather than by any office, so writing it is reserved to the platform operator and is not exposed to the accounts that use it. The authorization term **supersedes** the earlier clause of this requirement that let `admin` and `operador` store or replace it, and the amendment is deliberate rather than an omission: the term is signed with the office's e-CNPJ, which no member holds; the design states that issuance is automatic and that the office is never asked to sign anything; and no task in this change submits a term on a member's behalf. A term that a member could write would have to be signed by someone the product does not have the material of, so a write route would exist only to accept a document the platform itself could not have produced. Issuing is the platform's, renewing is the scheduler's, and reading is every member's.

#### Scenario: Nenhum Membro grava o termo
- **WHEN** a member of an Account, whatever that member's role — `admin`, `operador` or `user` — attempts to store or replace an authorization term
- **THEN** the system exposes no such route, the request does not reach a write, and the term is unchanged; issuance and renewal are the platform's own and are never driven by a request

#### Scenario: Membro com qualquer papel lê o termo
- **WHEN** a member whose role in the current Account is `user`, `operador` or `admin` requests the Account's authorization term
- **THEN** the system responds with the term's state, validity and signature moment, and with no signed document, token or encrypted column

#### Scenario: Termo de outra conta
- **WHEN** a member of one Account requests the term while another Account is the current tenant
- **THEN** the system reports that the term of the current Account is absent, and reveals nothing about the other office's term

#### Scenario: Operador da plataforma grava a conexão
- **WHEN** a platform operator changes a non-secret connection field
- **THEN** the change is applied and recorded

#### Scenario: Membro de Account não altera a conexão
- **WHEN** a member of an Account, whatever that member's role, attempts to change the platform connection
- **THEN** the system responds 403 and the connection is unchanged, because the credential is the platform's and is not that office's to change

### Requirement: Segredos e senha do certificado fora de log
The system SHALL NOT write the consumer secret, the certificate password, certificate contents or a signed authorization document to logs, and SHALL zero the in-memory certificate password as soon as the certificate has been opened.

#### Scenario: Falha durante a leitura do certificado
- **WHEN** opening the certificate fails or the connection cannot be established
- **THEN** the recorded failure contains no secret, no certificate content and no signed document
