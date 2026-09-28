## Context

Motivation is in `proposal.md`. This document covers how.

**Provenance.** The decisions below were taken in `archive/2026-09-27-add-integra-contador-sync`,
whose `design.md` remains the full record — including the provider research that justifies them.
D1, D2, D3, D4, D9, D12, D13, D14, D16 and D17 are **already realized in code** and are not
restated here. What follows is the subset that governs the work still to be done, plus the three
places where reading the code contradicted the decision as written.

**What the transport left behind.** The client is complete and correct against the provider:
one `POST` returning two tokens with a margin on the published validity, a single re-authentication
on `401`, the five-role envelope with `dados` as a JSON string inside a JSON string, the 32-character
`X-Request-Tag`, the PFX materialized at `0600` and unlinked in a `finally`, and the `ICGERENCIADOR`
code table mapped to four retry classes. `SerproFailure` is the only enum. Nothing calls it —
`SerproClient` is reachable only from a test, and `SerproMonitoring` is still a `name` with a
full CRUD around it.

**Three contradictions found while implementing.** Each is recorded because the decision text and
the code now disagree, and the code is what ships.

The `contratante` does not live in configuration. D13 puts the fixed `contratante` in
`config/integra-contador.php`, but it was written to `serpro_connections.contratante_numero` and
`contratante_tipo` instead. The database is the better home for the same reason D1 chose it for
the secret — the contracting e-CNPJ is part of the credential, it is read from the certificate
rather than typed, and it must be rotatable without a redeploy. The configuration file keeps the
service map, the two gateway bases and the timeout budgets; it does not keep the document.

The frontend does not model a call record. D9 persists a tag, a `responseId` and the `mensagens`
on every call, and the original task list asked for a corresponding TypeScript type. The type was
never written, because no screen consumes it and the composed backend is where the cost
reconciliation actually happens. The call log stays a backend concern; the `X-Request-Tag` is
still what makes per-office attribution possible, and it is already generated.

The enablement control is not on the connection screen. It was specified as part of
`admin/serpro.vue` and then deliberately excluded, with a comment attributing it to the Account
admin. That is the right call — enabling an office is an account-level permission, not a platform
credential operation, and putting it next to the consumer secret would mix two different blast
radii on one screen. It ships with D10's endpoint instead of with the credential form.

**The provider's constraints that shape the remaining work** are unchanged from the archived
design and are not repeated: the term is signed by the *procurador*, whose documented example is
"escritórios de contabilidade", which is what makes the term per office rather than per client; a
`504` does not guarantee the operation was not completed; `PGMEI` requires one request at a time
per CNPJ *and per assessment year*; only `200`, `202` and `403` are billed, and a `504` is not.

## Goals / Non-Goals

**Goals:**

- An office authorizes once, uploads its certificate once, and never acts again.
- A per-client, per-family authorization gate that is read from the provider, never established by us.
- A durable, inspectable record of every run and every call, surviving worker restarts.
- Per-client, per-service cost attribution against the shared platform contract.
- The read API the frontend already calls, with an honest "not served" state instead of a zero.
- The transport gaps closed before any of the above can be trusted end to end.

**Non-Goals:**

- Any Receita Federal write. This change only reads.
- Establishing a procuração through the product.
- Syncing individual clients; most read-only services accept `tipo` 2 only.
- Hand-rolling XMLDSig. The provider publishes the reference implementation of its own validator's
  expected format and it is MIT.
- Fixing the pre-existing `retry_after` mismatch on `CaptureFiscalDocumentsJob`.

## Decisions

### D1. The contracting document is read from the certificate, never typed

The `contratante` in the envelope must equal the CNPJ in the platform certificate, or the gateway
answers `AcessoNegado-ICGERENCIADOR-016`. That makes the certificate the authority and the column
a cache of it, so the document is extracted at upload time and compared before the first call
rather than trusted as configuration.

The comparison is a precondition, not a warning. Failing it early turns a gateway `403` — which
is indistinguishable, for a shared credential, from a wrong password — into a named
configuration fault with no network traffic at all. This is the one gap in the transport that
silently burns money if left open, which is why it is task 1.5 rather than a nicety.

### D2. The office certificate reuses the existing vault, and signing reuses the provider's component

`ClientCertificateVault` already validates with `openssl_pkcs12_read`, extracts non-secret metadata
with `openssl_x509_parse`, encrypts with `Crypt::encryptString` on a private disk and zeroes the
password in a `finally`. It cannot hold an office certificate because it is bound to a `Client` and
its path is `account_id/client_id/uuid.enc`.

So the parsing, encryption and password-hygiene logic is **extracted into a shared unit** and both
the client vault and the new office vault become thin callers. This is the one pre-existing file
this change restructures, and the existing certificate tests must keep passing unchanged — that is
the guard against the refactor silently altering behaviour, not a formality.

Signing is `Serpro.Componentes.AssinadorDigital.php`, vendored into `app/Support/` and wrapped.
It is wrapped rather than called directly for two reasons that are not stylistic: it is the single
place invisible Unicode gets stripped, which the provider warns causes
`AcessoNegado-AUTENTICAPROCURADOR-013`, and it is the single place that keeps the signed document
out of logs. `git diff composer.json` staying empty is the test.

### D3. The term is per office, stored verbatim, and renewed by re-POST

One term per office, signed by the platform over the office's identity, submitted to the free
`/Apoiar` service. The signed document is kept **verbatim** because renewal is a re-POST of
exactly those bytes: a term still in the `validado/autenticado` state answers `304` with the token
in the `ETag`, so the daily refresh re-signs nothing and asks nothing of the office.

This is what makes the feature automatic rather than a chore the office has to remember, and it is
the reason "regenerate the document" would be the expensive mistake — a different byte sequence is
a different document, and the `304` path is what avoids re-signing.

Two consequences shape the model. The term carries its own validity, and when it lapses only the
office can renew it, so the product must name the office as the party who must act rather than
reporting a generic failure. And the token is per office, not per client, which is why it is a
property of the office's connection rather than of a run item.

### D4. Authorization is per (client, family), read from the provider, never written by us

`client_ecac_powers_of_attorney` gains the Serpro procuração code and an integration state, and a
join table records which family each client's procuração covers. The state is read from
`PROCURACOES/OBTERPROCURACAO41`, which is itself one of the services marked as not requiring a
procuração — so the product can always ask, including to discover that it never had one. That call
is the permission oracle: it returns, per client, the expiry and the list of RFB systems actually
granted, and it explains every other service's `-022`.

Per family rather than one boolean, because the concrete codes differ per service — `REGIMEAPURACAO`
`00060`, `SITFIS` `00002`, `CAIXAPOSTAL` `00006`, `DCTFWEB` `00103`, `DTE` `00050` — and a single
flag would misreport coverage in both directions.

**One exception the provider's table does not state, which this change must record: `00146` is
shared by `PGDASD` and `DEFIS`.** One client's grant of the PGDAS-D power of attorney covers both,
so those two obligations are not independently authorizable and the office setup instructions must
not present them as two grants.

The oracle answers in **names, not codes** — `sistemas[]` carries e-CAC system names as free text
like `"Caixa Postal - Mensagens"`, not the numeric codes the services table is keyed by. The
name-to-family mapping is a table this change owns, it is not derivable, and it is the piece most
likely to rot if the provider rewords a name, so the vocabulary is spelled out in a test rather than
left implicit in a config file.

### D5. Runs are durable rows; per-client work is one idempotent job

`serpro_sync_runs` and `serpro_sync_run_items` are database tables, not `Cache::put`. The existing
`DeleteClientsJob` caches its result for two hours, which is right for a one-shot bulk delete and
wrong here: the requirement is a history an office can look back at, and a Redis eviction or a
worker restart must not erase a run.

The run is created synchronously in `queued`; a fan-out job dispatches one child job per client
carrying `accountId` explicitly, because `CurrentTenant` is a mutable singleton that is never reset
and the value from the previous job survives in a long-lived worker. The child is idempotent
through a unique constraint on `(run_id, client_id)`, so a duplicate delivery updates rather than
duplicates, and it re-checks eligibility and the term inside `handle()` instead of trusting the
fan-out.

Calls are serialized **per client**, because `PGMEI` requires one request at a time per CNPJ. A
`504` is recorded as a distinct non-terminal outcome, never retried inside the same run, and never
counted as a client failure. The `401` case is deliberately different and does retry, because
re-authenticating once and replaying once is unambiguous.

### D6. `SerproMonitoring` stops being a name and becomes the client link

The table carries `account_id` and a `name` and nothing else, and the CRUD around it has no
consumer: the frontend has never called `/api/monitorings`. The rows are removed rather than
migrated — there is no client to attach them to — and the table becomes the link between an
office's client and its synchronized state, keyed by `client_id` with a state and a provenance
timestamp.

The CRUD, the policy and the `'monitorings'` key in `PlanLimits` go with it, because the thing
being counted is now "cliente × obrigação" and the old key would silently report a number nobody
queries. **This is the one destructive task in the change and it must come before the read API**,
which depends on this table already carrying `client_id` and state. It breaks three test files that
currently assert the old behaviour; removing it and updating them are one piece of work.

### D7. The read API is derived from synchronized data, and an unserved obligation is a fourth thing

The nineteen monitored obligations are the product's surface and they are **not** what the
catalogue covers. PGDAS-D publishes a closed tax-code table with no FGTS; `DET` and the FGTS
mailbox entry are filters over `assuntoModelo`, not services; Certidões is a projection of the same
`SITFIS` PDF; federal parcelamento via PGFN has no service; and `DIRF` is not a missing-data case at
all — it was substituted by EFD-Reinf and eSocial, neither exposed by the Integra Contador.

So each obligation is `direct`, `derived`, `unavailable` or `extinct`, and the last two show no
counter and no client row. Collapsing them into "no data" would tell an office that a federal
parcelamento screen is broken when the honest answer is that nobody serves it.

The classification is **configuration, not code**, it records the publication date of the catalogue
revision it was read from, and a test asserts all nineteen entries still resolve. A mapping that
cannot be re-verified in one reading of a config file is a mapping that will be trusted past its
shelf life — the catalogue is a moving document that disagrees with itself in at least eight places.

### D8. The integration is enabled per Account, not per platform

Each Account carries an explicit flag, writable only by `admin`. The shared credential is precisely
the argument for a per-Account switch: a revoked credential, a Serpro outage, a suspended endpoint
or a lapsed platform certificate should not reach every office at once, and turning one office off
is the fastest containment available. Disabling keeps runs and synchronized data readable — it
stops new work rather than erasing history.

The control lives with the account admin, not on the platform connection screen. Mixing the two on
one page would present a per-office permission next to a platform-wide secret and invite the reader
to treat them as the same kind of thing.

### D9. Documents are text everywhere

The provider requires `numero` to be treated as text in every layer, explicitly warning against
restricting it to `[0-9]+`, because alphanumeric CNPJs arrive under RFB IN 2.119/2022.
`BrazilianTaxId::normalize` currently does `preg_replace('/\D+/')`, which **discards the letters** and
therefore rejects every alphanumeric CNPJ. This is the single most damaging gap in the shipped
transport: the integration fails at the moment an office is saved, with a validation error that names
nothing about the real cause.

The `tax_id` column is already `string(14)`, so the fix is confined to the validation layer, and it
needs the paired test — one valid alphanumeric accepted, one invalid rejected — because a validator
that accepts everything is not a fix.

## Risks / Trade-offs

- **The office's certificate becomes a platform-wide secret.** The platform now holds a signing key
  per office, a larger blast radius than a read-only consumer key: it can sign a term on that
  office's behalf. → Same vault discipline, password zeroed after use, signed document never leaves
  the backend, removal deletes the encrypted contents while keeping non-secret metadata for audit.
  A compromised office certificate is a credential incident and is treated as one.
- **Signing is a correctness surface, not a formatting one.** A subtly wrong enveloped XMLDSig fails
  at the provider with an opaque code. → Vendor the provider's own component, normalize in one
  place, assert the generated document's structure locally instead of discovering it at the gateway.
- **The `autorPedidoDados` role assignment is inferred, not documented in one place.** It comes from
  reading `-019` and `-054` together, and it is the difference between a working integration and a
  wall of `403`s. → Isolated in a single function with its own tests; `-019` and `-054` are
  classified as "term problem, do not retry" so a wrong guess surfaces as a clear, non-retrying
  failure rather than a silent one.
- **A suspended endpoint has a platform-wide blast radius.** → No hot retries, a per-office off
  switch, and a run reason that distinguishes provider suspension from client failure.
- **Terms lapse and tokens expire at midnight daily.** → The refresh is a `304` re-POST needing no
  user action; the validity is surfaced with a warning naming the office as the party who must act.
- **Queued jobs have no test coverage anywhere in the repository.** `QUEUE_CONNECTION=sync` means a
  dispatched job runs inline and is invisible to the suite. → The child job is tested through
  idempotency and tenant hydration rather than queue assertions; the debt is named rather than
  claimed as paid.
- **No static analysis.** There is no PHPStan or Larastan, so a PHPDoc array shape that
  misdescribes a provider payload will not be caught — and the string-encoded `dados` is proof that
  provider payloads are exactly where a wrong assumption hides. → Tests assert the exact stored key
  set, following the existing `assertArrayNotHasKey` discipline.
- **The frontend is already written against an API that does not exist.** Every screen currently
  renders its empty state, which looks like working software. → The verification section exists to
  prove a populated path end to end, not just an empty one; a screen that only ever shows "no data"
  has proven nothing.
- **Removing the `monitorings` CRUD breaks existing tests.** → Named in the task list as part of the
  same task, so it is never a surprise discovered by a red suite.

## Migration Plan

1. Additive migrations only: create `account_certificates`, `serpro_authorization_terms`,
   `serpro_sync_runs`, `serpro_sync_run_items` and the per-client service authorization table;
   extend `serpro_monitorings` and `client_ecac_powers_of_attorney` with nullable columns. Nothing
   is retyped, so every step reverses by dropping the new tables.
2. The existing `serpro_monitorings` rows are removed, not migrated — they carry only a `name` and
   there is no client to attach them to.
3. The integration stays inert until a connection row exists. With no row, the connectivity check
   reports not configured, the sync endpoint refuses to create a run, and the monitoring views fall
   back to empty states. This is already the behaviour in production today; the change makes it real.
4. The read API is registered last, in the existing `['auth:sanctum', 'tenant']` group, unversioned,
   following the existing convention.
5. Deploy order follows the root `AGENTS.md` production rules: build both images, apply migrations
   once by hand via the `migrate` service, then `docker stack deploy`. No migration runs in an
   entrypoint or the worker.
6. Rollback is dropping the new tables and redeploying. No `APP_KEY` rotation is involved, and none
   may be performed, since it is what encrypts the stored credential.

## Open Questions

- Whether an expiring procuração or term escalates beyond a warning. The requirements only report
  the approach, and no product action depends on the answer yet.
- Whether the per-office term also needs to name each contributor explicitly. The documentation
  describes the term as authorizing requests in the author's name, which suggests the contributor
  travels in the envelope rather than in the document, but it is worth confirming with a contract
  because it decides whether one term per office is genuinely enough.
- Whether payment confirmation should come from `PGTOWEB` on top of the `dasPago` flag the
  declaration already carries, and whether that is worth a fifth e-CAC procuração code. The
  derivation is sufficient for a maintenance view; the collection record is authoritative for
  reconciliation.
- Whether a per-office spending cap belongs in the product. The `X-Request-Tag` is the prerequisite,
  but the feature itself is a separate decision.
