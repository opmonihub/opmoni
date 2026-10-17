## Purpose

Manage real office membership and department organization without changing global identity or granting platform-role tenant bypasses.

## ADDED Requirements

### Requirement: Member administration is reserved to Account admins
The member-management endpoints SHALL require a real `admin` membership. Creation SHALL validate a globally unused normalized email, name, password, and one of `admin|operador|user`, enforce the users plan limit, and create the user and membership together. Update SHALL change the Account role rather than reset the existing global identity or credentials. Removing a membership SHALL leave the global user identity intact.

#### Scenario: Email belongs to an existing identity
- **WHEN** a new member request supplies an already used normalized email
- **THEN** the API returns 422 and does not overwrite that identity

#### Scenario: Read-only Super Admin membership
- **WHEN** a globally privileged user with role `user` attempts member administration
- **THEN** the Account operation is denied

### Requirement: Last Account administrator is preserved
Demotion or removal of the last `admin` membership SHALL return 422. Membership changes SHALL be checked against current persisted roles and SHALL serialize the last-admin decision within the Account.

#### Scenario: Remove last administrator
- **WHEN** the only remaining Account admin is targeted for removal
- **THEN** the request returns 422 and the membership remains

### Requirement: Departments and directory are Account scoped
Members SHALL read the office member directory and departments. Department management SHALL require an Account `admin` or `operador` and SHALL validate member references within that Account. New and backfilled Accounts SHALL have the four default Fiscal, Pessoal, Contábil, and Societário departments without overwriting customized departments.

#### Scenario: Foreign department member
- **WHEN** a department update references a user without membership in this Account
- **THEN** the operation is rejected rather than creating a foreign department link
