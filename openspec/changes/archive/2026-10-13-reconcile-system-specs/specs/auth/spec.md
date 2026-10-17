## MODIFIED Requirements

### Requirement: Bootstrap is available only once
The system SHALL expose `GET /api/v1/registration-status` with `{registration_available:boolean}` and SHALL accept `POST /api/v1/register` only when no users and no Accounts exist. A valid registration SHALL atomically create one user, one active Account, an `admin` membership, and a session selecting that Account; the user SHALL be the initial Super Admin. Input SHALL contain `name`, `email`, `password`, `company`, and `size`; the team-size value SHALL be one of `solo`, `2-10`, `11-50`, `51-200`, or `200+` and SHALL be persisted. Passwords SHALL contain 8–128 characters. Account creation SHALL also initialize one active Básico subscription and the Fiscal, Pessoal, Contábil, and Societário departments within the creation transaction. Existing catalog rows SHALL not close initial registration or be reset by bootstrap.

#### Scenario: Successful initial registration
- **WHEN** a valid initial registration is submitted to an empty installation
- **THEN** the system returns 201, creates the user, Account and membership together with its default subscription and departments, authenticates the user, and disables subsequent registration

#### Scenario: Installation already initialized
- **WHEN** any user or Account exists and a registration is submitted
- **THEN** the system returns 403 with no new user, Account, or membership

#### Scenario: Concurrent bootstrap requests
- **WHEN** two valid registrations reach an empty installation concurrently
- **THEN** exactly one returns 201 and the other returns 403 after initialization, with only one bootstrap user and Account created

## ADDED Requirements

### Requirement: Business writes use the established access session
Client, fiscal, monitoring, work, member, department, and WhatsApp browser mutations SHALL use the existing authenticated session, trusted frontend origin, and session-bound CSRF token. Business integration SHALL not create a separate bearer-authentication or legacy-login path. Public provider webhooks SHALL instead follow their explicitly authenticated provider contract.

#### Scenario: Business write without CSRF
- **WHEN** an authenticated member posts a client with a missing or invalid CSRF token
- **THEN** the API returns 403 before creating the client
