> Status: planning reconciled with the approved Nuxt frontend and Laravel backend architecture (D006). Product implementation and detailed page acceptance remain tracked in `tasks.md`; this status does not assert completed authentication or Account isolation.

## Purpose

Provide clear Portuguese access pages for accounting-office operators and platform administrators, distinguishing first installation, daily login, owner activation, and unavailable Account access.

## ADDED Requirements

### Requirement: Protected navigation resolves access before rendering
Unauthenticated visitors to protected pages SHALL be redirected to `/onboarding` when initial registration is available, otherwise to `/login`, without rendering protected content first. Failure to check registration availability SHALL fall back to login. The backend SHALL resolve the default active membership for an authenticated session without selection before rendering the Account workspace; navigation SHALL never require an initial selection page. A user with no active memberships or a suspended selected Account SHALL reach `/acesso-indisponivel`; Super Admin platform pages SHALL remain accessible without tenant selection. A normal user visiting `/admin/contas` SHALL receive an access-denied page rather than platform data.

#### Scenario: First visit to an empty installation
- **WHEN** a visitor opens a protected page while bootstrap is available
- **THEN** the first rendered destination is onboarding and no protected content is exposed

### Requirement: Login presents usable and truthful controls
`/login` SHALL display email, password, and Entrar, with field validation and a disabled loading submit state. It SHALL show "Email ou senha inválidos" for unknown email or incorrect password, distinguish rate limiting and service unavailability, retain the form and email after failure, and allow retry. After credentials succeed, the backend-selected default active Account SHALL enter `/` directly regardless of membership count; no active membership SHALL open `/acesso-indisponivel`. The page SHALL offer no remember-me, social login, or automatic password-recovery controls in this release, and SHALL offer onboarding only when registration is available.

#### Scenario: Service unavailable during login
- **WHEN** a valid form cannot be submitted because the service is unavailable
- **THEN** the page explains the temporary failure and allows retry without labeling it an incorrect password

### Requirement: Login enters the default office directly
Users SHALL enter the backend-selected default active Account without choosing a profile, even with multiple active memberships. Optional switching SHALL use AccountMenu after login. The obsolete `/selecionar-escritorio` URL SHALL redirect through the normal authenticated navigation rules rather than render profile cards.

#### Scenario: Multiple offices after valid credentials
- **WHEN** login succeeds with multiple active member Accounts
- **THEN** the interface enters the default office directly and offers optional AccountMenu switching

#### Scenario: Reload after manual switching
- **WHEN** a user reloads after a successful AccountMenu switch
- **THEN** the selected office remains active for that session, while a new password login returns to the default

#### Scenario: Selected office becomes suspended
- **WHEN** the selected office becomes suspended
- **THEN** unavailable access explains the suspension and offers AccountMenu when another active membership exists; failed switching preserves the prior context

### Requirement: Onboarding represents initial installation only
`/onboarding` SHALL show three steps: Acesso with name, email and password; Escritório with office name and team size; and Revisão with the non-secret values. Voltar and Continuar SHALL preserve entered values; Concluir SHALL submit once while loading. Passwords SHALL never appear in the review summary. Successful bootstrap SHALL open the authenticated shell; a concurrent initialization SHALL explain that setup is already complete and offer login. The page SHALL redirect to login if bootstrap is unavailable and SHALL not claim terms acceptance without supplied terms and a server contract.

#### Scenario: Review initial setup
- **WHEN** valid access and office information have been entered
- **THEN** the review shows name, email, office and team size, with no password displayed

### Requirement: First access separates invalid links from service failure
`/primeiro-acesso` SHALL validate the token and email, show a loading state during validation, and display the owner and office names only for valid activation. It SHALL request password and confirmation and protect against duplicate submits. Invalid/expired links SHALL explain that a new link is needed; network or server failures SHALL have a retry action instead of being reported as invalid links. The page SHALL not load third-party analytics or assets that receive the activation URL and SHALL use a no-referrer policy.

#### Scenario: Link validation fails temporarily
- **WHEN** validation cannot complete because of network or server failure
- **THEN** the page shows a retryable validation failure and does not state that the link is expired

### Requirement: Account-unavailable page offers a way forward
`/acesso-indisponivel` SHALL explain whether no active membership is available or the selected Account is suspended, without displaying protected operational data. It SHALL offer another active membership when available, logout, and platform Account administration only for Super Admin. It SHALL not redirect in a loop between itself and the authenticated home.

#### Scenario: All own Accounts are suspended
- **WHEN** an authenticated member has no active Account to select
- **THEN** the page explains the blocked access and still allows logout

### Requirement: Authenticated shell has an Account-aware switcher
The shell SHALL show the user's current Account name and role, an Account switcher listing their memberships, and logout. Optional office switching SHALL use AccountMenu's team-style combobox/select after automatic entry. There SHALL be no initial profile-card page. The current Account SHALL be marked; suspended choices SHALL be disabled. A successful switch SHALL reload into `/` after discarding previous tenant state; failure SHALL show an actionable error and retain the previous Account. The authenticated `/` SHALL open the current Account workspace shell directly, without a dedicated initial identity/profile or Account-summary page or cards. AccountMenu SHALL replace the existing TeamsMenu component and all its consumers, including unavailable access. Routine Account selection SHALL occur only through this menu at the top of the dashboard sidebar; the real user menu and logout SHALL be available at its footer. The workspace SHALL restore the original Nuxt UI dashboard and its existing demonstration widgets/routes, as explicitly approved by the user. Dashboard demonstration data SHALL be labeled as such and SHALL NOT be represented as real Account business data. This exception SHALL NOT introduce product business APIs or weaken authentication, active membership, state clearing, or platform authorization.

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

### Requirement: Platform Account page supports only foundational operations
`/admin/contas` SHALL be visible only to Super Admin and SHALL provide name/ID search, active/suspended filtering, pagination, creation, rename, suspension/reactivation, and owner-link reissue. The creation modal SHALL request office name, owner name, and owner email, with field errors and a loading state. A newly issued activation URL SHALL be shown in a temporary copy action; list refresh or modal reopening SHALL not reveal it again. Suspension SHALL require confirmation. The page SHALL distinguish loading, failed-load with retry, empty installation, and empty filtered results. It SHALL expose no plans, billing, support entry, password assignment, or operational Account data.

#### Scenario: New office requires first access
- **WHEN** a Super Admin creates a valid office
- **THEN** the page offers the newly generated owner link for copying and refreshes the Account list

#### Scenario: No results match the filter
- **WHEN** a successful filtered Account query returns no records
- **THEN** the page offers clearing the filters rather than showing a service error

### Requirement: Server-rendered access preserves the requesting session
Access pages and protected navigation SHALL use relative `/api/v1/*` requests. During server rendering, identity checks SHALL reflect only the requesting browser session, never a session from another visitor. Read-only navigation SHALL NOT replace an existing authenticated cookie with a newly created anonymous session. Any server-rendered flow intentionally establishing or rotating a session SHALL deliver the corresponding session cookies to the browser. Internal backend addresses and session credentials SHALL NOT appear in public runtime configuration or application state serialized for the client. Protected server-rendered content SHALL NOT be cached publicly.

#### Scenario: Authenticated visitor reloads a protected page
- **WHEN** a browser with a valid authenticated session directly loads or reloads a protected page
- **THEN** the first rendered response uses that browser's authorized identity and Account, without replacing its session or briefly rendering another identity

#### Scenario: Parallel visitors render protected pages
- **WHEN** separately authenticated visitors request server-rendered protected pages concurrently
- **THEN** each response contains only its requesting visitor's authorized identity and Account data

#### Scenario: A server-rendered flow issues a session
- **WHEN** an access flow establishes or rotates a session during server rendering
- **THEN** its response delivers the corresponding cookies to that browser, and subsequent browser requests use the issued session
