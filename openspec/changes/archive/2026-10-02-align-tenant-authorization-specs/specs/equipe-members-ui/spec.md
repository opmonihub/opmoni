## MODIFIED Requirements

### Requirement: Configurações oferece o certificado do escritório
The system SHALL offer, under Settings navigation (toolbar and sidebar settings children), an office-certificate entry for members who can manage the office e-CNPJ (account `admin`, or super_admin acting as `admin` in the current Account). The screen SHALL register, replace and remove the office e-CNPJ of the current Account and SHALL ask only for the file and the password that opens it. The screen SHALL NOT show the authorization term and SHALL NOT show the integration switch. The screen SHALL address only the current Account. The system SHALL NOT ask for a client certificate on that screen. Members with role `operador` or `user` SHALL NOT see the entry and SHALL be redirected away from the screen.

#### Scenario: Admin cadastra o e-CNPJ da Account corrente
- **WHEN** a member with role `admin` opens Settings and uploads a valid e-CNPJ on the office-certificate screen
- **THEN** the certificate is stored for the current Account, the screen shows no term and no integration switch, and no other Account is changed

#### Scenario: Operador não vê a aba
- **WHEN** a member whose role is `operador` opens Settings
- **THEN** the settings navigation has no office-certificate entry

#### Scenario: Membro sem permissão abre o endereço direto
- **WHEN** a member with role `operador` or `user` navigates to the office-certificate screen
- **THEN** they are redirected to the operational area
