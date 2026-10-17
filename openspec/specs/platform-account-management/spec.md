# platform-account-management Specification

## Purpose

Extend platform office administration with separate safe commercial contact data while preserving owner activation, status, and identity contracts.

## Requirements

### Requirement: Foundational provisioning remains authoritative
Office creation, rename/status updates, and first-access reissue SHALL retain the `/api/v1/admin/accounts` access contracts, including unused owner email, seven-day single-use links, and no platform password assignment. Activation URLs SHALL appear only at issuance. Separate commercial edits SHALL not replace owner identities, credentials, or stored activation metadata.

#### Scenario: Commercial payload assigns credentials
- **WHEN** an administrator sends password or owner-access fields to the commercial endpoint
- **THEN** validation returns 422 without changing owner identity or activation

### Requirement: Separate commercial detail and update
`GET` and `PATCH /api/v1/admin/accounts/{id}/commercial` SHALL require Super Admin and return `{data:Account}` with safe tenant settings, `platform_billing_contact`, member count, and subscription/plan when loaded. Foundational list fields including `pending_owner` SHALL retain their own representation; commercial detail SHALL not be documented as a generic `/admin/accounts/{id}` endpoint.

#### Scenario: Commercial access without membership
- **WHEN** a Super Admin without membership reads an Account's commercial detail
- **THEN** the platform read succeeds without authorizing its client or fiscal data

#### Scenario: Unknown office
- **WHEN** a Super Admin requests commercial detail for an unknown Account
- **THEN** the API returns 404

### Requirement: Contact edits preserve unrelated settings
Commercial PATCH SHALL accept nullable phone up to 20 characters, a boolean WhatsApp flag, nullable two-letter state normalized to uppercase, and nullable city up to 255 characters. Omitted fields SHALL preserve saved values, null/empty nullable values SHALL clear contact fields, and removing phone SHALL remove its WhatsApp flag. The update SHALL preserve unrelated tenant settings and private platform activation values.

#### Scenario: Clear commercial phone
- **WHEN** an administrator clears a previously saved phone
- **THEN** the contact no longer contains the phone or its WhatsApp flag and unrelated settings remain intact

### Requirement: Account interface separates access and commercial actions
The Account page SHALL provide foundational creation/edit/reissue controls and a separate commercial editor with real plan/contact data. Issued links SHALL be temporary copy material. Suspension SHALL block tenant access without erasing the Account's members or stored business data.

#### Scenario: Reopen issuance dialog
- **WHEN** the operator closes and later reopens the activation dialog
- **THEN** the previously issued link is not retrieved from ordinary list/detail data
