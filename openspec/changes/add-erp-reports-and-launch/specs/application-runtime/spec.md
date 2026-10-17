## ADDED Requirements

### Requirement: Unified ERP production release requires complete core acceptance
ERP APIs, public signup and background actions SHALL enforce an explicit disabled, preview or production release mode. Missing/unknown mode SHALL disable ERP operations. Preview SHALL require a non-empty valid company/actor allowlist in an isolated verification environment and SHALL NOT bypass authorization or enable production fiscal effects. Production release SHALL require complete implementation and acceptance of all nine ERP changes and every included source-core matrix item, with unchanged office regressions, real PostgreSQL/Redis process evidence, SSR/browser checks, private persistence/recovery checks and required scoped provider canaries. Partial module implementation or fake-provider success SHALL not authorize complete public launch. Disabling ERP SHALL preserve committed data and existing office operations.

Separately authorized pre-release fiscal canaries SHALL use only a non-public operator harness with explicit actor/company/model/environment/key scope, current company permissions and enabled provider gates. They SHALL retain normal durable attempts and audits and SHALL NOT enable public ERP mode. Missing approval or empty/invalid scope SHALL block the canary; ordinary preview/API/queued operations SHALL not acquire this exception.

#### Scenario: Release configuration missing
- **WHEN** ERP routes or work execute without a valid release mode
- **THEN** the operation is blocked without publishing a partial ERP or changing office availability

#### Scenario: Preview scope is empty
- **WHEN** preview is selected with no valid allowlist
- **THEN** all preview work is blocked and clearing the list cannot imply production mode

#### Scenario: One core acceptance item remains missing
- **WHEN** all but one included core module or required provider verification is complete
- **THEN** complete ERP release remains blocked and the missing evidence is reported explicitly

#### Scenario: ERP must be disabled after deployment
- **WHEN** an operator disables the ERP release gate
- **THEN** new ERP operations stop while committed ERP history and existing office access/capture remain preserved

#### Scenario: Authorized canary precedes public release
- **WHEN** an operator runs an explicitly approved fully scoped fiscal canary while public ERP is disabled
- **THEN** only the non-public harness can execute the authorized effect with normal company permissions and durable evidence, and public ERP operations remain disabled
