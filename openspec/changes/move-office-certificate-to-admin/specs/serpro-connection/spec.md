## MODIFIED Requirements

### Requirement: Certificado do escritório armazenado com disciplina de segredo
The system SHALL accept the office's e-CNPJ certificate once per Account, SHALL validate that the supplied password opens a parseable certificate, SHALL store the certificate and its password encrypted in the database following the same encrypt-then-base64 convention already applied to other secrets, SHALL NOT keep a filesystem path for it, and SHALL NOT return certificate contents, password, storage path or signing material through the API. Storage in the database is deliberate: the container filesystem is ephemeral in production, so a file-based office certificate would be lost on every deploy and the office would be asked to authorize again. Only a super_admin SHALL upload or remove that certificate for the current Account. Any member of the current Account SHALL read its non-secret metadata. A client certificate SHALL NOT be accepted as the office certificate and SHALL NOT be required to issue the authorization term.

#### Scenario: Certificado do escritório válido
- **WHEN** a super_admin uploads a valid certificate for the current Account
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
- **WHEN** a super_admin uploads a new valid certificate for an office that already has one
- **THEN** the new certificate becomes current, the previous encrypted copy is deleted and its non-secret metadata is retained

#### Scenario: Remoção do certificado
- **WHEN** a super_admin removes the office certificate
- **THEN** the encrypted copy is deleted, the office stops being able to authorize the integration, and previously recorded runs remain readable

#### Scenario: Material de assinatura fora de log
- **WHEN** signing or a provider call fails
- **THEN** the recorded failure contains no certificate content, no password and no signed document

#### Scenario: Admin da Account tenta gravar o e-CNPJ
- **WHEN** a member whose role in the current Account is `admin` or `operador`, and who is not a super_admin, uploads or removes the office certificate
- **THEN** the system responds 403 and the stored certificate is unchanged

#### Scenario: Membro lê os metadados
- **WHEN** a member of the current Account requests the office certificate metadata
- **THEN** the system responds 200 with the non-secret metadata, or reports that none is stored, and the response contains no certificate content, password or storage path

### Requirement: Termo de autorização emitido uma vez por escritório
The system SHALL build, sign, submit and renew the authorization term on behalf of an office, using that office's stored certificate, SHALL treat the term as belonging to the office rather than to an individual client, and SHALL NOT require any further action from the office once its certificate is stored. The client's procuração e-CAC SHALL remain the per-client gate and SHALL NOT be replaced by a certificate of the client.

#### Scenario: Escritório sem certificado não tem termo
- **WHEN** an office has no stored certificate
- **THEN** the system reports the missing certificate as an action for the super_admin on the current Account, and does not attempt to produce a term

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
- **THEN** the office is reported as no longer authorized, the reason names the expired term, and replacing the certificate is a super_admin action

#### Scenario: Termo rejeitado
- **WHEN** the provider rejects the generated term
- **THEN** the system records a non-retryable rejection naming the cause, does not retry automatically, and does not present the office as authorized

### Requirement: Habilitação da integração por escritório
The system SHALL require an Account to be explicitly enabled before any of its clients is synchronized, SHALL allow only a super_admin to enable or disable it for the current Account, SHALL let any member of the current Account read that flag, and SHALL keep already recorded runs and synchronized data when it is disabled.

#### Scenario: Escritório ainda não habilitado
- **WHEN** a synchronization is requested for an Account that is not enabled
- **THEN** the system refuses the request, reports that the office is not enabled, and creates no run

#### Scenario: Habilitação pelo super_admin
- **WHEN** a super_admin enables the integration for the current Account and the platform connection is usable
- **THEN** the office becomes enabled and a synchronization can be requested

#### Scenario: Membro da Account tenta habilitar
- **WHEN** an `admin`, `operador` or `user` member who is not a super_admin attempts to enable or disable the integration
- **THEN** the system responds 403 and the enablement state is unchanged

#### Scenario: Escritório desabilitado
- **WHEN** a super_admin disables the integration for an Account
- **THEN** new synchronization requests for that Account are refused while previously recorded runs and synchronized data remain readable

#### Scenario: Membro lê a habilitação
- **WHEN** a member of the current Account requests the integration flag
- **THEN** the system responds 200 with the flag and no secret
