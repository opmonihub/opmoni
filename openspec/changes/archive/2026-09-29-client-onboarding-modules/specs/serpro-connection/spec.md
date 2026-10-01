## MODIFIED Requirements

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

## ADDED Requirements

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
