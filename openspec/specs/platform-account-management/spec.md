# platform-account-management Specification

## Purpose

Extend platform office administration with separate safe commercial contact data while preserving owner activation, status, and identity contracts.

## Requirements

### Requirement: Foundational provisioning remains authoritative
Office creation, rename/status updates and first-access reissue SHALL retain the `/api/v1/admin/accounts` access contracts, including unused owner email, seven-day single-use links and no platform password assignment. Activation URLs SHALL appear only at issuance. Creation SHALL preserve `{data,first_access:{url,expires_at}}`; reissue SHALL preserve `{first_access:{url,expires_at}}`, active/zero-members rules, prior-link invalidation and existing 409 rejection for an already activated Account. Optional contact/subscription provisioning SHALL be atomic with issuance; failure SHALL leave no partial Account/activation/subscription. Existing identities, passwords, memberships and default-office/session behavior SHALL be preserved. Separate commercial edits SHALL not replace owner identities, credentials or stored activation metadata.

#### Scenario: Commercial payload assigns credentials
- **WHEN** an administrator sends password or owner-access fields to the commercial endpoint
- **THEN** validation returns 422 without changing owner identity or activation

#### Scenario: Issuance contact failure
- **WHEN** contact or subscription persistence fails while creating an Account
- **THEN** issuance rolls back without a partial Account, activation or subscription

#### Scenario: Reissue after activation
- **WHEN** an Account already has members and owner access is reissued
- **THEN** the existing 409 response preserves identity and activation

### Requirement: Separate commercial detail and update
GET and PATCH `/api/v1/admin/accounts/{id}/commercial` SHALL require Super Admin and preserve their existing `{data:Account}` representation with safe tenant settings, `platform_billing_contact`, member count and subscription/plan when loaded. Foundational list fields including `pending_owner` SHALL retain their representation. Commercial detail SHALL remain a distinct API alongside the additive generic safe detail, and SHALL not authorize foreign tenant business data.

#### Scenario: Commercial access without membership
- **WHEN** a Super Admin without membership reads an Account's commercial detail
- **THEN** the platform read succeeds without authorizing its client or fiscal data

#### Scenario: Unknown office
- **WHEN** a Super Admin requests commercial detail for an unknown Account
- **THEN** the API returns 404

### Requirement: Contact edits preserve unrelated settings
Commercial PATCH and root Account create/PATCH SHALL share validation/persistence of settings-backed `platform_billing_contact`; root phone/state/city fields SHALL be a safe projection, not conflicting columns. Nullable phone SHALL be at most 20 characters, state SHALL be a valid Brazilian UF normalized uppercase, city SHALL be nullable and at most 255 characters, and WhatsApp SHALL be boolean and require nonempty phone. Omitted fields SHALL preserve saved values; explicit null/empty nullable input SHALL clear values; removing phone SHALL force phone_whatsapp false. Changes SHALL be atomic and preserve unrelated tenant settings, private activation values and owner identity. Contact editing SHALL not dispatch messages or create WhatsApp channels.

#### Scenario: Clear commercial phone
- **WHEN** an administrator clears a previously saved phone
- **THEN** the contact no longer contains the phone or its WhatsApp flag and unrelated settings remain intact

#### Scenario: Invalid UF or WhatsApp combination
- **WHEN** root or commercial contact input contains invalid UF or enables WhatsApp without phone
- **THEN** validation returns field errors without partial contact, owner or activation changes

### Requirement: Account interface separates access and commercial actions
The Account page SHALL use the shared table presentation with ID, name/status, plan, member count, search/status/column controls, count, pagination and row actions. It SHALL provide foundational create/edit/reissue/confirmed status controls and retain a separate commercial editor. Create/edit SHALL expose contact fields. Issued links SHALL be temporary copy material. Entry SHALL use ordinary switching only for an active real membership, preserving its role; foreign/suspended entry SHALL be disabled with feedback. Suspension SHALL block tenant access without erasing members or stored business data, and SHALL not authorize a foreign support context.

#### Scenario: Reopen issuance dialog
- **WHEN** the operator closes and later reopens the activation dialog
- **THEN** the previously issued link is not retrieved from ordinary list/detail data

#### Scenario: Suspended real membership
- **WHEN** the selected Account is suspended before a subsequent tenant request
- **THEN** tenant access is denied and identity/client state refresh follows the existing membership contract without elevation

### Requirement: Generic safe Account projection and paginated detail
GET `/api/v1/admin/accounts/{id}` SHALL require Super Admin and return `{data:PlatformAccount}` using the same safe projection as list rows. The resource SHALL retain `id,name,status,members_count,pending_owner` and add nullable `phone,state,city`, boolean `phone_whatsapp` and nullable `subscription:{id,status,plan:{id,slug,name}}`. Pending owner SHALL expose only name/email/expiry; activation hashes/URLs and arbitrary private settings SHALL never appear. Existing bounded literal name/ID search, status filter, deterministic ID order and 15-row `meta.page` pagination SHALL remain, with additive `last_page` and eagerly combined metadata.

#### Scenario: Numeric support detail lookup
- **WHEN** a Super Admin searches a numeric Account ID in Suporte or opens Account editing
- **THEN** both consume the same safe generic detail envelope, with unknown IDs returning 404

#### Scenario: Combined Account list
- **WHEN** a list page includes contacts, members, pending owner and subscription plan
- **THEN** all rows use the safe common projection with existing pagination keys plus last_page and no activation material
