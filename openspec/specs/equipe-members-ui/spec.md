# equipe-members-ui Specification

## Purpose
Define a superfície Equipe (Membros e Departamentos), ausência de Members em Configurações, e ícones de navegação coerentes com o papel de cada seção.

## Requirements

### Requirement: Equipe Membros usa layout de lista com dados do diretório
The system SHALL present Equipe → Membros as a searchable member list (card header with invite affordance for authorized admins, search field, and a flat list of members) populated from the Account member directory; each row MUST show the member name, role, and department badges (or an explicit empty-department label). The UI MUST be in Portuguese (Brazil). The list MUST NOT use mock or template fixture members.

#### Scenario: Membro autenticado vê a equipe real
- **WHEN** any tenant member opens Equipe → Membros
- **THEN** the page lists the current Account members from the directory API with name, role and departments, without fixture/demo people

#### Scenario: Busca filtra a lista
- **WHEN** the user types a search term matching a member name or role
- **THEN** only matching members remain visible in the list

#### Scenario: Filtro por departamento
- **WHEN** the user selects a department filter
- **THEN** only members linked to that department remain visible

### Requirement: Gestão de membros na Equipe só para admin
The system SHALL show invite, role-change and remove controls on Equipe → Membros only when the current actor can manage members (`admin` or super_admin in support as admin). Non-admin members MUST see a read-only role indicator and MUST NOT see invite or remove actions. Successful admin actions MUST call the existing members management endpoints (create, update role, destroy).

#### Scenario: Operador não gerencia
- **WHEN** an `operador` views Equipe → Membros
- **THEN** the invite button and destructive/role-edit controls are hidden and the roles appear read-only

#### Scenario: Admin convida
- **WHEN** an `admin` submits a valid invite form from Equipe → Membros
- **THEN** the system creates the membership via the members API and the new member appears in the list after refresh

#### Scenario: Admin altera papel
- **WHEN** an `admin` changes a member role to `admin`, `operador` or `user` from the list
- **THEN** the system persists the role via the members update endpoint

#### Scenario: Admin remove membro
- **WHEN** an `admin` confirms removal of a member from the list
- **THEN** the system detaches the membership via the members destroy endpoint and the member leaves the list

### Requirement: Configurações não oferece aba Members
The system SHALL NOT expose a Members item under Settings navigation (toolbar or sidebar settings children). Member directory and member management for the tenant SHALL live under Equipe only.

#### Scenario: Nav Settings sem Members
- **WHEN** a user opens Settings
- **THEN** the settings navigation has no Members entry and `/settings/members` is not a product surface

### Requirement: Equipe Departamentos usa lista densa Settings-like
The system SHALL present Equipe → Departamentos as a searchable flat list (no alphabetical letter groups) with a card header and create affordance for actors who can manage departments; each row MUST show department color and name, member count, linked member avatars (or an empty-members label), and edit/delete actions only for authorized managers. Create/edit and delete MUST keep using the existing department modals and API.

#### Scenario: Lista sem agrupamento A-Z
- **WHEN** any tenant member opens Equipe → Departamentos with several departments
- **THEN** departments appear in a single searchable list ordered by name without letter section headers

#### Scenario: Operador cria departamento
- **WHEN** an `operador` creates a department from the Equipe → Departamentos card action
- **THEN** the department is persisted via the departments API and appears in the list after refresh

#### Scenario: User só lê
- **WHEN** a `user` member opens Equipe → Departamentos
- **THEN** create/edit/delete controls are hidden and the list remains readable

### Requirement: Ícones da sidebar distinguem o papel de cada seção
The system SHALL use distinct Lucide icons in the main sidebar so Clientes is not represented as people (reserved for Equipe), Monitoramento signals continuous status, Work signals operational execution, and Help signals support. Child nav icons under Equipe and Work MUST use icon names that resolve in the project's Lucide set.

#### Scenario: Clientes e Equipe não compartilham metáfora de pessoas
- **WHEN** a user views the main sidebar
- **THEN** Clientes uses a building-style icon and Equipe uses a people-style icon

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
