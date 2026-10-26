## ADDED Requirements

### Requirement: Monitoring preserves server data and later-visit freshness
The Monitoring overview SHALL retain its authorized initial server-rendered counters during hydration and SHALL NOT replace them with default zero counters as a successful result. Returning to the overview or explicitly refreshing SHALL retrieve current stored state under the selected Account. Read errors SHALL remain retryable failures rather than successful zero counts. Overview rendering and refresh SHALL NOT initiate synchronization, acknowledge messages or perform another provider operation.

#### Scenario: Populated overview loads directly
- **WHEN** direct entry includes nonzero portfolio or obligation-attention counters in the server-rendered overview
- **THEN** the hydrated overview shows those same counters without a duplicate hydration request or default-zero substitution

#### Scenario: Synchronization completes between visits
- **WHEN** a previously admitted synchronization changes stored counters and the member returns to the overview
- **THEN** the view reads the current counters without starting another synchronization or retaining an old visit's payload as fresh data

#### Scenario: Overview read fails
- **WHEN** the selected Account's overview cannot be read
- **THEN** the interface distinguishes the failure from actual zero counters and provides its existing retry behavior without exposing another Account's data
