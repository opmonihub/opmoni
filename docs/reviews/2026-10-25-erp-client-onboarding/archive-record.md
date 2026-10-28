# ERP customer onboarding and office trials archive record

On 2026-10-28, the user selected canonical synchronization followed by archival of `simplify-office-erp-client-onboarding`. The `spec-driven` change had all four artifacts complete and 17/17 tasks complete. Its implementation was already integrated into local `main`; the documentation baseline for this operation was `fa59d1f`.

The [archived change](../../../openspec/changes/archive/2026-10-28-simplify-office-erp-client-onboarding/proposal.md) preserves all nine files, including `.openspec.yaml`. SHA-256 hashes matched before and immediately after the directory move. The task file's relative implementation-plan link was then adjusted for the archive's additional directory level; other archived artifacts were unchanged.

## Canonical synchronization

| Capability | Added requirements | Modified requirements | Delta scenarios |
| --- | ---: | ---: | ---: |
| [client-portfolio](../../../openspec/specs/client-portfolio/spec.md) | 0 | 1 | 5 |
| [erp-commercial-access](../../../openspec/specs/erp-commercial-access/spec.md) | 0 | 3 | 12 |
| [erp-company-foundation](../../../openspec/specs/erp-company-foundation/spec.md) | 1 | 0 | 3 |
| [erp-office-trials](../../../openspec/specs/erp-office-trials/spec.md) | 5 | 0 | 11 |
| [office-erp-commercial-management](../../../openspec/specs/office-erp-commercial-management/spec.md) | 0 | 6 | 23 |
| Total | 6 | 10 | 54 |

Every delta requirement and scenario was compared with its canonical counterpart before archival, with no remaining differences. Existing Purpose sections, all 20 unrelated requirements in affected existing capabilities, and surviving scenarios were preserved. The new trial capability copies its delta's Purpose. Main specs retain one Requirements section and contain no delta operation headers or new placeholders. The separate `standardize-system-ui-patterns` change remains active and unchanged.

## Verification and boundaries

Before archival, strict validation passed all 33 canonical specifications and the selected change. After archival, all five capabilities still match the 16 delta requirements and 54 scenarios, all nine archived files match their original contents except the intended task-link correction, 193 scoped documentation links resolve, and Git whitespace checks pass.

Global strict validation reports 33 passing canonical specifications and one failing separate active change: `standardize-system-ui-patterns`. Its `office-erp-commercial-management` MODIFIED block predates the onboarding synchronization and omits the now-canonical scenarios "Restricted settings are not a loading failure" and "Customer-first workspace on mobile". That delta must be reconciled with the current canonical requirement before its own sync/archive so the onboarding contract is preserved. The interface change's artifacts remain unchanged by this onboarding archive; the global validation is explicitly not green. This does not affect the completed onboarding sync or its pre-archive validation.

The [acceptance record](acceptance.md) and [scenario crosswalk](scenario-crosswalk.csv) retain the implementation's historical backend, frontend, PostgreSQL, browser and migration/recovery results with their source identities and limits. Application tests were not rerun for this documentation-only operation; no new runtime, PostgreSQL/concurrency or live-provider acceptance is claimed.

Only canonical specifications, archive paths and associated documentation changed. Application code, schemas, environment files, keys, identities, development stores and production/registration/trial/fiscal/provider/payment gates remain unchanged. No remote push or production rollout is included.
