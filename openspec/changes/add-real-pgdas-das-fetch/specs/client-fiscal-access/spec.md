## ADDED Requirements

### Requirement: Probes respeitam procuração e-CAC do cliente canário
The system SHALL evaluate the canary client's power of attorney exclusively from provider-confirmed authorized service families before issuing a PGDAS homologation probe, and SHALL treat family `00146` as the gate shared by PGDAS and DEFIS.

#### Scenario: Família 00146 confirmada
- **WHEN** the provider has confirmed family `00146` as established for the canary client
- **THEN** the PGDAS probe may proceed for that client

#### Scenario: Procuração ausente ou expirada
- **WHEN** family `00146` is not established or is expired for the canary client
- **THEN** the probe skips the provider call, reports the client as not eligible, and names procuração e-CAC as the correction

#### Scenario: Pessoa física
- **WHEN** the configured canary identifier belongs to an individual taxpayer
- **THEN** the probe refuses with an ineligibility reason and performs no PGDAS call, consistent with synchronization rules for company clients only
