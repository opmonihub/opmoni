## ADDED Requirements

### Requirement: Company management exposes the reviewed task flow
Commercial tabs, sidebar, search and overview shortcuts SHALL consistently expose Empresas and Assinaturas. Empresas SHALL offer a Novo cliente menu, searchable portfolio-selection modal with inline/bulk addition, Company inspection/editing and a target-bound Add subscription action. Ordering SHALL identify name/date and direction; mobile SHALL preserve access to hidden-column ordering. Assinaturas SHALL open a Company detail with subscription, Usuários e acessos, faturas and history; named nested contract/invoice routes SHALL retain their actual resource identifiers. Old customer URLs SHALL redirect compatibly with legitimate filter/selection intent and old contract URLs SHALL remain functional. Ordinary navigation SHALL preserve the owning shell and permitted list state.

#### Scenario: Apply a plan from a Company row
- **WHEN** the administrator clicks Adicionar assinatura on an unassigned Company
- **THEN** the plan modal is bound to that Company, saving opens its pending subscription detail and activation/access remain deliberate actions in that detail

#### Scenario: Company edit is canceled or fails
- **WHEN** the user cancels editing or receives a definitive field-validation error
- **THEN** cancellation leaves confirmed data unchanged and rejected saving retains the editable draft and named errors

#### Scenario: Old subscription bookmark
- **WHEN** an authorized user opens an existing contract-specific bookmark
- **THEN** its original contract remains inspectable with a Company-detail backlink where a Company is linked, without interpreting the contract identifier as a Company identifier

### Requirement: Access management supports one or many Company users
Usuários e acessos SHALL list active Company users and pending invitations with name/email, role, status and a per-row Gerenciar action. It SHALL support search, authoritative pagination, active/pending filtering, adding a user, recipient invitation resend/revocation and ordinary membership removal. Its focused editor SHALL separate Company permissions/establishment scope from global password recovery. Owner protections SHALL be visible and enforced server-side; Viewer SHALL not offer write grants. Direct-password and email recovery options SHALL target the selected active identity, while a pending invitation SHALL show first-access actions without overwriting an existing identity. Users SHALL never confuse Company removal with global account deletion.

#### Scenario: Company has only the owner
- **WHEN** an administrator opens access management for a Company with one owner
- **THEN** the list renders that owner and ordinary-user addition without presenting owner removal or an empty failed state

#### Scenario: Remove a user with other Company access
- **WHEN** the user confirms removal in the selected Company's editor
- **THEN** the confirmation names that Company and explains that the identity and access elsewhere remain, with the actual row removed only after confirmed success

#### Scenario: Unknown password outcome
- **WHEN** a credential submission has a transport/5xx outcome that cannot be confirmed
- **THEN** password inputs are cleared, automatic resubmission is disabled and only target-bound receipt recovery or recipient login/recovery is offered

### Requirement: Public access continuations keep secrets private
Member-invitation and password-recovery receiver pages SHALL be reachable without an authenticated or selected workspace and SHALL preserve only an allowlisted, short-lived server continuation across required login. Raw tokens SHALL be received in URL fragments, removed before third-party content can run and excluded from referrers, SSR payloads, browser storage, analytics and logs. Pages SHALL separate invalid/expired/replaced links from service failure and prevent a logged-in different identity from silently accepting or resetting another account. Confirmed success SHALL offer normal password login or the existing invitation-acceptance destination without opening general registration.

#### Scenario: Existing identity follows member invitation
- **WHEN** its intended recipient follows the invitation and logs in
- **THEN** the invitation continuation returns to its receiver without a workspace-selection loop and acceptance preserves the existing password

#### Scenario: Receiver service fails
- **WHEN** the backend cannot validate an access continuation
- **THEN** the page offers retry without declaring the link invalid or showing another identity's protected data
