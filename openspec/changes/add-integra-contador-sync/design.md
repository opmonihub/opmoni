## Context

Motivation is in `proposal.md` — this document covers how.

The repository has more to reuse than the provider has to teach. `app/Concerns/BelongsToAccount.php` already provides a tenant global scope, a `creating` hook and a tenant-aware `resolveRouteBinding`. `app/Services/CnpjWsLookup.php` is the only outbound call and establishes the house style for an external client — `final class`, domain exception carrying an HTTP status, `data_get()` mapping, explicit status mapping. `ClientCertificateVault` already parses PKCS#12 with `openssl_pkcs12_read`, already handles the password-never-persisted rule, and already knows an A1 certificate arrives as a password-protected `.pfx`/`.p12`. `client_ecac_powers_of_attorney` already models a client's e-CAC authorization with dates. `Http::fake()` is the established test seam. All of that is reused.

The provider side was researched against the SERPRO documentation and, where possible, executed live. Confidence is marked per claim.

**Authentication is one POST returning two tokens, and the certificate is mandatory.** `POST https://autenticacao.sapi.serpro.gov.br/authenticate` with `Authorization: Basic base64(consumerKey:consumerSecret)`, `Role-Type: TERCEIROS`, `Content-Type: application/x-www-form-urlencoded` — a JSON content type returns `415` — and body `grant_type=client_credentials`. The call presents an e-CNPJ certificate, and the documentation states it must be the certificate used to contract the product. This was confirmed empirically: calling `/authenticate` without a client certificate returns `HTTP 400 {"message":"Não foi possível identificar um certificado digital válido."}` — the server rejects at the TLS layer before it even looks at the consumer key. The response carries `expires_in` (2008 s in the docs, 1472 s in SERPRO's own .NET sample, so treat it as short-lived), `token_type`, an `access_token` which is itself an RS256 JWT, and a `jwt_token` which is a second, differently-signed JWT. There is no refresh token: the gateway answers `401` and the client repeats the same call.

**The envelope is fixed, the operation is a path segment, and `dados` is a string inside a string.** The five paths are `/Apoiar`, `/Consultar`, `/Declarar`, `/Emitir`, `/Monitorar`, all POST. The body is:

```json
{
  "contratante":      { "numero": "…", "tipo": 2 },
  "autorPedidoDados": { "numero": "…", "tipo": 2 },
  "contribuinte":     { "numero": "…", "tipo": 2 },
  "pedidoDados": {
    "idSistema": "PGDASD",
    "idServico": "CONSEXTRATO16",
    "versaoSistema": "1.0",
    "dados": "{ \"numeroDas\": \"…\" }"
  }
}
```

`dados` is documented as an "escaped string" holding the service's JSON, so the client encodes it with `json_encode` and the mapper decodes it twice. The response mirrors the envelope and adds `status`, a `dados` string, a `mensagens` array of `{codigo, texto}`, and a `responseId`. Errors from the gateway itself use a different shape entirely — `{"code":"900807","message":"…","description":"…"}` — with no `mensagens` array, and an error response may null the whole envelope. Field naming is camelCase in some services and PascalCase in others, and `tipo` has been observed returning as both the integer `2` and the string `"2"`. None of it can be trusted to a single convention.

**The contract is bound to one CNPJ and one e-CNPJ, and acting for anyone else requires a signed authorization term.** `AcessoNegado-ICGERENCIADOR-016` returns 403 when the CNPJ in `contratante.numero` differs from the CNPJ extracted from the certificate — a fatal data error, not a retryable one. `AcessoNegado-ICGERENCIADOR-019` returns 403 when `autorPedidoDados` is not the contratante, stating that the termo de autorização signed by the procurador is required. `AcessoNegado-ICGERENCIADOR-054` returns 403 when the `autorPedidoDados` is not who signed the termo. Reading those three together: the contratante is the platform, and the `autorPedidoDatos` is the office that signed the term. The provider's own wording supports this — it describes the term's signatory as the *procurador* and gives "escritórios de contabilidade" as the example, so the office is the signatory, not the individual client. That also explains why `-054` complains when the requester is not the signatory: the requester has to be the office. It is inferred from the error codes and one parenthetical rather than stated in a single place, so it is the first thing to confirm against a real contract, and it is consistent with the `X-Request-Tag` format, which carries the author and the subject as separate fields.

**The authorization term carries the platform's own signature over the office's identity.** The documentation states that requesters who contracted the product but lack the contributor's procuração — naming software-houses explicitly — must send an XML document signed by the *procurador's* certificate, through `AUTENTICAPROCURADOR.ENVIOXMLASSINADO81` on `/Apoiar`, and it gives "escritórios de contabilidade" as the example of a procurador. The XML names the contratante as `destinatario`, the signing standard is W3C XMLDSig with RSA-SHA256, and re-submitting a term still in the `validado/autenticado` state returns `304` with the token in the `ETag`. Since the office supplies the certificate and the platform does the signing, the office authorizes once and then takes no further action. The response is a `autenticar_procurador_token` — a 36-character UUID — valid until midnight the following day in `America/Sao_Paulo`. Critically, re-sending a term that is still in the `validado/autenticado` state returns `304 Not Modified` with the token in the `ETag`, so the term does not need to be re-signed or re-uploaded. A documented historical alias is that the requester must be the signatory, which is what makes `-054` an error if the roles are confused.

**Costs, limits and failure modes are all documented and mostly favourable.** `/Apoiar` and `/Monitorar` calls are free; only `200`, `202` and `403` are billed, so failures are not charged and a `504` is explicitly not billed. The gateway caps a synchronous response at 30 seconds with per-service budgets of 3–8 s low, 10–20 s medium and 20–28 s high complexity. Exceeding it returns `504` with an error code ending `058`, and the documentation is explicit that a `504` does not guarantee the operation was not completed — late persistence is possible and the prescribed response is to wait and check status rather than resend. Repeated timeouts trip a circuit breaker that puts the endpoint in `SUSPENDED`. Separately, `PGMEI` returns `Erro-PGMEI-MSG_23099` instructing the client to send one request at a time per CNPJ and avoid parallelism, so serialization is a correctness requirement and not only a politeness. The only numeric rate limits published anywhere are on `EVENTOSATUALIZACAO`: 1000 requests per day per event type, batches of up to 1000 contributors, a 20-minute TTL, and a destructive read — a successful poll deletes the result, so a failure to persist after a `200` loses the data permanently.

**A public trial environment exists and works.** The official trial OpenAPI document publishes its bearer token in the spec's own description, and the trial requires no certificate and no purchase. Live calls to `REGIMEAPURACAO`, `PGDASD`, `DTE` and `SITFIS` all returned `200` from a non-Brazilian IP, with the `REGIMEAPURACAO` response byte-identical to the documented example. Three caveats make it unsuitable as a source of truth: the trial is a mock that overwrites the identity fields with the scenario page's own numbers and returns `SITFIS` complete immediately, skipping the `202`/`204` polling state machine; its quota is global and shared by everyone experimenting, so bursts throttle heavily; and it does not enforce `jwt_token`. It can validate the transport, envelope and parsing layers and nothing else.

**The published OpenAPI is nearly empty.** Both the production and trial specs are downloadable and describe the same five paths with a complete request schema, but every `200` response is `content: {}` and errors are typed as RFC-7807 `ProblemDetails`, which is not the shape the gateway actually returns. The spec also declares `x-throttling-tier: Unlimited`, which the observed trial `429`s contradict.

## Goals / Non-Goals

**Goals:**

- One platform-level Serpro contract, held and used by the opmoni, serving every Account.
- Per-office authorization that the platform produces, signs, submits and renews on its own, so the office
  authorizes once and then never acts again.
- A durable, inspectable record of every synchronization run and every call it made, surviving worker restarts and container recreation.
- Per-client, per-service cost attribution, so one platform contract can be reconciled against the offices that caused the spend.
- A client that keeps secrets out of the database in plaintext, out of responses and out of logs.
- A monitoring view whose numbers come from real synchronized data, with an explicit "not covered" state instead of a fabricated regular.
- Conventions for authenticated outbound HTTP that the next integration can copy.

**Non-Goals:**

- Any Receita Federal write: emitting or transmitting declarations, payment emission, MIT closing. This change only reads.
- Building our own XML digital signature primitive. The provider publishes an official PHP component for exactly this and we vendor it rather than hand-rolling cryptography (D18).
- Webhooks and `EVENTOSATUALIZACAO`, which is a push model and whose destructive read is a data-loss trap.
- Syncing individual clients; most read-only services accept `tipo` 2 only.
- A generated OpenAPI client, on the evidence above.
- Reworking the existing per-client A1 feature, which has the same ephemeral-disk problem but is out of scope.

## Decisions

### D1. Platform secrets live in the database, encrypted, not on disk

The consumer key, consumer secret, the platform e-CNPJ and its password live in a single `serpro_connections` row, with the secret and certificate bytes passed through `Crypt::encryptString` and decrypted only inside the client call.

The reason is durability. `ClientCertificateVault` writes certificate bytes to the `certificates` disk, and the root `AGENTS.md` records that in production the Laravel container has no volume, so PFX/P12 files vanish whenever the service is recreated. A per-client certificate losing its file is recoverable — the member re-uploads it. A *platform* Serpro certificate losing its file silently breaks the integration for every Account, and nobody can re-upload it because it is not the company's to hold in the first place. The database has transactional semantics and a backup story; the ephemeral disk does not.

`.env` was rejected: it cannot be rotated without a redeploy, cannot hold the binary certificate, and would make the connectivity check meaningless. `Account.settings` is explicitly not the home for the secret — it is cast `'array'` and holds plaintext JSON. Per-Account non-sensitive configuration (the enablement flag, which services are on) goes there.

### D2. The certificate is materialized per call and presented on both endpoints

Guzzle's `cert`/`ssl_key` options need filesystem paths; `php://` and `data://` streams are not accepted by the TLS layer. Each authenticated call decrypts the PFX to `storage/app/private/serpro-tmp/{uuid}.pfx` with `0600`, passes it via `withOptions(['cert' => …, 'ssl_key' => …, 'passphrase' => …])`, and unlinks it in a `finally` regardless of outcome, zeroing the password in memory alongside — following `ClientCertificateVault` and `ClientCertificateController`.

The certificate goes on **both** the `/authenticate` call and every gateway call. The documentation requires it on `/authenticate` and its gateway example omits it, but both the Dart and the Python community SDKs attach the client certificate to every gateway POST. Attaching it twice costs nothing and removes the risk of discovering at deploy time — with one shared credential, indistinguishable from a wrong password — that the gateway also demands it.

A long-lived decrypted file on disk was rejected because it widens the window in which an unencrypted private key exists.

### D3. The envelope is assembled per call, with `contratante`, `autorPedidoDados` and `contribuinte` as distinct roles

One `final class` in `App\Services` owns transport, authentication and the envelope. The signature carries all three identities because they are not interchangeable in this model:

- `contratante` is the platform, from configuration, and must equal the CNPJ in the certificate or the gateway answers `-016`.
- `autorPedidoDados` is the office whose authorization term the platform signed and holds.
- `contribuinte` is the client being queried, which for a read is usually the same party.

Four details are pinned by tests because getting them wrong fails quietly:

- **`dados` is a JSON string, not a nested object.** Encoded on the way out, decoded twice on the way in.
- **The operation is a path segment.** `Consultar` is not a field; building `.../v1` with a body flag sends the call nowhere useful.
- **`versaoSistema` is per service and inconsistent.** `PROCURACOES.OBTERPROCURACAO41` is `"1"`, `SITFIS` is documented as `"2.0"`, the trial mock accepts `"1.0"`. It is configuration keyed by `idServico` and is never hardcoded.
- **`tipo` may arrive as an int or a string**, and the provider's own example echoes `idServico` with a trailing space, so the echoed values are never used as keys or parsed as types.

Response mapping is **not** done in the client. Each service gets its own mapper returning a fixed key set, following `CnpjWsLookup` and `ClientManager`'s `OFFICIAL_FIELDS` allow-list. Failures raise a `SerproException` carrying the provider code, the HTTP status and a readable message, and the ICGERENCIADOR code table is mapped to a retry class in exactly one place.

### D4. Error codes drive a retry decision, and only three classes retry

The provider publishes a complete `ICGERENCIADOR` code table, which converts "handle errors" from guesswork into a lookup. The mapping is:

- **Re-authenticate once** — `401`, and `403` with `-003`, `-004`, `-005`, `-013`, `-025`, `-026`, `-037`, `-038`, `-041`. All are token problems.
- **Re-submit the authorization term once** — `403` with `-020` or `-042`, meaning the `autenticar_procurador_token` is missing, expired or malformed.
- **Never retry, surface to the user** — `403` with `-016` (certificate CNPJ mismatch), `-018`, `-019` (term required), `-022` (no e-CAC procuração for the contributor), `-053`, `-054`; and `400` with `-006` through `-012`, `-040`, `-043`, `-046` through `-052`. These are data, permission or configuration faults. Retrying them burns quota and hides the cause.
- **Retry with backoff** — `429`, `500`, `503`.

`-022` is the one that shapes the product: "o autor do pedido de dados não possui procuração outorgada no e-CAC para o contribuinte" is a business fact about the client, not an error, and the UI must present it as such rather than as a failed call.

### D5. Authorization is modelled per (client, service family) and only observed

`client_ecac_powers_of_attorney` gains the Serpro procuração code and an integration state, and a join table records which service family each client's procuração covers. A client is eligible for a read-only service only when the corresponding row is `established`.

The state is **read**, never written by us, from `PROCURACOES.OBTERPROCURACAO41` — which is itself one of the services marked as not requiring a procuração, so the product can always ask, including to discover that it never had one. This is the permission oracle: it returns, per client, the expiry date and the list of RFB systems the client has actually granted, and every other service's `-022` is explained by it.

Modelling per family rather than as one boolean comes from the concrete codes: `REGIMEAPURACAO` `00060`, `SITFIS` `00002`, `CAIXAPOSTAL` `00006`, `PGDASD` `00146`, `DTE` `00050`, `PAGTOWEB` `00004`, and `PARCSN`, `PERTSN`, `RELPSN` each requiring two. A client can be authorized for one family and not another, and a single flag would misreport coverage in both directions. `E-PROCESSO` and `PNRCONTADOR` are absent from the provider's table, which is a gap in the documentation rather than a documented exemption, and neither is in the first sync.

### D6. The authorization term is per office, and the platform signs it itself

The term is **one per office, not one per client.** The provider's own wording settles this: the term is signed by the *procurador*, and the documentation's parenthetical for that role is "escritórios de contabilidade". So an office signs once, authorizing the platform to act on behalf of that office's clients. It is not re-signed per client, and the office does nothing again afterwards.

The platform does the signing, using the office's own e-CNPJ. The flow is: the office uploads its certificate once; the platform builds the `termoDeAutorizacao` document naming the office as `destinatario` and itself as `contratante`, signs it, and submits it to `AUTENTICAPROCURADOR.ENVIOXMLASSINADO81` on `/Apoiar`, which is free; the response carries an `autenticar_procurador_token` valid until midnight the following day in `America/Sao_Paulo`; and that token is sent as a header on every call for that office's clients.

Renewal is the part that makes this automatic rather than a chore. Re-sending a term still in the `validado/autenticado` state returns `304` with the token in the `ETag`, so the daily refresh is a re-POST of the term already on file — no re-signing, no user action, no office involvement. The signed document is kept verbatim for precisely this reason; regenerating it would be the expensive mistake.

This is what resolves the role puzzle. The three identities in the envelope are distinct because the three parties are: `contratante` is the platform, whose document must match the platform certificate or the gateway answers `-016`; `autorPedidoDados` is the office, and it is the office that signed the term, which is what `-054` requires; `contribuinte` is the client. The `-019` refusal — author is not the contratante, a term is required — is satisfied by the term existing at all. And `-022`, the client having no e-CAC procuração, remains a separate per-client gate that this term does not bypass.

Two consequences shape the model. The term carries its own `vigencia`, and when it lapses only the office can renew it, so the product must name that action rather than reporting a generic failure. And the token is per office, not per client, which is why it is a property of the office's connection rather than of a run item.

### D7. Runs are durable rows; per-client work is one idempotent job

`serpro_sync_runs` and `serpro_sync_run_items` are database tables, not `Cache::put`. The existing `DeleteClientsJob` caches its result for two hours, which is right for a one-shot bulk delete and wrong here: the requirement is a history an office can look back at, and a Redis eviction or a worker restart must not erase a run. The run is created synchronously in `queued`; a fan-out job dispatches one child job per client.

The child job is idempotent through a unique constraint on `(run_id, client_id)`, so a duplicate delivery — which the existing `timeout: 300` against Redis `retry_after: 90` makes possible — updates rather than duplicates. It re-checks authorization and the term inside `handle()` rather than trusting the fan-out, following `DeleteClientsJob`, which re-validates the user's role after crossing the queue boundary. Its `timeout` is set below `retry_after` and it has a backoff; the existing mismatch is a latent bug that this change flags rather than silently fixing in an unrelated job.

### D8. Calls are serialized per client, and a `504` is an indeterminate state

Two provider constraints shape the job. `Erro-PGMEI-MSG_23099` instructs one request at a time per CNPJ and forbids parallelism, so the per-client job acquires a lock keyed on the client before calling anything — this is a correctness requirement, not merely a rate limit. And a `504` does not mean the operation failed: the documentation warns that the backend may have completed with late persistence and that the prescribed response is to check status rather than resend, and repeated timeouts promote the endpoint to `SUSPENDED` on a gateway that is shared by every office.

So a `504` is recorded as a distinct non-terminal outcome, never retried inside the same run, and never counted as a client failure. The run continues with the remaining clients; a later run is what retries it. The `401` case is deliberately different and does retry, because re-authenticating once and replaying once is unambiguous. Because this change only reads, "check status before resending" is trivially satisfiable — a read has no side effect to duplicate — which is a further argument for the read-only scope.

### D9. Every call carries an `X-Request-Tag` identifying the office's client and service

Every gateway call sends `X-Request-Tag` in the documented 32-character shape: the author's type and number, the subject's type and number, and a two-digit service sequence. The generated tag is persisted on the call record alongside the provider's `responseId` and `mensagens`.

This is not instrumentation for its own sake. One platform contract serving many offices produces a single bill, and without a tag there is no way to attribute cost to the office that caused it — which makes the shared-contract model commercially unviable the moment a second office appears. With it, the consumption report's `TAG` column splits by client and by service and reconciles against our own runs. The field is free text with no server-side validation, so the format is a convention we impose on ourselves and must keep stable: it is built by one function, with a test pinning the exact 32 characters.

The `responseId` is captured for the same reason — the timeout policy tells clients to quote it to support.

### D10. The integration is enabled per Account, not per platform

Each Account carries an explicit enabled flag, writable only by `admin`, and no client of a disabled Account is ever dispatched. The platform credential being shared by everyone is precisely the argument for a per-Account switch: a revoked credential, a Serpro outage, a suspended endpoint or a lapsed platform certificate should not reach every office at once, and turning an office off is the fastest containment available.

Disabling keeps recorded runs and synchronized data readable — it stops new work rather than erasing history — and a disabled office reports itself as not enabled rather than as failing.

### D11. The first sync covers five read-only queries across four procuração codes

The initial set is `PROCURACOES/OBTERPROCURACAO41` (no procuração required, so it always runs and establishes what authorization exists at all), `REGIMEAPURACAO/CONSULTARANOSCALENDARIOS102` (`00060`), `SITFIS/RELATORIOSITFIS92` (`00002`), `CAIXAPOSTAL/MSGCONTRIBUINTE61` (`00006`) and `PGDASD/CONSEXTRATO13` (`00146`).

The set proves the model rather than maximizing coverage. Each service after the first exercises a different e-CAC procuração code, so together they exercise per-family authorization end to end, and the one that needs no procuração proves the ineligible path needs no special case. `SITFIS` and `CAIXAPOSTAL` are also the two families the existing monitoring registry already names, so the first sync lands on screens that already exist. `PARCSN` is excluded because it needs two codes at once and would complicate the office's setup instructions without adding a new idea.

**Collection-slip status is derived, not fetched separately.** `PGDASD.CONSDECLARACAO13` already returns, per assessment period, an `indiceDas` carrying the slip number, its issue timestamp and a `dasPago` flag, alongside the declaration number and transmission timestamp. Whether a guide was issued, when it falls due and whether it was paid is therefore already in hand, and the maintenance view is a projection over data the first sync brings. `PGTOWEB` is the enrichment that confirms payment against the collection record rather than the declaration, and it needs its own procuração code, so it is deliberately a follow-up rather than part of this set. Adding it to the first sync would buy a second paid service and a fifth setup step for the office without demonstrating anything the other four do not.

`SITFIS` is the reference implementation for the status engine: a two-step `/Apoiar` then `/Emitir` pair where the first step is free, and where the second answers `200` ready, `202` with a `tempoEspera` while processing, `204` with the wait in an `ETag` after the window elapses, `304` while the protocol is still valid, and `503` with a wait on RFB backlog. The protocol is valid only on the day it was requested. The trial mock skips this state machine entirely, so it is implemented from the documentation and unit-tested against recorded fixtures — never validated live.

### D12. No generated client; a hand-written client over the Laravel `Http` facade

The official spec has a complete request schema and no response schemas at all, so a generated client would type every return as `Object`. Per-service shapes exist only as MkDocs prose. `backend/AGENTS.md` forbids changing dependencies without approval, and `Http` is already present and already wrapped by `Http::fake()`. The five paths are twenty lines by hand; the ~119 services' payloads are hand-transcribed per mapper either way.

### D13. The client set is configuration; the per-service timeout is not published

A `config/integra-contador.php` holds the authentication host, separate gateway bases for trial and production, the fixed `contratante`, and a service map keyed by `idServico` carrying its path, its `versaoSistema` and whether it is billable. This follows the `config/clients.php` precedent.

Timeout budgets are configuration with a conservative default below the 30-second cap, because the provider publishes only complexity bands and states the real per-service values are operational parameters held in its management console. `/Apoiar` and `/Monitorar` are marked free in the same map so the cost of a call is knowable before making it.

### D14. The monitoring view becomes server-driven; the static registry keeps its shape

`monitoringNav.ts` keeps its role as a route registry but loses the ten sample companies and the `statusCycle[(id + pageIndex) % 4]` derivation. `monitoringAttentionCount` becomes a count returned by the backend, because it cannot be computed client-side once the data is server-owned. `MonitoringSheet.vue` becomes a paged, server-filtered list following the `work/processos.vue` ladder and `DataTableFilter`.

The new screens are not sheet pages. They are static siblings under `app/pages/monitoring/`, which take routing precedence over the `[...slug].vue` catch-all, and they are registered in `monitoringGroups` so the sidebar and the overview reach them — avoiding a second navigation concept next to `workNav` and `adminNav`.

### D15. Routes follow the existing unversioned, tenant-scoped group

Endpoints are registered inside the existing `['auth:sanctum', 'tenant']` group in `routes/api.php` as `serpro/connection`, `serpro/connectivity`, `serpro/authorization-terms` and a `serpro/sync-runs` resource. `backend/AGENTS.md` defers API versioning to existing convention, and the existing convention is unversioned.

### D16. Transport is proven against the trial; behaviour is proven against fixtures

The trial is public, needs no certificate and works from any IP, which means the envelope, the double-encoded `dados`, the header set, the `mensagens` array, the gateway-versus-application error shapes and the `429` behaviour can all be exercised today against the real provider. Its quota is global and heavily contended, so it is not a place to run a suite: contract tests hit it only when a token is configured, treat `429` as a skip rather than a failure, and are excluded from the default run. Every response they observe is recorded as a fixture, and the deterministic suite runs entirely from those fixtures.

The distinction matters because the trial is a mock: it overwrites identity fields with the scenario page's own numbers, returns `SITFIS` complete immediately, and does not enforce `jwt_token`. It can therefore validate the transport layer and nothing about async state machines, `jwt_token` enforcement, or real procuração semantics. Those are covered by fixtures recorded from the documentation, not by the live call.

### D17. Documents are text everywhere, and there is no numeric column to get wrong

The provider requires `numero` to be treated as text in every layer, explicitly warning against restricting it to `[0-9]+`, because alphanumeric CNPJs arrive from July 2026 under RFB IN 2.119/2022. The trial's own scenario documents include alphanumeric examples. Any column holding `numero`, and any validation of it, must therefore accept `[A-Za-z0-9]+` and must not be a numeric type; the existing `ValidCnpj` rule and the `tax_id` column are checked for this during implementation. `tipo` is stored as a string for the same reason — the provider returns it as both an int and a string.

### D18. The office certificate reuses the existing vault, and signing reuses the provider's component

Two pieces of existing machinery carry this, and one piece of third-party code.

**Storage.** `ClientCertificateVault` already does everything needed — `openssl_pkcs12_read` to validate, `openssl_x509_parse` for non-secret metadata, `Crypt::encryptString` on a private disk, a tenant-scoped path, and zeroing the password in a `finally`. What it cannot do is store an office certificate, because it is bound to a `Client` and its path is `account_id/client_id/uuid.enc`. So the parsing, encryption and password-hygiene logic is extracted into a shared unit and the existing client vault and the new office vault become thin callers of it. That is a targeted refactor of working code rather than a second parallel implementation, and it is the only pre-existing file this change restructures.

**Signing.** The provider publishes `Serpro.Componentes.AssinadorDigital.php` — MIT, PHP 8+, OpenSSL only, no framework, and a subset of XMLDSig rather than a general library. It is vendored into `app/Support/` and wrapped, not added through composer, and `backend/AGENTS.md`'s prohibition on changing dependencies is honoured because nothing in `composer.json` moves. Wrapping it also gives one place to strip invisible Unicode before signing, which the provider warns causes `AcessoNegado-AUTENTICAPROCURADOR-013`, and one place to keep the signed document out of logs.

The alternative was hand-rolling XMLDSig on top of `ext-openssl`. It was rejected: the standard is W3C XMLDSig with RSA-SHA256, an enveloped `Reference URI=""` and specific transforms, and it has to interoperate with a government validator. When a vendor publishes the reference implementation of their own validator's expected format, using it is the lower-risk choice, and it is MIT.

## Risks / Trade-offs

- **A suspended endpoint has a platform-wide blast radius.** The circuit breaker state is per endpoint but the credential is per platform, so `SUSPENDED` stops every office. This is the sharpest consequence of the shared contract. → D8 forbids hot retries, D10 gives an immediate per-office off switch, and the run reason distinguishes a provider suspension from a client failure.
- **The `autorPedidoDados` role assignment is inferred, not documented in one place.** It comes from reading `-019` and `-054` together, and it is the difference between a working integration and a wall of `403`s. → It is the first thing verified against a real contract, it is isolated in a single function with its own tests, and the error mapper classifies `-019` and `-054` as "term problem, do not retry" so a wrong guess surfaces as a clear, non-retrying failure rather than a silent one.
- **The office's certificate becomes a platform-wide secret.** The platform now holds a signing key per office, which is a larger blast radius than a read-only consumer key: it can sign a term on that office's behalf. → The certificate is stored with the same vault discipline as client certificates, the password is zeroed after use, the signed document never leaves the backend, and removal deletes the encrypted contents while keeping non-secret metadata for audit. A compromised office certificate is a credential incident, and it is treated as one.
- **Signing is a correctness surface, not a formatting one.** An enveloped XMLDSig that is subtly wrong fails at the provider with an opaque code, and the provider specifically warns that invisible Unicode characters cause `AcessoNegado-AUTENTICAPROCURADOR-013`. → We vendor the provider's own component rather than writing the primitive, wrap it in one place that normalises the document before signing, and assert the generated document's structure in a test so a regression is caught locally instead of at the gateway.
- **A single office certificate authenticates every one of its clients.** One compromised certificate would reach every client of that office. → Mitigated rather than eliminated: the e-CAC procuração gate still applies per client and per service family, so a stolen certificate alone does not unlock a family the clients never granted, and the audit trail records the office on every call via the `autorPedidoDados` role and the request tag.
- **Terms lapse and tokens expire at midnight daily.** The term's own `vigencia` can end, and only the office can renew it. → The daily token refresh is a `304` re-POST and needs no user action; the validity is surfaced with a warning window naming the office as the party who must act.
- **Per-service timeouts are unpublished.** → Budgets are configuration with a conservative default; per-service tuning waits for a contract that exposes the management console.
- **The trial validates less than it appears to.** → D16 states precisely what the trial can and cannot prove, and the async state machine in particular is built from documentation and fixture-tested.
- **Two services are absent from the procuração table.** → Neither is in the first sync; adding one is a decision about undocumented families, not a code change.
- **Provider credentials are unverified end to end.** The endpoints, envelope, authentication and error taxonomy are documented and partly executed, but no call has been made from this codebase and no contract exists in the repository. → The connectivity check makes this a visible, testable state, and D2 covers the one genuinely ambiguous point by precaution.
- **D1 diverges from the existing certificate feature.** The per-client A1 stays on disk and keeps its ephemerality in production. → Deliberate and documented; aligning it is a separate change, flagged rather than smuggled in.
- **D7 does not fix the existing `retry_after` mismatch.** → Called out as a follow-up; changing an unrelated job's timeout would make the diff harder to reason about.
- **No static analysis.** There is no PHPStan or Larastan, so a PHPDoc array shape that misdescribes a provider payload will not be caught — and the string-encoded `dados` field is proof that provider payloads are exactly where a wrong assumption hides. → Tests assert the exact stored key set, following `ClientCnpjLookupTest`'s `assertArrayNotHasKey` discipline.
- **Queued jobs have no test coverage anywhere in the repository.** `QUEUE_CONNECTION=sync` means a dispatched job runs inline and is invisible to the suite. → The child job is tested through idempotency and tenant hydration rather than queue assertions; the debt is named rather than claimed as paid.
- **Replacing the monitoring mock touches many surfaces.** → The state vocabulary is unchanged by the specs, so labels and URL shapes stay stable.
- **Runs are readable by a `user`-role member.** → Accepted: writes stay restricted to `admin` and `operador` and the enablement flag to `admin`.

## Migration Plan

1. Additive migrations only: create `serpro_connections`, `account_certificates`, `serpro_authorization_terms`, `serpro_sync_runs`, `serpro_sync_run_items` and the per-client service authorization table; extend `serpro_monitorings` and `client_ecac_powers_of_attorney` with nullable columns. Nothing is dropped or retyped, so every step reverses by dropping the new tables.
2. The existing `serpro_monitorings` rows carry only a `name` and no client. They are removed rather than migrated — there is no client to attach them to, and the feature they anticipated is the one being built.
3. The integration is inert until a connection row exists. With no row, the connectivity check reports not configured, the sync endpoint refuses to create a run, and the monitoring views fall back to empty states — so the frontend deploys before any credential is provisioned.
4. Office-side setup, in order: opmoni's platform consumer key and e-CNPJ; each enabled office uploads its own e-CNPJ once, from which the platform builds and signs its authorization term; each client of that office grants the e-CAC procurações `00060`, `00002`, `00006` and `00146`. After the certificate upload the office takes no further action. All three layers are surfaced in the product rather than buried in documentation.
5. Deploy order follows the root `AGENTS.md` production rules: build both images, apply migrations once by hand via the `migrate` service, then `docker stack deploy`. No migration runs in an entrypoint or the worker.
6. Rollback is dropping the new tables and redeploying; the previous release's own specs describe its sample-data monitoring view, so the rollback is internally consistent.
7. No `APP_KEY` rotation is involved, and none may be performed, since it is what encrypts the stored credential.

## Open Questions

- Whether the per-service authorization rows are seeded for the full catalog or only for the five services in D11. A data-seeding decision inside D5, not a change to approach or specs.
- Whether an expiring procuração or term escalates beyond a warning. The current requirements only report the approach, and no product action depends on the answer yet.
- Whether the per-office term also needs to name each contributor explicitly, or whether the office's single term covers every client it holds. The documentation describes the term as authorizing requests in the author's name, which suggests the contributor travels in the envelope rather than in the document, but this is worth confirming with a contract because it decides whether one term per office is genuinely enough.
- Whether payment confirmation should come from `PGTOWEB` on top of the `dasPago` flag the declaration already carries, and whether that is worth a fifth e-CAC procuração code for the office. The derivation is sufficient for a maintenance view; the collection record is the authoritative one for reconciliation.
- Whether a per-office spending cap, as the SCI proxy offers, belongs in the product. The `X-Request-Tag` in D9 is the prerequisite for it, but the feature itself is a separate decision.
