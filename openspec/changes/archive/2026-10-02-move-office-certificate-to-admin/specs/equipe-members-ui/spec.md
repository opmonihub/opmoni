## ADDED Requirements

### Requirement: Configurações oferece o certificado do escritório
The system SHALL offer, under Settings navigation (toolbar and sidebar settings children), an office-certificate entry for a super_admin only. The screen SHALL register, replace and remove the office e-CNPJ of the current Account and SHALL ask only for the file and the password that opens it. The screen SHALL NOT show the authorization term and SHALL NOT show the integration switch. The screen SHALL address only the current Account. The system SHALL NOT ask for a client certificate on that screen. A member who is not a super_admin SHALL NOT see the entry and SHALL be redirected away from the screen.

#### Scenario: super_admin cadastra o e-CNPJ da Account corrente
- **WHEN** a super_admin opens Settings and uploads a valid e-CNPJ on the office-certificate screen
- **THEN** the certificate is stored for the current Account, the screen shows no term and no integration switch, and no other Account is changed

#### Scenario: Admin da Account não vê a aba
- **WHEN** a member whose role is `admin` and who is not a super_admin opens Settings
- **THEN** the settings navigation has no office-certificate entry

#### Scenario: Membro abre o endereço direto
- **WHEN** a member who is not a super_admin navigates to the office-certificate screen
- **THEN** they are redirected to the operational area
