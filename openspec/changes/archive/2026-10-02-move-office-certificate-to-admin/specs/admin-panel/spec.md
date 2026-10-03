## ADDED Requirements

### Requirement: Painel Global não cadastra o e-CNPJ do escritório
The system SHALL NOT offer the office e-CNPJ screen in the global panel. The platform credential screen SHALL remain there. The system SHALL NOT offer the office certificate form, the authorization-term screen or the integration switch in the monitoring module. Synchronization runs and the obligations panel SHALL remain in the monitoring module.

#### Scenario: Abas do Painel Global sem o certificado do escritório
- **WHEN** a super_admin opens the global panel
- **THEN** the navigation has no office-certificate entry and `/admin/certificado` is not a page of the panel

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
