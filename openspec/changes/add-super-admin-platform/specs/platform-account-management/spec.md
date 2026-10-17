> Reconciliation status (2026-10-13): this artifact records the earlier platform target, not the delivered system baseline. See [system reconciliation](../../../../../docs/architecture/system-spec-reconciliation.md) and `reconcile-system-specs`. D009 supersedes foreign support entry/elevation. Other stronger API, catalog, certificate-source, and concurrency guarantees remain pending where absent or unverified. Reconcile this artifact against the canonical specs before implementation, sync, or archive; do not mark its unchecked tasks complete from the import or documentation sync.

## Purpose

Extend platform Account administration to the mapped legacy table and contact information while preserving the current owner activation, suspension, and identity contracts.

## ADDED Requirements

### Requirement: Extended Account resources and detail
Platform Account list/detail resources SHALL preserve `id,name,status,members_count,pending_owner` and add nullable `phone,state,city`, boolean `phone_whatsapp`, and nullable `subscription:{id,status,plan:{id,slug,name}}`. `GET /api/v1/admin/accounts/{id}` SHALL return `{data:Account}` using the same resource as list rows. Pending-owner metadata SHALL remain limited to name/email/expiry; activation URLs and hashes SHALL never appear in list/detail responses. Existing name/ID search, status filtering, and 15-row pagination SHALL remain available.

#### Scenario: Detail and support search use one envelope
- **WHEN** a Super Admin opens Account editing or searches a numeric Account ID in Suporte
- **THEN** both clients consume the same `{data:Account}` detail response

### Requirement: Account contact editing and validation
Creation and PATCH Account endpoints SHALL accept optional contact fields: phone up to 20 characters, a valid Brazilian UF or null, city up to 255 characters, and a boolean WhatsApp flag. Enabling the flag SHALL require a nonempty phone. Omitted fields SHALL preserve existing values; explicit null SHALL clear nullable fields. Clearing a phone SHALL force `phone_whatsapp=false`. Account name SHALL retain its existing validation and active/suspended statuses. Changes SHALL be atomic and shall not deliver messages, create a WhatsApp channel, or mutate owner identity.

#### Scenario: Clear a phone previously marked for WhatsApp
- **WHEN** an administrator clears the Account phone
- **THEN** the phone becomes null and the WhatsApp flag becomes false in the same committed update

### Requirement: Preserve foundational provisioning
Creation SHALL continue to require Account name, owner name, and globally unused owner email; it SHALL issue the existing seven-day first-access activation without assigning a password or adding a membership before acceptance. POST `/api/v1/admin/accounts/{id}/first-access` SHALL preserve the existing active/zero-members reissue rules, email-conflict checks, prior-link invalidation, and temporary `{data,first_access:{url,expires_at}}` response. Existing owner identities and credentials SHALL remain unchanged. Contact and subscription provisioning SHALL not weaken these checks or change the initial onboarding/default-office behavior.

#### Scenario: Copy a newly issued activation link
- **WHEN** a new Account is provisioned
- **THEN** its newly issued link is available for temporary copying, clears when the modal closes, and cannot be retrieved by reopening list/detail

#### Scenario: Reissue for an activated Account
- **WHEN** an Account already has members and an administrator requests owner-link reissue
- **THEN** the existing 409 rejection is preserved and no identity or activation is modified

### Requirement: Account table and actions
`/admin/contas` SHALL provide ID, Account, situation, plan, member count, and row actions using the shared table presentation, search/filter/column controls, footer count, and pagination. It SHALL provide creation, detail editing, first-access reissue, confirmed suspension/reactivation, and explicit support entry for active Accounts. Contact fields SHALL appear in creation/editing; owner activation SHALL use the current form rather than the legacy password/no-access modes. Suspended Account support entry SHALL be disabled with explanatory feedback. Suspension SHALL preserve stored data and memberships while blocking operational access and activation.

#### Scenario: Suspend during an open support session
- **WHEN** the supported Account is suspended
- **THEN** subsequent tenant requests are denied and the support context is cleared/restored according to the support contract without granting operational access
