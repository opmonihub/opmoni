# support-access Specification

## Purpose
Permite ao super_admin operar dentro de um escritório para suporte e manutenção sem se passar por outro usuário, com trilha de auditoria e indicação visível.

## Requirements

### Requirement: Entrada e saída de suporte
The system SHALL allow a super_admin to enter any account for support and to exit back to their own account; entering sets the support account as current, exiting restores the previous one.

#### Scenario: Entrada em conta
- **WHEN** a super_admin enters account B for support
- **THEN** subsequent requests operate in the context of account B while the acting identity remains the super_admin

#### Scenario: Saída
- **WHEN** the super_admin exits support mode
- **THEN** the current account returns to their own account

### Requirement: Poder de admin com auditoria simples
The system SHALL grant the super_admin in support mode exactly the powers of an account `admin`, with no exception, including acts with legal or cost effect at the provider such as registering the ciência da intimação. The system SHALL record in an append-only log, with acting user, account, action, details and IP, every enter and exit, every tenant write (create, update, delete) and every act with effect at the provider (starting a synchronization, enabling or disabling the integration, reading a mailbox message). Other specs SHALL NOT restate this rule.

#### Scenario: Escrita em suporte
- **WHEN** a super_admin in support mode updates a client of account B
- **THEN** the update is applied and an audit entry records who, where, what and when

#### Scenario: Ato com efeito no provedor em suporte
- **WHEN** a super_admin in support mode starts a synchronization or reads a mailbox message with ciência
- **THEN** the act is performed as it would be for an `admin` and an audit entry records it

#### Scenario: Membro fora do suporte não gera entrada
- **WHEN** a regular member, or a super_admin inside an account where they hold a membership, performs a write
- **THEN** no support audit entry is recorded

#### Scenario: Log imutável
- **WHEN** any client attempts to modify or delete audit entries through the application
- **THEN** no such operation exists and the entries remain unchanged

### Requirement: Indicação visível de suporte
The system SHALL display a persistent "support access" indicator naming the account while a super_admin operates outside their own account, with an option to exit.

#### Scenario: Banner de suporte
- **WHEN** a super_admin in support mode loads any page
- **THEN** the indicator names the support account and offers an exit action
