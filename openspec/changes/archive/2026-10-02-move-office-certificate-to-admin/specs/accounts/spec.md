## MODIFIED Requirements

### Requirement: Níveis de membro por conta
The system SHALL support exactly three membership roles per account: `admin`, `operador` and `user`, and every other spec SHALL state only its exceptions to the matrix below.

- `admin` SHALL read and write every tenant resource, including members and the subscription, and SHALL NOT write the office e-CNPJ or the SERPRO enablement of the account, which respond 403.
- `operador` SHALL read and write every tenant resource except members, the subscription, the office e-CNPJ and the SERPRO enablement, which respond 403.
- `user` SHALL read every tenant resource and SHALL write only its own saved client filters; every other write SHALL respond 403.

#### Scenario: Operador tenta gerenciar membros
- **WHEN** a member with role `operador` requests member management in their account
- **THEN** the system responds 403

#### Scenario: Admin gerencia membros
- **WHEN** a member with role `admin` invites or removes a member of their account
- **THEN** the membership is created or removed

#### Scenario: Operador escreve recurso operacional
- **WHEN** an `operador` creates, updates or deletes a client, department, template, process or task of the current account
- **THEN** the write is applied

#### Scenario: User lê recurso do tenant
- **WHEN** a `user` reads any tenant resource
- **THEN** the system responds 200

#### Scenario: User tenta escrever
- **WHEN** a `user` attempts any write other than its own saved client filters
- **THEN** the system responds 403 and nothing changes

#### Scenario: User salva o próprio filtro
- **WHEN** a `user` creates or deletes one of its own saved client filters
- **THEN** the write is applied

#### Scenario: Admin da Account tenta gravar a integração
- **WHEN** a member with role `admin` who is not a super_admin uploads the office e-CNPJ or changes the SERPRO enablement
- **THEN** the system responds 403 and nothing changes
