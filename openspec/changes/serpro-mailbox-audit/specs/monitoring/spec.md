## MODIFIED Requirements

### Requirement: Leitura de mensagem exige confirmação da ciência

The system SHALL read a mailbox message from the provider only when the member explicitly confirms that reading it registers the ciência da intimação and starts the legal deadline. The system SHALL NOT read a message as a side effect of listing or opening the monitoring screen. The read SHALL be a `POST` to `serpro/monitoring/obligations/{obligation}/clients/{client}/messages/{message}` carrying `ciencia: true` in the body, and SHALL be allowed to `admin` and `operador` members and to a super_admin in support mode, like any other `admin` act. Every refusal SHALL happen before the provider is called, because the call itself is the legal act and a refusal after it cannot undo the ciência. The system SHALL accept a message id only when it is among the messages already synchronized for that client and that obligation. The system SHALL record who read the message and when. A provider failure SHALL answer with the readable failure label and SHALL NOT expose the provider's own text. Reopening a message already read SHALL call the provider again.

#### Scenario: Leitura sem confirmação

- **WHEN** a member requests a mailbox message without `ciencia: true` in the body
- **THEN** the system responds 422 and does not call the provider

#### Scenario: Leitura com confirmação

- **WHEN** an `admin` or `operador` member confirms the ciência and requests a message synchronized for that client
- **THEN** the system reads the message from the provider, responds 200 with its subject, plain-text body, reading date, ciência date and deadline, marks the message as read in the list, and records the member who read it

#### Scenario: Listagem não lê mensagens

- **WHEN** a member opens the monitoring screen or lists the mailbox
- **THEN** the system does not read any message from the provider

#### Scenario: User não registra ciência

- **WHEN** a `user` member requests a message with `ciencia: true`
- **THEN** the system responds 403 and does not call the provider

#### Scenario: Suporte registra ciência com auditoria

- **WHEN** a super_admin in support mode confirms the ciência and requests a message
- **THEN** the system reads the message as it would for an `admin` and records the act in the support audit log

#### Scenario: Mensagem fora da caixa sincronizada

- **WHEN** the message id is not among the synchronized messages of that client for that obligation, the obligation has no mailbox, or the client belongs to another Account
- **THEN** the system responds 404 and does not call the provider

#### Scenario: Escritório sem termo ou sem e-CNPJ

- **WHEN** the Account has no valid authorization term or no stored e-CNPJ certificate
- **THEN** the system responds 409 naming what is missing and does not call the provider

#### Scenario: Falha do provedor

- **WHEN** the provider rejects the read
- **THEN** the system responds 502 with the readable failure label, the response contains no provider text, and the message stays unread in the list
