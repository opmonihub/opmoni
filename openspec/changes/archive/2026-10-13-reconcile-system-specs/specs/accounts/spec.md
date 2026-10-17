## MODIFIED Requirements

### Requirement: Global identities have per-Account membership roles
The system SHALL keep user email unique globally after trimming and case normalization, and SHALL permit at most one membership per user and Account. Membership roles SHALL be exactly `admin`, `operador`, and `user`; Super Admin SHALL be a separate global identity flag. The same identity SHALL support different membership roles in different Accounts. Memberships SHALL be created through bootstrap, owner activation, and the authorized Account member-management contract; member administration SHALL preserve existing global identities and the last Account administrator.

#### Scenario: Different roles for one identity
- **WHEN** an identity has memberships in multiple Accounts
- **THEN** the effective operating role is the membership role of the current Account, not a global role

#### Scenario: Duplicate identity or membership
- **WHEN** an operation attempts a duplicate normalized email or duplicate user/Account membership
- **THEN** the operation is rejected without creating duplicate records

### Requirement: Platform provisioning is restricted to Super Admin
Only a Super Admin SHALL list, create, rename, suspend, reactivate, or reissue owner activation for Accounts through `/api/v1/admin/accounts`. Creating an Account SHALL require `name`, `owner_name`, and `login_email`, create an active Account without an owner membership before activation, and return 201 with `{data,first_access:{url,expires_at}}`. A globally existing owner email or an email already reserved by a live owner activation SHALL be rejected with 422. Password assignment by the platform and Account deletion SHALL not be exposed. Activation links SHALL be returned only when newly issued, without automatic email delivery. Subscription/default-department provisioning SHALL be additive within Account creation; commercial contacts SHALL use the separate platform commercial API without changing this issuance contract.

#### Scenario: Provision a new accounting office
- **WHEN** a Super Admin submits a valid office name and new owner name/email
- **THEN** an active Account and a seven-day owner activation are created together, and the response provides the new link for copying

#### Scenario: Ordinary Account admin tries platform provisioning
- **WHEN** an Account `admin` who is not Super Admin calls a platform Account endpoint
- **THEN** the system returns 403 and changes nothing

## ADDED Requirements

### Requirement: Accounts receive additive business defaults
Account initialization SHALL provide one default active Básico subscription and Fiscal, Pessoal, Contábil, and Societário departments when missing. Reinitialization SHALL preserve existing subscription choices, department customizations, activation records, identities, and memberships.

#### Scenario: Initialize an existing customized office
- **WHEN** an office already has a subscription and customized departments
- **THEN** initialization adds only missing defaults without resetting its data
