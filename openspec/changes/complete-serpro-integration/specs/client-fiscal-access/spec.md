## MODIFIED Requirements

### Requirement: Controle da procuração e-CAC
The system SHALL allow `admin` and `operador` members to register, update or remove a client's procuração e-CAC using start date, expiration date, the Serpro-issued procuração code and optional notes, SHALL keep the Serpro integration state of that procuração current, and SHALL still not require a procuração file in this version.

#### Scenario: Procuração cadastrada
- **WHEN** an authorized member provides a valid start date and an expiration date on or after it
- **THEN** the procuração metadata is associated with the client and its deadline status is returned

#### Scenario: Datas incoerentes
- **WHEN** the expiration date precedes the start date
- **THEN** the system responds with a validation error and preserves the previous procuração data

#### Scenario: Código de procuração do SERPRO registrado
- **WHEN** an authorized member provides the procuração code issued by the Serpro service for a client
- **THEN** the code is associated with the client, is returned as non-secret metadata, and the client's eligibility to be acted upon is reported as pending until the Serpro side confirms it

#### Scenario: Confirmação da procuração pelo SERPRO
- **WHEN** the Serpro service confirms that the procuração of a client is established
- **THEN** the stored integration state for that client becomes established and the client becomes eligible to be acted upon

#### Scenario: Procuração recusada pelo SERPRO
- **WHEN** the Serpro service rejects a procuração submitted for a client
- **THEN** the client is marked as not eligible, no data is requested on its behalf, and the rejection is presented as a readable reason

#### Scenario: Procuração não aplicável a pessoa física
- **WHEN** a member registers a procuração for a client that is a natural person and the Serpro service does not accept that authorization form
- **THEN** the system keeps the client's eligibility unchanged, does not present the integration as established, and does not discard the stored dates

#### Scenario: Remoção da procuração
- **WHEN** an authorized member removes a client's procuração
- **THEN** the client immediately stops being eligible to be acted upon and previously synchronized data is retained rather than deleted

#### Scenario: Procuração expira
- **WHEN** a client's procuração expiration date is earlier than the current date
- **THEN** the client is reported as not eligible, the monitoring view marks it as not covered by the integration, and its previously synchronized data is retained and labelled as out of date

#### Scenario: Segredo da procuração não exposto
- **WHEN** a member requests a client that has a procuração with a Serpro code
- **THEN** the response contains the dates, the code and the integration state but no procuração file, no stored credential and no internal storage path
