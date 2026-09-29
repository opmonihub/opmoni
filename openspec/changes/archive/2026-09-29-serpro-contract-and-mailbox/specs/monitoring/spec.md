## ADDED Requirements

### Requirement: Leitura de mensagem exige confirmação da ciência

The system SHALL read a mailbox message from the provider only when the member explicitly confirms that reading it registers the ciência da intimação and starts the legal deadline. The system SHALL NOT read a message as a side effect of listing or opening the monitoring screen.

#### Scenario: Leitura sem confirmação

- **WHEN** a member requests a mailbox message without confirming the ciência
- **THEN** the system refuses the request and does not call the provider

#### Scenario: Leitura com confirmação

- **WHEN** a member confirms the ciência and requests a mailbox message
- **THEN** the system reads the message from the provider and returns its content

#### Scenario: Listagem não lê mensagens

- **WHEN** a member opens the monitoring screen or lists the mailbox
- **THEN** the system does not read any message from the provider
