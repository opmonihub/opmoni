## ADDED Requirements

### Requirement: First-run leva visitante direto ao onboarding
The system SHALL, when an unauthenticated visitor requests any protected route and the database contains no users and no accounts, redirect the visitor directly to the onboarding route without rendering the login page first. When users or accounts already exist, the system SHALL redirect unauthenticated visitors to the login route. If the registration status check fails, the system SHALL fall back to the login route.

#### Scenario: Base vazia e rota protegida
- **WHEN** the database has no users and no accounts and an unauthenticated visitor opens any protected route
- **THEN** the first response already redirects to the onboarding route, without rendering the login page in between

#### Scenario: Base já populada
- **WHEN** users or accounts already exist and an unauthenticated visitor opens a protected route
- **THEN** the visitor is redirected to the login route

#### Scenario: Falha na checagem de status
- **WHEN** an unauthenticated visitor without a session opens a protected route and the registration status check fails
- **THEN** the fallback destination is the login route

#### Scenario: Visitante autenticado
- **WHEN** the visitor has a valid session
- **THEN** no redirect happens and the requested route renders normally
