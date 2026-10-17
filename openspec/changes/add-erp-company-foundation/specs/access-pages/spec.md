## MODIFIED Requirements

### Requirement: Protected navigation resolves access before rendering
Unauthenticated visitors to protected pages SHALL be redirected to `/onboarding` when initial registration is available, otherwise to `/login`, without rendering protected content first. Failure to check registration availability SHALL fall back to login. The backend SHALL resolve the default active membership for an authenticated session without selection before rendering the Account workspace; navigation SHALL never require an initial selection page. Office navigation without an active office membership or with a suspended selected Account SHALL reach `/acesso-indisponivel`; company-only identities with authorized company membership SHALL enter `/erp` under its independent company guard; Super Admin platform pages SHALL remain accessible without tenant selection. A normal user visiting `/admin/contas` SHALL receive an access-denied page rather than platform data.

#### Scenario: First visit to an empty installation
- **WHEN** a visitor opens a protected page while bootstrap is available
- **THEN** the first rendered destination is onboarding and no protected content is exposed

### Requirement: Login presents usable and truthful controls
`/login` SHALL display email, password, and Entrar, with field validation and a disabled loading submit state. It SHALL show "Email ou senha inválidos" for unknown email or incorrect password, distinguish rate limiting and service unavailability, retain the form and email after failure, and allow retry. After credentials succeed, the backend-selected default active Account SHALL enter `/` directly regardless of membership count; when no active office exists, an authorized direct company membership SHALL enter `/erp`; when neither domain has an authorized active membership the destination SHALL be `/acesso-indisponivel`. The page SHALL offer no remember-me, social login, or automatic password-recovery controls in this release, and SHALL offer onboarding only when registration is available.

#### Scenario: Service unavailable during login
- **WHEN** a valid form cannot be submitted because the service is unavailable
- **THEN** the page explains the temporary failure and allows retry without labeling it an incorrect password

### Requirement: Authenticated shell has an Account-aware switcher
The shell SHALL show the user's current Account name and role, an Account switcher listing their memberships, and logout. Optional office switching SHALL use AccountMenu's team-style combobox/select after automatic entry. There SHALL be no initial profile-card page. The current Account SHALL be marked; suspended choices SHALL be disabled. A successful switch SHALL reload into `/` after discarding previous tenant state; failure SHALL show an actionable error and retain the previous Account. The authenticated `/` SHALL open the current Account workspace shell directly, without a dedicated initial identity/profile or Account-summary page or cards. AccountMenu SHALL replace the existing TeamsMenu component and all its consumers, including unavailable access. The same sidebar context control SHALL present authorized Offices and Companies as distinct groups; company selection SHALL use the company-switch API and navigate to `/erp` after clearing stale protected state. Office selection SHALL retain the existing behavior. Routine Account selection SHALL occur only through this menu at the top of the dashboard sidebar; the real user menu and logout SHALL be available at its footer. The workspace SHALL restore the original Nuxt UI dashboard and its existing demonstration widgets/routes, as explicitly approved by the user. Dashboard demonstration data SHALL be labeled as such and SHALL NOT be represented as real Account business data. This exception SHALL NOT introduce product business APIs or weaken authentication, active membership, state clearing, or platform authorization.

#### Scenario: Direct workspace entry without summary cards
- **WHEN** password login succeeds for a user with one or multiple active Accounts
- **THEN** `/` renders the selected Account workspace shell with AccountMenu and no initial profile or Account-summary cards

#### Scenario: Multiple memberships in the Account menu
- **WHEN** a user opens AccountMenu after entering the workspace
- **THEN** it lists only their memberships, indicates the selected Account, disables suspended Accounts, and permits switching to any other active member Account without another login

#### Scenario: Original demonstration dashboard is restored
- **WHEN** the user enters `/` after successful login
- **THEN** the original dashboard widgets render with a visible demonstration-data label and the sidebar AccountMenu, without an intermediate profile, Account-summary, or empty header-only page

#### Scenario: Failed Account switch
- **WHEN** the switch request is denied or fails
- **THEN** the current Account and displayed data remain unchanged and the error is visible
