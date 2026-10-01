## ADDED Requirements

### Requirement: Certificado do escritório na Account corrente
The system SHALL give a super_admin a global-panel screen that registers, replaces and removes the office e-CNPJ of the current Account, shows the authorization term state the platform produces, and enables or disables the integration. The screen SHALL address only the current Account, whether selected in the account menu or entered through support access. The system SHALL NOT ask for a client certificate on that screen. The system SHALL NOT offer that screen, the office certificate form or the integration switch in the monitoring module. Synchronization runs and the obligations panel SHALL remain in the monitoring module.

#### Scenario: super_admin cadastra o e-CNPJ da Account corrente
- **WHEN** a super_admin opens the office-certificate screen and uploads a valid e-CNPJ for the current Account
- **THEN** the certificate is stored for that Account, the term state is shown, and no other Account is changed

#### Scenario: Membro da Account não entra na tela
- **WHEN** a member who is not a super_admin navigates to the office-certificate screen
- **THEN** they are redirected to the operational area

#### Scenario: Monitoramento não oferece o cadastro
- **WHEN** a member opens the monitoring module
- **THEN** the module shows no office-certificate form, no authorization-term screen and no integration switch, and `/monitoring/termos` is not a page of the module

### Requirement: Entrada do Painel Global no sidebar
The system SHALL show the global panel entry in the operational sidebar if and only if the current user is a super_admin.

#### Scenario: super_admin vê a entrada
- **WHEN** a super_admin loads the operational shell
- **THEN** the sidebar includes the global panel entry

#### Scenario: Admin da Account não vê a entrada
- **WHEN** a member whose role is `admin` and who is not a super_admin loads the operational shell
- **THEN** the sidebar does not include the global panel entry
