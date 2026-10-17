## Purpose

Control each office's WhatsApp channel and explicit client-guide dispatches with safe credentials, provider feedback, and Account isolation.

## ADDED Requirements

### Requirement: Office channel pairing is admin controlled
Members SHALL read the selected Account's channel and pairing status. Only a real Account `admin` SHALL initiate pairing or disconnect. The channel SHALL remain Account-specific, instance credentials SHALL be encrypted and hidden, and pairing initiation SHALL be rate limited. With the WhatsApp feature disabled, its operational endpoints SHALL return 404.

#### Scenario: Operator attempts pairing
- **WHEN** an `operador` membership initiates WhatsApp channel pairing
- **THEN** the API returns 403 without pairing a number

#### Scenario: Gateway unavailable
- **WHEN** the gateway cannot complete pairing
- **THEN** the UI reports failure and does not claim the channel is connected

### Requirement: Dispatch contacts belong to the client and Account
Members SHALL read client dispatch contacts; `admin` and `operador` SHALL create, update, or remove valid contacts. A contact SHALL represent its declared channel and belong to the route client and selected Account. A contact identifier from another client or Account SHALL return 404.

#### Scenario: Contact belongs to another client
- **WHEN** an operator updates a contact through a different client route
- **THEN** the API returns 404 and preserves the contact

### Requirement: PGDAS guide delivery is an explicit operation
Sending PGDAS guides or supported declaration artifacts SHALL require an operational writer and selected authorized client contacts. Synchronization, association, schedule changes, or ordinary reads SHALL not automatically send messages. The API SHALL expose Account-scoped dispatch tracking and distinguish accepted, delivered, and failed outcomes; a gateway failure SHALL not be described as delivery.

#### Scenario: Synchronization completes
- **WHEN** a PGDAS synchronization produces a guide
- **THEN** no WhatsApp dispatch is started without an explicit send action

#### Scenario: Other obligation dispatch
- **WHEN** a send request references an unsupported obligation
- **THEN** the API returns 404 instead of sending unrelated material

### Requirement: Webhook authentication and event deduplication
`POST /api/v1/webhooks/wzap` SHALL use its provider authentication and event validation rather than browser session CSRF. Events SHALL resolve the authenticated channel/instance and update only its matching dispatches. Replayed events SHALL not duplicate delivery transitions; unauthorized callbacks SHALL not mutate channel or dispatch state.

#### Scenario: Forged callback
- **WHEN** a callback lacks valid channel authentication
- **THEN** the event is rejected without changing delivery state
