## ADDED Requirements

### Requirement: Commercial context reads are coordinated within current authority
Concurrent commercial workspace consumers under the same actor, session and selected office SHALL share one pending context confirmation instead of queueing duplicate confirmations. New visits and explicit refresh SHALL retain current authorization checks, and changed or invalid capabilities SHALL invalidate protected results and pending consumers. Confirmed read state SHALL remain distinct from unknown, stale, failed and confirmed-empty state. Existing enrollment, plans, trial links, maintenance eligibility, print isolation and command recovery SHALL remain compatible.

#### Scenario: Configuration cards initialize together
- **WHEN** the commercial policy and trial-link cards initialize concurrently for one selected office
- **THEN** exactly one context confirmation is admitted for their shared refresh cycle before their distinct resource reads proceed

#### Scenario: Capabilities resolve on the first workspace visit
- **WHEN** the first context confirmation provides capabilities to a newly opened commercial view
- **THEN** capability publication does not restart that same confirmation or duplicate the view's initial logical data read

#### Scenario: A current context check fails
- **WHEN** context confirmation fails, denies access or changes capability identity
- **THEN** the view retains truthful retry or denial feedback, does not admit newly unauthorized actions, and cannot use an old pending confirmation as current permission

#### Scenario: Office changes during confirmation
- **WHEN** the user changes office or session while confirmation is pending
- **THEN** its response is discarded and it cannot satisfy the new office's consumers or clear their loading state
