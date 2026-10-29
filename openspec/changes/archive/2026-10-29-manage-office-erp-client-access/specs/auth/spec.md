## ADDED Requirements

### Requirement: Commercial password reset targets an authorized active member
A currently authorized office administrator SHALL directly set a confirmed 8–128-character password only for an active member of its currently managed Company through a target-bound commercial command. The administrator SHALL never read the old password. Global identity and all memberships SHALL remain unchanged, and the UI SHALL state that a reset changes the account password across Companies. Super Admin identities, identities holding any Account administrator role and the acting administrator's own identity SHALL be excluded from this commercial direct-reset path; recipient-only email recovery SHALL remain available when authorized. Raw passwords, password-derived operation fingerprints and credential hashes SHALL NOT enter command journals, audit/history, logs, client storage or queued payloads. The identity's salted password hash remains confined to its credential store.

#### Scenario: Reset a linked ordinary user's password
- **WHEN** a real office administrator submits a valid confirmed password for an eligible active Company member
- **THEN** the password and authentication revision change once, the audit identifies actor/Company/target without credentials, and no membership or ownership changes

#### Scenario: Protected or unlinked target
- **WHEN** a reset references an unrelated/deleted membership, privileged identity or the acting administrator
- **THEN** it is refused without revealing privilege details or changing credentials, and only an authorized email recovery option is offered where applicable

#### Scenario: Same operation is replayed with another password
- **WHEN** a completed reset's original operation identity is submitted again
- **THEN** it returns only the original sanitized receipt after reauthorization and never applies the second password or stores a password fingerprint

### Requirement: Password recovery is recipient controlled and short lived
An authorized member-recovery request SHALL deliver a single-use link only to that member's currently registered email and SHALL never return its bearer token/URL to the office. Recovery tokens SHALL expire after at most 60 minutes; replacement SHALL invalidate older tokens. Completing recovery SHALL require the token, the intended identity and a matching 8–128-character confirmed password, with current initiating-relationship eligibility checked again. The public receiver SHALL not require an office/Company selection or open public registration. Invalid, expired, consumed or replaced links SHALL have indistinguishable invalid-link responses; service failures SHALL remain retryable. Issuance SHALL be limited to one new link per normalized identity per 60 seconds plus shared per-actor/IP attempt budgets; blocked attempts SHALL return 429 with Retry-After. Direct reset and later membership/management revocation SHALL invalidate outstanding recovery attempts for that affected recovery authority.

#### Scenario: Someone asks to redirect recovery
- **WHEN** an administrator supplies a different recipient or foreign user identifier
- **THEN** the request is rejected and no recovery link is returned or sent to the supplied address

#### Scenario: Two receivers consume one link
- **WHEN** independent valid requests attempt to consume the same recovery token
- **THEN** exactly one changes the password and the other receives the same invalid-link response without another credential update

#### Scenario: Mail delivery is unavailable or uncertain
- **WHEN** the configured transport cannot securely deliver a recovery message or its outcome is uncertain
- **THEN** the UI distinguishes failed/unknown delivery from transport acceptance, logs contain no bearer link, and replay/lookup does not issue or send another token automatically

### Requirement: Password changes revoke old sessions globally
Successful direct or recipient password recovery SHALL atomically advance an identity authentication revision with the password change. All sessions authenticated under a prior revision SHALL fail subsequent authentication checks across application instances and Account/Company contexts, even if an already-running request later writes stale session data. Password login SHALL verify the password and capture its authentication revision consistently with concurrent recovery; a request using an obsolete password SHALL not acquire a session carrying the new revision. Recovery SHALL not implicitly authenticate another identity or extend existing absolute deadlines. Missing revision state SHALL be compatible only with the initial unmigrated revision, and authentication-store failure SHALL fail closed.

#### Scenario: Stale session writes after reset
- **WHEN** an old authenticated request persists its session after a password reset committed
- **THEN** subsequent use of that session still receives 401 and cannot revive the old authentication revision

#### Scenario: Password login races reset
- **WHEN** old-password login and reset run in independent processes
- **THEN** login either precedes the reset and receives an immediately obsolete session or rejects the old password, without authenticating it under the new revision
