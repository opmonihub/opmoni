# Auth Specification

## Purpose

Permite que pessoas criem conta, entrem e saiam do sistema com sessão segura, estabelecendo a identidade usada por todas as demais capacidades de tenancy.

## Requirements

### Requirement: Registro via onboarding cria usuário, conta e assinatura
The system SHALL, on a single registration request with name, email, password, company and team size, create the user, one account named after the company, an `admin` membership linking them, and a subscription on the Basic plan. Registration with credentials SHALL be available only during initial startup, i.e. when the database contains no users or no accounts; otherwise the system SHALL respond 403 and create nothing.

#### Scenario: Primeiro registro da base
- **WHEN** the users table is empty and a registration request arrives
- **THEN** the created user is flagged as super_admin in addition to the account, membership and subscription above

#### Scenario: Registro com base já populada
- **WHEN** users or accounts already exist and a registration request arrives
- **THEN** the system responds 403 and creates nothing (later members are added by an account admin, not by self-registration)

### Requirement: Login e logout por sessão
The system SHALL authenticate login requests with email and password, establishing a server session, and SHALL destroy the session on logout.

#### Scenario: Login válido
- **WHEN** correct credentials are posted to the login endpoint
- **THEN** subsequent requests are authenticated and the current user endpoint returns the user profile

#### Scenario: Credenciais inválidas
- **WHEN** wrong credentials are posted
- **THEN** the system responds 422 without creating a session

### Requirement: Endpoint de usuário atual
The system SHALL expose an authenticated endpoint returning the user, the super_admin flag, the list of accounts the user belongs to, and the current account.

#### Scenario: Sessão ativa
- **WHEN** an authenticated client requests the current user
- **THEN** it receives user data plus accounts and current account

#### Scenario: Sem sessão
- **WHEN** an unauthenticated client requests the current user
- **THEN** the system responds 401

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
