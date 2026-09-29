## ADDED Requirements

### Requirement: Vigência do termo provada contra o provedor

The system SHALL use an authorization term validity period that was accepted by the provider's demonstration environment, and SHALL record the proof before any contract proof unlocks term issuance.

#### Scenario: Vigência não confirmada

- **WHEN** the term validity period has not been accepted by the demonstration environment
- **THEN** term issuance stays blocked by the contract-proof gate

#### Scenario: Vigência confirmada

- **WHEN** the demonstration environment accepts a term with the configured validity period
- **THEN** the proof is recorded and the contract-proof gate may open
