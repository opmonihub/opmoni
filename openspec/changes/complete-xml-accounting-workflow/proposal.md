## Why

Accounting offices need usable, complete fiscal XMLs, not distribution summaries presented as finished documents. NF-e capture is now operating in production, making the missing summary-to-complete workflow and the existing incorrect science deadline an immediate operational and compliance risk.

## What Changes

- **BREAKING**: Publish only persisted complete document XMLs in the accounting document list, downloads and fiscal document aggregates. Retain summaries and events privately; show events only in the completed document's timeline.
- Add a secondary processing view with pending, deferred and actionable terminal outcomes, separate from ready documents. Never describe a deadline-expired or permanently rejected item as endlessly processing.
- Automate only the non-conclusive Ciência da Emissão event `210210` for eligible inbound NF-e, followed by asynchronous full-XML retrieval and publication. Never send `210200`, `210220` or `210240` automatically.
- Correct the science deadline to ten days from provider authorization, not ninety days from issue. Preserve the authorization timestamp separately from the issue timestamp. Missing authorization dates, expired deadlines, cancelled notes and self-issued notes must not trigger automatic science.
- Reuse the current fiscal jobs, connector, writer, locks and lookup budget; add durable due times, bounded retries, recovery validation and a frequent recovery dispatcher rather than waiting until 02:30.
- Persist trusted authenticated-delivery provenance for new capture; do not infer transport authentication from XML shape or historical metadata. Historical rows without trustworthy evidence remain in attention and cannot authorize new automatic science.
- Reconcile existing summaries safely: recover XML where correlated provider acceptance already allows it, enqueue new science only where eligible, and retain historical exclusions with an actionable reason. Do not rewrite issue dates, reset NSUs or fabricate eligibility.
- Verify availability and length/SHA-256 integrity of complete XML before ready publication/counts; surface missing/corrupt files in operational attention even after completion, preserving records and audits.
- Keep the non-conclusive-science notice and ninety-day authorization-based conclusive deadline visible after XML completion as well as in Processing.
- Keep production science gated until implementation verification and a scoped live canary succeed. Require explicit disabled/pilot/fleet modes; missing mode or missing/empty/invalid pilot scope blocks work and never implicitly enables fleet. Preserve the independent CT-e and NFS-e gates.

## Capabilities

### New Capabilities

None. The workflow extends the existing integrated fiscal capability rather than creating a new service.

### Modified Capabilities

- `fiscal-documents`: Complete-only accounting reads and aggregates, transparent processing outcomes, eligibility-aware non-conclusive science and asynchronous XML completion.
- `application-runtime`: Durable, Account-aware science/recovery dispatch with bounded execution, scheduled recovery, rollout safeguards and independent-process concurrency verification.

## Impact

- Laravel fiscal metadata extraction, document/manifestation persistence, capture orchestration, recovery jobs/schedules, read services, policies and versioned APIs.
- Nuxt Fiscal documents, processing presentation, filters, counts, detail/download affordances and authenticated API types.
- Additive migrations and a dry-run-first historical reconciliation command; no destructive reset of development data.
- No new package dependencies, backend extraction, external fiscal service, general redesign, automatic conclusive manifestation or additional source rollout.
- The approved product direction changes the accounting read behavior and manifestation policy previously deferred in D007. It does not supersede D009's integrated Laravel boundary or tenant contracts. Enabling production science is a separate implementation/rollout step, not a consequence of creating this proposal.

## Decisions and Safety Boundaries

The user approved complete-only documents, separate processing with visible failures, correction of the science deadline, and subsequent automatic `210210` for eligible notes. Science is not legally consequence-free: Ajuste SINIEF 44/20 establishes the ten-day deadline and subsequent conclusive-event obligation; Ajuste SINIEF 14/26 establishes the ninety-day conclusive window and its effects. The office/destination remains responsible for that later conclusion; this change must communicate that obligation without automating it.

No unresolved product decision prevents implementation. Provider availability and historical recoverability remain externally determined; excluded records must remain inspectable rather than promising recovery. The observed Auto Center historical cohort includes 63 summaries without complete XML and 13 complete documents; those counts are observations, not hard-coded acceptance criteria.
