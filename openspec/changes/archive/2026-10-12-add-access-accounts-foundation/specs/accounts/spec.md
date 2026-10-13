> Status: planning reconciled with the approved Nuxt frontend and Laravel backend architecture (D006). Product implementation and detailed page acceptance remain tracked in `tasks.md`; this status does not assert completed authentication or Account isolation.

## Purpose

Represent each accounting office as an Account with its own memberships and operating context, while allowing the platform to provision and suspend offices without introducing billing features.

## ADDED Requirements

### Requirement: Global identities have per-Account membership roles
The system SHALL keep user email unique globally after trimming and case normalization, and SHALL permit at most one membership per user and Account. Membership roles SHALL be exactly `admin`, `operador`, and `user`; Super Admin SHALL be a separate global identity flag. The same identity SHALL support different membership roles in different Accounts. This change SHALL create memberships through bootstrap and new-owner activation only; additional member management SHALL be a later change.

#### Scenario: Different roles for one identity
- **WHEN** an identity has memberships in multiple Accounts
- **THEN** the effective operating role is the membership role of the current Account, not a global role

#### Scenario: Duplicate identity or membership
- **WHEN** an operation attempts a duplicate normalized email or duplicate user/Account membership
- **THEN** the operation is rejected without creating duplicate records

### Requirement: Account selection belongs to the current session
`POST /api/v1/account/switch` SHALL accept `{account_id}` and select only an active Account where the authenticated user is a member. The endpoint SHALL support both initial selection in an authenticated session with no current Account and subsequent switching; it SHALL NOT require an already selected tenant context. Nonexistent or foreign Accounts SHALL return the same 403 denial; suspended member Accounts SHALL return 403 with `account_suspended`. Success SHALL return 200 with the current-user representation and SHALL affect only the current session. Switching SHALL NOT restart or extend the authenticated session deadline. Invalid requests SHALL leave the current Account unchanged. Super Admin status SHALL not authorize switching into an Account without membership in this change. Reloads within a valid session SHALL preserve its selected Account without asking for initial selection; status and membership checks SHALL remain authoritative.

#### Scenario: Switching to another own Account
- **WHEN** a member switches to another active Account they belong to
- **THEN** the current session selects that Account and subsequent tenant requests use its membership role

#### Scenario: Switching to a foreign Account
- **WHEN** a user submits an Account identifier outside their memberships
- **THEN** the system returns 403 and preserves the previous selection

#### Scenario: Independent sessions
- **WHEN** a user switches Account in one browser session
- **THEN** another separately authenticated session retains its own selected Account

#### Scenario: Switching remains available without existing context
- **WHEN** an authenticated user without a selected Account submits one of their active member Account IDs
- **THEN** the endpoint selects it in the current session and returns the current-user representation without changing the authenticated deadline

#### Scenario: Suspension races with a switch
- **WHEN** suspension commits before a switch can authorize the chosen Account under the required locks
- **THEN** selection returns 403 with `account_suspended` and preserves the previous selection

#### Scenario: Reload preserves office selection
- **WHEN** a user reloads after selecting an Account that remains active with a valid membership
- **THEN** the same session still reports that Account as selected without resetting to the default

### Requirement: Platform provisioning is restricted to Super Admin
Only a Super Admin SHALL list, create, rename, suspend, reactivate, or reissue owner activation for Accounts through `/api/v1/admin/accounts`. Creating an Account SHALL require `name`, `owner_name`, and `login_email`, create an active Account without a membership, and return 201 with `{data,first_access:{url,expires_at}}`. A globally existing owner email or an email already reserved by a live owner activation SHALL be rejected with 422. Password assignment by the platform, Account deletion, commercial contact details, and subscription provisioning SHALL not be exposed in this change. Activation links SHALL be returned only when newly issued, without automatic email delivery.

#### Scenario: Provision a new accounting office
- **WHEN** a Super Admin submits a valid office name and new owner name/email
- **THEN** an active Account and a seven-day owner activation are created together, and the response provides the new link for copying

#### Scenario: Ordinary Account admin tries platform provisioning
- **WHEN** an Account `admin` who is not Super Admin calls a platform Account endpoint
- **THEN** the system returns 403 and changes nothing

### Requirement: Account listings expose only necessary platform data
`GET /api/v1/admin/accounts` SHALL provide a paginated list with `q` matching name or numeric ID, `status` accepting `active` or `suspended`, and 15 records per page ordered by descending ID. Each item SHALL contain `{id,name,status,members_count,pending_owner}`; `pending_owner` SHALL be null or `{name,email,expires_at}`. `PATCH /api/v1/admin/accounts/:id` SHALL accept `name` and/or `status`, applying all supplied valid fields atomically. Unknown IDs SHALL return 404. Tokens, hashes, passwords, and unrelated operational data SHALL never be listed.

#### Scenario: Rename and suspend in the same request
- **WHEN** a Super Admin sends valid name and suspended status together
- **THEN** both changes are applied and the updated safe Account representation is returned

### Requirement: Suspension preserves data and blocks operating access
Suspension SHALL preserve Account data and memberships while blocking all tenant reads, writes, selection, and owner activation. Super Admin platform operations and authenticated identity/logout endpoints SHALL remain available. Reactivation SHALL restore membership-based access without creating new users or memberships. Expired owner activations SHALL remain expired after reactivation.

#### Scenario: Account suspended during an existing session
- **WHEN** an Account is suspended while a member has it selected
- **THEN** the next tenant request receives 403 with `account_suspended`, and the member can still view available Accounts or log out

### Requirement: Owner activation can be reissued without ambiguity
`POST /api/v1/admin/accounts/:id/first-access` SHALL allow a Super Admin to issue a replacement link only for an active Account with no members, using explicit `owner_name` and `login_email` and the same email conflict rules as creation. It SHALL invalidate the previous unconsumed link, return the new link and expiry, and SHALL NOT modify an existing user. An Account already containing members SHALL return 409; a suspended Account SHALL return 403.

#### Scenario: Replace an expired or lost owner link
- **WHEN** a Super Admin reissues first access for an active Account with no members
- **THEN** only the replacement link can activate the Account owner

#### Scenario: Owner already activated
- **WHEN** a Super Admin attempts reissue for an Account that has members
- **THEN** the system returns 409 without changing memberships or identity credentials
