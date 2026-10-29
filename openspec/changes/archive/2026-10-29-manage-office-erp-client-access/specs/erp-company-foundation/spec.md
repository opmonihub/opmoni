## MODIFIED Requirements

### Requirement: Company permissions are checked on every operating action
Company reads and writes SHALL require an authenticated identity, active company and current direct membership or valid explicit office delegation. Company roles and granular action permissions SHALL be enforced server-side; viewer/read-only access SHALL not mutate. Owner-only membership, ownership and delegation actions SHALL not become ordinary operating permissions. Platform administration SHALL NOT grant implicit company operating access. Foreign resources SHALL return 404 and foreign related identifiers SHALL return 422.

The separate selected-office commercial member-administration boundary SHALL permit a real administrator to administer ordinary members of currently managed Companies as explicitly specified below. It SHALL NOT grant Company operating access, weaken owner checks on Company-context APIs or make delegation an administrative permission.

#### Scenario: Revoked direct membership
- **WHEN** a user submits an operation after their company membership was removed
- **THEN** the operation returns 403 even when a stale interface still displays the old role

#### Scenario: Platform administrator has no company authorization
- **WHEN** a Super Admin requests company operating data without membership or valid delegation
- **THEN** the request returns 403 and no protected company data

### Requirement: Administrative provisioning separates subscription from owner access
An eligible office administrator SHALL be able to provision a new Company for an active portfolio company Client without inventing an owner identity. The Company SHALL remain explicitly awaiting verified owner setup until its intended responsible person creates or authenticates their identity using a revocable, single-use setup continuation. Subscription assignment SHALL grant neither direct Company membership to the office administrator nor implicit accounting delegation. A Company with an existing owner SHALL retain that owner and require the owner's explicit authorization before a new office association. Pending owner setup SHALL not expose operating Company data to the office. Setup SHALL be recoverable without repeating subscription activation, invoices or trial allocation.

Administrative Company registration SHALL also be possible before commercial assignment, retaining an explicit office-owned registration link and no operating grant. The subsequent assignment and activation SHALL reuse that Company and technical subscription. Only an independently authorized new office association can transfer management of an already owned Company; matching a CNPJ or knowing a user email SHALL not establish that authority.

#### Scenario: Office admin attempts to operate a newly provisioned Company
- **WHEN** an office admin has only commercial administration and no direct Company membership or explicit delegation
- **THEN** Company selection and operating access are denied even when its subscription is active

#### Scenario: Verified owner completes access setup
- **WHEN** the intended responsible person completes valid one-time setup for an unclaimed office-provisioned Company
- **THEN** that identity becomes its owner without creating an office Account, changing the assigned subscription or issuing another invoice

#### Scenario: Unrelated identity attempts owner setup
- **WHEN** an identity different from the intended responsible person attempts setup or a consumed continuation is reused by a foreign actor
- **THEN** access is denied without revealing or changing Company ownership

## ADDED Requirements

### Requirement: Office administrators directly manage linked Company members
A real administrator of the selected active Account SHALL directly list and service members/invitations of a Company currently managed by that office, without additional Company-owner approval or selection of a Company operating context. Historical contracts alone SHALL NOT authorize current membership changes. The boundary SHALL require session ownership, selected-Account assertion, CSRF/origin checks and fresh actor/relationship authorization on every command and recovery. It SHALL permit Manager, Operator and Viewer roles, validated action permissions and all/selected authorized establishments. It SHALL NOT assign Owner, remove/demote the owner, transfer ownership, implicitly enroll the office actor or its office administrators as operating members, or manage unrelated/global identities. Existing Company-owner member APIs SHALL retain their independent owner-only guard.

#### Scenario: Ordinary office administrator manages a member
- **WHEN** a real office administrator edits an ordinary member of its currently managed Company
- **THEN** the Company-scoped role/permissions change without a separate owner approval, office membership or Company operating session for the administrator

#### Scenario: Owner removal or demotion is requested
- **WHEN** the commercial command targets the Company's owner for role replacement or removal
- **THEN** it returns 403 without changing ownership or removing the last owner

#### Scenario: Office actor attempts self-enrollment
- **WHEN** the commercial route is used to grant Company operating membership to the acting administrator or an administrator of the selling office
- **THEN** it is refused and an actual Company owner must use the independently authorized owner flow for such a grant

#### Scenario: Viewer is assigned write actions
- **WHEN** a member change attempts to grant mutation permissions to a Viewer or references a foreign establishment
- **THEN** it returns 422 without partial role, permission or establishment changes

#### Scenario: Remove an ordinary member
- **WHEN** an administrator confirms removal of an ordinary Company membership
- **THEN** subsequent Company checks deny that membership while the global identity, other Company/Account memberships and attributable business history remain intact

### Requirement: Member invitations establish recipient-confirmed access
Adding a user SHALL create a seven-day, revocable, single-use Company invitation with explicit role, permissions and establishment scope. Pending invitations SHALL appear separately from active memberships in the same access list and SHALL grant no operating access. A new identity SHALL establish its own name/password only after proving possession of the invitation; an existing identity SHALL authenticate with the invited normalized email and retain credentials/profile. Acceptance SHALL atomically consume the invitation and establish one membership with current authorized grants. Reissue SHALL invalidate the prior pending link. Company-owner setup remains a distinct purpose; a member invitation SHALL never create an Owner.

#### Scenario: Existing email is invited
- **WHEN** an invitation names an existing global identity
- **THEN** the inviter does not overwrite or receive its credentials and acceptance requires authentication as that invited identity

#### Scenario: Invitation acceptance races membership creation
- **WHEN** independent acceptance requests or an existing owner flow target the same Company/user
- **THEN** at most one membership is created and no request promotes that user, duplicates grants or overwrites credentials

#### Scenario: Invitation loses its authorized relationship
- **WHEN** the office/Company management relationship or invitation is no longer authorized before acceptance
- **THEN** acceptance creates no membership and does not disclose foreign Company data

#### Scenario: Unknown-email first access
- **WHEN** the intended recipient accepts a valid invitation using a new identity and a valid confirmed password
- **THEN** identity, ordinary membership and invitation consumption commit together without creating an office Account or activating/billing the subscription
