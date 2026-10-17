## Context

See `proposal.md` for motivation and the two delta specs for observable contracts. The integrated Laravel module already stores summary/document/event as separate records, has a `210210`-only event enum, records science outcomes, and supports point recovery through `ManifestacaoResync`. The gaps are orchestration and publication: `FiscalDocuments` lists every stage, aggregates count pending material, science uses a ninety-day issue-date guard, accepted science has no immediate recovery job, and recovery normally runs once at 02:30.

The current resync also accepts a same-key response without verifying it is a complete document. A processing item must not be marked recovered because another summary or an event was stored. Existing per-client/source capture locks and the CNPJ point-lookup budget must continue governing network calls.

Official sources verified on 2026-10-14:
- https://www.confaz.fazenda.gov.br/legislacao/ajustes/2020/ajuste-sinief-44-20 : science within ten days of authorization; subsequent conclusive manifestation obligation.
- https://www.confaz.fazenda.gov.br/legislacao/ajustes/2026/AJ014_26 : ninety-day conclusive window and statutory effect of no conclusion.

No event has been sent as part of planning. Production NF-e distribution remains enabled independently; `fiscal.manifestacao_enabled` remains false.

## Goals / Non-Goals

**Goals:** Turn each delivered inbound NF-e summary into traceable completion work, register only eligible non-conclusive science, recover a complete XML safely, and publish a usable accounting record only after durable storage. Make waiting and terminal exclusions visible without mixing them with ready documents.

**Non-Goals:** Conclusive recipient actions, CT-e/NFS-e rollout, provider-specific shortcuts, unlimited retries, separate fiscal microservice extraction, legal-advice automation, or modification of unrelated application work.

## Decisions

### 1. Complete-only publication is a backend contract

Use a common backend readiness query for document lists, aggregates and processing exclusion. A record must have `kind=document`, `stage=document` and verified private XML availability/integrity; stage alone is not readiness. Persist `storage_state` (`unverified`, `available`, `missing`, `corrupt`) and `storage_verified_at` on document records. The writer must check successful file persistence and byte length/SHA-256 against the raw delivered XML before publishing `available` and committing the row. Preserve summary and event rows for internal provenance and the document timeline. Existing unique stage identity continues preventing duplicated complete rows.

Before ready pagination, model options and aggregate counts, reconcile private-file availability/integrity for the Account-scoped candidate set in bounded chunks, then apply the common ready query. Use the stored byte length and SHA-256 to verify the file; never reread certificates or call the provider. This adds private-storage I/O, but prevents a stale database stage from claiming an unavailable accounting artifact. A storage service failure is a read failure with preserved last-success UI, not evidence that every file is missing or that there are zero ready documents. Also run bounded scheduled storage reconciliation so loss is visible without a document-list visit. Detail/download authorizes ownership before verification; non-ready summary/event stages return 409, foreign IDs remain 404, missing full XML returns 404, and corrupt full XML returns 409 without serving it as valid.

Storage loss demotes readiness, not the preserved historical completion or accepted science audit. The processing attention projection includes missing/corrupt complete-document records even when no summary exists, exactly once per logical note; a summary with that same key must not create another attention row. Restore/re-capture may republish verified XML without resending an accepted science event. Do not delete evidence, reset NSUs or silently mark historical processing incomplete again.

Align `FiscalCoverage` and `FiscalClients` ready-document measures with this verified query, while certificate coverage and client attention keep their independent meanings. Preserve legitimate completed cancelled notes and their event-derived status. Do not implement this as frontend `filter()`: that would break pagination, totals and direct-download contracts.

Alternatives considered: retaining summaries in the table with badges (rejected by product decision); hiding summaries only in Nuxt (incorrect counts and API leakage); changing stored summaries into completed rows (destroys original evidence and misrepresents XML).

### 2. Preserve provider authorization as a separate field

Add nullable `authorized_at` to `fiscal_documents`, carried through `FiscalXmlMetadataResult`, `PulledDocument` and the writer. Extract NF-e authorization from `resNFe/dhRecbto` and `procNFe/protNFe/infProt/dhRecbto`, scoped to the relevant document root; do not overwrite `emissao_at` with it. Also persist nullable provider note-status metadata from `resNFe/cSitNFe` so cancelled/denied summaries can be excluded even before a cancellation event arrives.

Persist delivery evidence separately from XML metadata: `delivery_origin` (`authenticated_distribution`, `unverified`), `delivery_environment`, normalized `queried_tax_id`, `delivery_received_at` and a correlation identifier generated by the trusted capture invocation. These fields are stamped only by the application-owned connector/capture boundary after a successful authenticated provider response for the explicit Account/client/source and requested tax ID. They are not parsed from XML, accepted from upload/request data, or inferred by the generic writer from the stage, source name, access key or `resNFe` root. Carry a typed trusted delivery context to the writer; a generic/imported document defaults to `unverified`.

A shared science-eligibility service requires this evidence to match the current Account, client, NF-e source, production environment and normalized recipient tax ID, then reads model/stage, author/issuer, authorization date, accepted science, verified complete XML and cancellation/conclusive events. Authenticated native `resNFe` delivery to that queried recipient can establish recipient delivery even when the summary omits a recipient tag; conflicting explicit recipient data fails closed. Imported/unknown provenance, self-issued notes, invalid identities, inactive/deleted client or suspended Account, unusable certificate, missing/future authorization, cancellation/denial or ten-day expiry cannot send new science. Accepted science may still permit guarded recovery after the science window, based on its correlated trusted audit, without inventing summary-delivery evidence.

Historical rows start with `delivery_origin=unverified`. A scoped backfill reads private XML through the same parser only to recover authorization/status and verify storage; it does not recreate authentication evidence. Existing row values (`source`, NSU, capture time, XML shape) are insufficient to promote provenance. Only an already persisted trusted correlated delivery audit, or a new normal authenticated provider delivery for that exact Account/client/source/key, can establish verified provenance. Otherwise classify `delivery_unverified` attention and withhold new automatic science. Never reset NSUs or repeat a fiscal call merely to manufacture evidence. Missing files or timestamps remain explicit attention reasons. Authorization and issue dates remain separate in the UI.

### 3. Durable processing state belongs to the logical summary

Extend the summary record with additive processing fields: `processing_state`, `processing_reason`, `next_attempt_at`, `processing_attempts`, `processing_started_at`, `processing_completed_at`, and a bounded lease/claim expiry. A summary is already the persisted logical key needed before any science registration, including science-ineligible records. No duplicate tracking row or second document identity is necessary.

State vocabulary: `awaiting_science`, `science_queued`, `awaiting_xml`, `recovery_queued`, `deferred`, `attention`, `complete`. Reasons distinguish gate disabled, unverified delivery, certificate/cadastro prerequisites, deadline expiry, cancelled/self-issued note, transient provider failure, publication delay, budget/cooldown, permanent rejection and exhausted attempts. Verified available complete XML has precedence over a stale processing state; publication through the common writer reconciles an existing summary in the same durable operation. Storage availability is an independent axis on full-document records: `missing`/`corrupt` enters operational attention even after historical processing is `complete`, without resetting accepted science or falsifying the original completion date.

Lease updates and state transitions use short database transactions, explicit Account/client/source/key predicates and conditional updates; never hold a database transaction across an HTTP call. Claim expiry permits crash recovery. The manifestation table remains the authoritative external event audit, not the sole processing queue.

### 4. Science and recovery are separate bounded jobs

Keep `SendFiscalManifestationJob` but enforce eligibility again at execution, claim/deduplicate the exact event, persist safe attempts/outcomes, and enqueue a dedicated per-key XML recovery job after `Sent` or a verified duplicate event response. Only `210210` is supported; never add a conclusive event to the automatic enum/path.

Improve response correlation to match requested key, event type/sequence and environment. Lote success or unrelated `retEvento` must not establish acceptance; `136` (accepted but not linked) must remain awaiting provider linkage instead of claiming linked complete acceptance. Preserve valid `573` as an already-registered event for the same correlated logical request, not evidence that any unrelated manifestation succeeded.

Use stable persisted attempt/deadline values rather than a moving `retryUntil()` horizon. The recovery job uses the existing client/source capture lock and shared `FiscalLookupBudget`, then validates source/model, `stage=document`, `kind=document`, exact key, expected recipient and persistence. Store through `FiscalDocumentWriter`; mark complete only after the file and row are committed. A returned summary, event, wrong key or mismatching recipient consumes an unsuccessful executed attempt but never marks `xml_recovered_at`.

Budget exhaustion, active cooldown or occupied lock sets a future due time without spending a network attempt. Default recovery policy: up to three unsuccessful executed point lookups, spaced by at least one hour; exhaustion becomes attention, with no permanent busy state. Next-hour budget boundary and provider unblock time control deferral. Normal incremental distribution can deliver the full XML first and closes the processing item without an unnecessary point call.

### 5. Frequent dispatch replaces nightly-only progress

Add a lightweight due-work command scheduled every five minutes with `withoutOverlapping`. It scans due persisted processing rows in bounded chunks, atomically claims them, and dispatches one science/recovery job per key after commit. Keep the nightly resync/reconciliation as guarded repair paths, using the same due-time and attempt policy so they cannot bypass pacing, duplicate network work or multiply attempts.

After capture stores a new summary, initialize processing and enqueue eligible science after commit. Gate-disabled or historical excluded summaries are recorded truthfully, not silently discarded. Recovery after accepted science is queued for the earliest safe time; actual provider publication may not be instantaneous. Do not call a multi-key global synchronous resync on every accepted event: it could exceed the 85-second job deadline and starve other clients.

### 6. Processing is a secondary Fiscal surface

Add `/fiscal/processamento` and `GET /api/v1/fiscal/processing`, plus `GET /api/v1/fiscal/processing/summary`. Both are Account-scoped reads under the existing fiscal read policy. The list envelope retains `data` and Laravel pagination `meta`; safe fields include client, key, model/source, authorization/issue dates, state, reason, attempts, next eligible time and safe provider code. Filters are bounded `q`, owned `client_id`, `state`, `page`, `per_page`; no secret, storage path or raw XML is returned.

Add a small secondary navigation/count from Documents to Processing, not an automatic replacement of the requested Documents view. Main document list removes summary/event rows and redundant “Resumo aguardando XML” presentation; ordinary completed-document fields, filters, events, download and mobile cards remain. Remove the standalone-event filter from ready Documents while keeping event history in the detail. Display a truthful empty state when XMLs are not ready, with pending/attention counts rather than a successful zero after API failure.

Processing presents two categories, active/deferred and attention, with actionable reasons, including unavailable/corrupt full XML. Read-only members can inspect but get no mutation button. Poll current status at a modest interval only while visible/active (e.g. thirty seconds); cancel on unmount/Account change, preserve last success with an explicit refresh error, and never trigger provider work from a GET.

Keep the legal notice in both Processing and the completed NF-e detail, so it does not disappear when XML recovery finishes. State that `210210` is non-conclusive and XML availability does not confirm the operation; the recipient remains responsible for a conclusive event within ninety days of provider authorization under the applicable rule. Where authorization is known, show its date and the calculated conclusive deadline; missing dates are labelled unverifiable, not invented. Do not infer a conclusive event from XML completion or display a confirmed operation without a recorded correlated conclusive event. The existing timeline/audit, when available, supplies the actual observed event state; otherwise communicate that conclusion is not verified here.

### 7. Historical reconciliation and rollout are explicit

Add a dry-run-first command scoped by required `--account` and optional `--client`, with `--apply` for durable classification and eligible enqueue. It must classify complete records, missing authorization, expired science, prior science, gate/prerequisite blocks and cancellation separately, avoid resetting final audits, and be repeatable under duplicate invocations. There is no blind “send science for all stored summaries” command.

Expose the existing manifestation gate consistently in Compose backend/queue/scheduler with a false default; do not enable it during implementation or migrations. Require a separate explicit rollout mode `disabled`, `pilot` or `fleet`, with missing/unknown mode treated as disabled. The global gate must be true as well as an allowed mode. In `pilot`, require a non-empty valid allowlist of explicit `(account_id, client_id, chave_acesso)` tuples checked at both dispatch and execution; an absent, empty or malformed allowlist blocks all science/recovery work and never falls back to fleet. This prevents a configuration omission from widening the canary. Account/client/key identifiers are non-secret configuration. Fleet activation requires explicitly selecting `fleet` after successful canary evidence, not clearing or omitting a pilot list. Runtime inconsistencies fail closed and are reported.

After fake tests and independent-process verification, perform a single explicitly scoped eligible live canary. The user's policy approval covers only non-conclusive science, not using an expired note or a failed canary as fleet acceptance. If no eligible canary exists, leave live acceptance explicitly unverified and report the blocker; do not weaken the deadline. Enable the wider automatic policy only following successful authorized canary evidence. CT-e/NFS-e retain their existing values.

## Risks / Trade-offs

- Science creates subsequent recipient obligations -> show the ninety-day authorization-based notice in processing and completed detail, preserve audit and do not automate conclusive decisions.
- Historical summaries may be too old or lack delivery evidence for new science -> retain attention and do not fabricate dates/provenance; recovery of previously authorized XML remains conditional on provider availability.
- `resNFe` may omit recipient data -> persist trusted authenticated capture context, reject conflicting recipient evidence and never infer an actor from XML shape or mere access-key possession.
- Provider acceptance is not immediate XML availability -> separated states, delayed bounded jobs, and complete-only publication.
- Concurrent schedulers/workers and delivery duplication -> database claims, per-key event fencing, existing client/source lock, after-commit enqueue, idempotent writer, independent PostgreSQL/Redis process tests.
- Gate disabled or a runtime recreation leaves rows pending -> classify and resume through durable due-work; recheck gates at both dispatch and execution.
- Missing/corrupt private XML despite a document row -> verified readiness excludes it from ready lists/aggregates and includes one operational attention record even without a summary; retain original completion/audit and verify private-storage persistence in rollout.
- Verification I/O increases ready-read cost -> chunk Account-scoped candidate inventory and share the readiness query across reads; prefer explicit storage-service failure over misleading ready counts.
- Complete-only behavior changes old API bookmarks and filter expectations -> consistent 409 for non-ready normal reads, separate processing route, and regression tests of totals and permissions.

## Migration Plan

1. Keep production science false and rollout mode disabled; deploy additive processing/authorization/status/provenance/storage-verification migrations and updated code without discarding existing records.
2. Run the historical backfill/reconciliation preview for the approved Account/client; inspect safe classifications, including unverified delivery and unavailable XML, before `--apply`.
3. Apply classification and switch accounting reads to verified complete-only; verify available complete notes remain downloadable, unavailable artifacts remain in attention, and historical pending notes stay inspectable.
4. Run fake-provider suites and isolated independent PostgreSQL/Redis concurrency tests, then restart long-running workers/scheduler to load changes.
5. With an eligible selected note, explicitly select pilot mode, configure its valid allowlist tuple and enable the gate under that restricted scope; run the science/recovery canary and verify correlated `210210` acceptance, actual validated/stored complete XML and single ready publication. Test that missing/empty pilot configuration blocks all outgoing work. Do not claim completeness from science acceptance alone.
6. Explicitly select fleet mode to enable the authorized wider NF-e science policy only after canary success, retaining independent source gates and monitoring pending/attention counts; clearing the pilot allowlist alone must not activate fleet.

Rollback disables the science gate and stops new outgoing science/recovery dispatch, retaining all XMLs, summaries, events, audits and completed states. Never delete accepted event evidence or try to undo science with a conclusive event. Additive columns can remain; API/UI rollback must not make summary downloads masquerade as full XML.
