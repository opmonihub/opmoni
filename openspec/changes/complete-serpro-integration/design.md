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

**The office certificate is stored encrypted in the database, not on disk, and this replaces the
earlier `storage_path` decision for the office vault.** The same discipline as the client vault —
`openssl_pkcs12_read` to validate, `openssl_x509_parse` for non-secret metadata, the repository's
encrypt-then-base64 convention (`Crypt::encryptString(base64_encode($bytes))` to write,
`base64_decode(Crypt::decryptString(...), true)` to read) — but the bytes live in
`account_certificates.certificate_encrypted`, with the password in `password_encrypted` beside it,
and **there is no path column**. The reason is deployment, not preference: the Laravel container's
filesystem is ephemeral in production, so a file-based office certificate would vanish on every
recreate and the office would find itself re-authorizing after a deploy. `ClientCertificateVault`
keeps its disk because the client certificates that shipped with it are not being moved, and this
is the one pre-existing file this change restructures. The upload semantics are unchanged by the
move: one certificate per Account, replaced or removed, with non-secret metadata retained for audit
and the encrypted contents deleted.

`APP_KEY` is what encrypts these columns, so **no rotation may ever be performed** — same as
already true for the platform credential.

**Signing is the provider's own component, isolated rather than vendored verbatim, and its
provenance is recorded because it is third-party code that must not be trusted on its face.**

| | |
| --- | --- |
| Source | [modelo de assinador digital PHP](https://apicenter.estaleiro.serpro.gov.br/documentacao/api-integra-contador/pt/modelos/modelo_de_assinador_digital_php/), published by SERPRO |
| Artifact | `Serpro.Componentes.AssinadorDigital.php.zip`, version `1.0.0` |
| SHA-256 of the ZIP | `6e139b207527047e9e66e9228c7c1ea6ea444b1b1a936f5f02f4d936a9e0c72b`, reconfirmed 2026-09-28 before integrating |
| License | MIT (`LICENSE` inside the ZIP, `Copyright (c) 2022 SERPRO`), itself based on `XMLDSIG for PHP` (<https://github.com/selective-php/xmldsig>) |

The SHA is of the ZIP, not of the script: the ZIP is what the documentation links, and it is what
carries the license. The provider's own note calls the model a basic example to orient an
implementation and says complete tests are essential before production — which is why the checksum
is re-verified on the day of integration and why the difference between the reference model and
the isolated routine is written down rather than assumed away.

**The distributed model is inspected, never executed, and it does not run as shipped.** The script
does not pass `php -l`: it aborts with `Parse error: Unclosed '{' on line 25 does not match ')' ...
line 50`, caused by one parêntese too many in the `vigencia` line. It also defines six global
functions and reads thirteen `$GLOBALS` entries, prints the signed document and its base64 through
two `echo`, changes the whole process timezone with `date_default_timezone_set('America/Sao_Paulo')`
on line 2, reads the PFX from a filesystem path, ignores the return of `openssl_pkcs12_read`, calls
`date()` with three arguments where PHP accepts two, and throws `XmlSignerException` — a class
**the ZIP never declares**.

So `app/Support/SerproSigner.php` is an independently written routine that ports **only** the
XMLDSig sequence of the model's `assinar()`, and it is not a copy of the file. Taken unchanged:
the `C14N` canonicalization, the SHA-256 digest, `SignedInfo`, `Reference URI=""` with the
`enveloped-signature` transform followed by `c14n`, RSA-SHA256, `KeyInfo/X509Data/X509Certificate`,
and the order in which the digest is taken before the `Signature` exists. Rewritten or removed:
every global and `$GLOBALS` read, the timezone mutation, the path-based PFX loading, both `echo`,
the unverified `loadXML` and `openssl_pkcs12_read` returns, and the undeclared exception class —
which is now `SerproException` with `SerproFailure::NotSent`, because a signing failure is local and
nothing was sent. No global function and no `$GLOBALS` entry from the official file reaches the
application, and the provenance test asserts that those six function names do not exist.

**The term document builder was not vendored, and of its three oddities one is corrected and two
are kept on purpose.** Building the document is `SerproTermSigner`'s job, and three things in the
model look like defects: `addChild('finalidade ')` with a trailing space in the element name,
`date('Ymd', '+30 days', …)` where the model passes a string where a timestamp belongs and a third
argument `date()` does not take, and a digest computed with exclusive `C14N` while the `Reference`
declares the inclusive `c14n` of REC 2001.

**The trailing space is corrected, because it cannot survive into the signed document.** The space
does exist in the model's own serialised string, so this is not a claim that XML forbids it in
principle; it is a claim about what reaches the signature. This reverses an earlier decision in this
document, which kept the space on the reasoning that the model is the only authority available. That
reasoning does not reach this case, and the measurement is unambiguous:
- `DOMDocument::createElement('finalidade ')` throws `DOMException: Invalid Character Error` — the
  name cannot be constructed;
- the model's own path, `SimpleXMLElement::addChild('finalidade ')`, does **not** throw, and that is
  what makes the case worth stating: it emits `<finalidade  texto="…"/>` with the space, and libxml
  accepts that string, so it looks like the space survived. It did not. The resulting `nodeName` is
  `finalidade` without the space, because the parser consumes it as inter-tag whitespace;
- the `loadXML`/`saveXML` pair that the signing routine itself performs **removes** the space, so a
  term built that way would be signed into `<finalidade texto="…"/>`;
- a name with a space is unreachable by XPath, where `local-name()='finalidade '` matches zero nodes.

So the space is dropped because it does not survive signing, **not because it was judged
unimportant**, and the element is named `finalidade`. The distinction is recorded because the failure
it invites is a later reader "restoring fidelity to the model" and getting a `DOMException`, or worse
a document that silently never had the space. The name itself is what remains unverified and gated,
like the other two.
**The provider's term documentation is reachable, and that changes the record — read on
2026-09-28.** The statement that it "returns `500` on every plausible URL and ships no XSD" was true
when this decision was written and **no longer is**: `…/autenticaprocurador/padroes_tecnicos_assinatura_xml/`
and `…/autenticaprocurador/servicos/envio_de_xml_assinado/` both answer `200` and carry the layout
table, the technical signature standards, the `304` cache contract and a full request/response
example. Three of the contested points are therefore **documented rather than inferred**, and the
code that was already built for them is confirmed rather than changed:

- the layout table names the element **`finalidade`**, with no trailing space — the correction of the
  model's `finalidade ` is the provider's own, not a tolerance of ours;
- the `CanonicalizationMethod` and the `c14n` transform are both
  `http://www.w3.org/TR/2001/REC-xml-c14n-20010315`, the **inclusive** form the `Reference` declares —
  the divergence with the exclusive digest is preserved because the provider documents the inclusive
  side and the two coincide for a document that declares no namespace;
- the published example puts **SERPRO — the contracting platform — in `destinatario`** with the role
  `contratante`, and the signing office in `assinadoPor`. That is the reading this system builds, and
  it settles the conflict the plan had with the spec's "with the office as the recipient".

**What the documentation does not settle is the validity period, and this is now the honest
statement of why the `+30 days` is kept.** The provider declares only that `vigencia` is "a data de
validade deste termo de autorização, no formato AAAAMMDD" — a format, no number of days, and no XSD.
Its own two examples run far longer: the layout example spans `20220614` → `20221231` (**200 days**)
and the service example `20220808` → `20221231` (**145 days**). Both end on the same date, which is
what samples written by hand look like and not what a rule looks like: if the period were a constant
of N days, the two examples would end on different dates. So the examples **contradict** 30 days and
do **not** replace it with a better number. `PERIODO_VIGENCIA_DAYS = 30` stays because it is the only
arithmetic of a period anywhere in the provider's material — the reference model's `+30 days` — and
because choosing 145 or 200 would be picking one of two contradictory samples as a rule, against a
schema we cannot see. It is recorded as **unconfirmed**, and the contract test of `tasks.md` 4.6a is
what has to settle it before any proof is recorded: the constant is inside `formatDigest()`, so
changing it re-opens the gate by itself, which is the correct behaviour and also the reason not to
change it on a guess.

"Verbatim" has a precise meaning for the vigência, and getting it wrong would make the decision
unimplementable: what is preserved is the **period the reference model computes**, not a claim that
the provider specified thirty days. The `date()` call around it is not preserved — it cannot run. It
passes a string where a timestamp belongs and a third argument to a function that takes two, and it
is the same line as the parse error. `SerproTermSigner` therefore writes the period as a `Carbon`
calculation in `America/Sao_Paulo` and reproduces the model's intent, not its syntax. The same
"verbatim is not the call" distinction applies to the element name, and it is why the space is
corrected while the period is kept.

**The gate is term issuance, and it is not advisory.** Nothing may emit a term until a real contract
test against the provider proves the document is accepted, the roles are the ones the gateway
expects, and the resubmission of a still-valid term answers `304` with the token in the `ETag`. In
`tasks.md` that gate is **item 4.8**, the automatic issuance, which is where it has to bite; the
spec states it as a requirement rather than leaving it to a comment. The failure mode is deliberate:
if a kept value turns out to be wrong, the cost is that issuance stays blocked until a human obtains
a contract test. A blocked feature is recoverable; terms the provider rejects are not, and the
office has already authorized on the strength of them.

**The gate has a named predicate, so two implementers cannot read it differently.** The proof is
recorded in the platform connection row, as `serpro_connections.term_format_sha256` and
`term_format_proven_at`, and issuance is permitted only when `term_format_proven_at` is not null and
`term_format_sha256` equals `SerproTermSigner::formatDigest()`. It lives in the database rather than
in `config/integra-contador.php` for D1's reason — it is a fact about the provider that must survive
a redeploy.

**The digest's input is the template plus the format constants, and a template-only digest is a gate
that does not gate.** `SerproTermSigner::formatDigest()` hashes the canonicalized template, with
every per-office and per-term value replaced by a **fixed plain-ASCII** placeholder, **concatenated
with the format constants**: the validity period length, the canonicalization algorithm, the
invisible-Unicode normalization rule, and the timezone the term's dates are written in. The join
carries a separator or a length prefix that cannot occur in either part, because "concatenated in a
stable order" on its own leaves a template ending in the same bytes a constant begins with
ambiguous. The placeholder is ASCII for a reason that is easy to get backwards: if a template
carried invisible characters, the normalization step would change its bytes, and the digest would be
sensitive to that step for the wrong reason — the constant would appear to be covered by the
template when it is the constant that covers it. The constants are in there because measurement says
the template cannot see them. The period never appears in the document — the model writes only the
computed date, and that date is a per-term placeholder — so moving 30 days to 60 leaves the template
bytes identical and returns the same digest. The normalization step is a transformation of the
document, not a mark on the template, so removing it also leaves the template untouched. The
timezone does not appear in the document at all, and it decides something: near midnight the
calendar day `dataAssinatura` falls on is the one the timezone says it is, so changing it changes
the document the provider receives while the template stays byte-identical. Three of the four values
this gate exists to cover are therefore invisible to a template-only digest, and the
canonicalization algorithm is a fourth that happens to be harmless: for a document with no namespace
declaration the exclusive and inclusive forms are byte-identical, so the constant cannot change the
output at all. An earlier version of this paragraph claimed that "editing the document builder
changes the digest" and stopped there; that is true only of edits to the template, and the edits
that matter most are not template edits. A recorded proof that survives the edit it should have
invalidated reads as a guarantee, which is worse than having no gate, so the constants are hashed
rather than trusted to be visible.

The gate is **self-invalidating** for the same reason: change the template or any of the four
constants and the digest changes, the comparison stops matching, and issuance re-blocks with nobody
deciding to block it. A boolean is the obvious cheaper design and it is wrong here, because a
boolean cannot be invalidated by a change to the format and would keep authorizing a document nobody
tested.

**What the gate does not cover is the signature envelope, and saying so is part of the decision.** The
digest sees the term template and four constants. The provider validates the *signed* document, so a
change to the transform list, to the `Reference` URI, to the signature algorithm, or to
`SerproSigner` itself changes the bytes the provider sees and leaves `formatDigest()` untouched — the
gate re-opens for nobody. That is a real hole and it is not this gate's to close: the envelope is
covered by the provenance tests, which assert its structure and verify the signature against the
certificate's own public key. Those are a different kind of evidence — a measurement, where a contract
test is an acceptance — and conflating them is how a reader ends up believing the gate covers the
whole document when it covers the part that carries the office's data.

**The proof is written by one command, and that command is the declared exception to its own rule.**
The columns are written by no request, no job and no scheduler, and are not `Fillable` on the model
— the same protection the encrypted columns of that table have. The single sanctioned writer is the
operator-invoked `serpro:record-term-proof`, which writes both columns together and records an audit
entry naming the digest it stored. **It takes no digest as input**: the value written is the one
`formatDigest()` computes at that moment, and an operator who disagrees with it has a bug to fix, not
a flag to set. A proof that recorded the operator's assertion rather than a measurement would prove
nothing, and would in fact be worse than no gate, because it would read as evidence.

Naming the command matters more than it looks, and so does how the rule is scoped. The earlier
wording, "no automated path writes the columns and an operator records the value", left the
requirement with no satisfiable mechanism: an artisan command *is* application code, and out-of-band
SQL through tinker leaves no audit trail. The rule that dissolves it is the narrower one the spec now
states — **no request, no job, no scheduler** — because those are the paths that would write the
columns on their own initiative. A rule phrased as "no code path" would have to except the very thing
it forbids, and `tasks.md` briefly restated it that way before this round put it back in step with
the spec.

The canonicalization decision carries one more safeguard, because it is the only one of the two
preserved values that can drift without anyone touching the code. The two canonicalizations coincide
byte for byte exactly when the document carries **no namespace declaration at all**, and
`SerproSignerProvenanceTest::test_a_divergencia_de_canonicalizacao_do_modelo_nao_altera_o_digest_do_termo`
proves it by recomputing the digest the way a validator does. The real trigger is wider than "declares
a prefix of its own": exclusive and inclusive canonicalization diverge as soon as the document
carries **any** namespace declaration, used or unused, because the exclusive form renders a
declaration on the element that uses it and the inclusive form renders it where it was declared —
`<termoDeAutorizacao xmlns:ns1="urn:x"><ns1:dados/></termoDeAutorizacao>` canonicalizes
differently under the two. So the guarantee is: **the term's root element declares no namespace, and
the term is not nested inside an element that does.** That proof is about the document shape the test
uses: `SerproTermSigner`'s document does not exist yet, and the moment it is built that test has to
be pointed at the real document, because a term carrying a declaration would stop the coincidence and
the digest written into the signature would stop being the one a validator recalculates.

**What remains unproven is the interoperability with the provider's validator and the acceptance of
the term document.** The `304` resubmission and the roles are no longer in that sentence: the
provider's cache page (`…/autenticaprocurador/cache/`, read 2026-09-28) documents the whole
`304` contract — the status, the empty body, `cache-control: termo_autorizacao`, the `etag` carrying
`autenticar_procurador_token:<uuid>` and the `expires` — and the layout page documents the roles.
That is **documented, not observed**: no response from the provider has entered this repository, and
documentation is a claim about behaviour rather than an acceptance of one. None of it can be settled
by a local test, and this design document does not claim otherwise: the signature is proven to be
well-formed and cryptographically valid, not proven to be accepted. Until a contract test exists the
honest statement is that `AcessoNegado-AUTENTICAPROCURADOR-013` from invisible Unicode, the `304`
token recovery from the `ETag`, and the acceptance of the term document itself are documented
behaviour that has not been exercised end to end. The `expires` is the sharpest case: the page's
prose says the token lasts "until midnight, Brasília time" while its own example is
`Sat, 15 Oct 2022 00:00:01 GMT`, which is 21:00 the previous day in Brasília. The two contradict each
other, the code follows the example rather than reconciling them, and the contract test is what
settles it.

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
  at the provider with an opaque code. → Isolate the provider's own signing sequence with its
  provenance recorded, normalize in one place, and assert the generated document's structure and
  its cryptographic validity against the certificate's own public key locally, instead of
  discovering it at the gateway.
- **The provider's reference model is third-party code that does not run as shipped, and copying it
  would import its defects.** The distributed script does not parse, defines globals, prints the
  signed document, and throws an exception class it never declares. → Inspect it, port only the
  signing sequence into an independently written routine, keep the origin URL, version, SHA-256 and
  MIT license in the file, and assert in a test that none of its global functions exist.
- **Two oddities in the model are kept verbatim, and by 2026-09-28 one of them is confirmed and the
  other is contradicted by the provider's own examples.** The digest's exclusive `C14N` against an
  inclusive `Reference` is now documented — the technical page names the inclusive form — and the
  `+30 days` vigência is not: the provider states only a format for `vigencia`, and its two examples
  span 200 and 145 days, both ending on the same date. → Preserve each verbatim as a decision with a
  gate rather than tidying it, name them in the spec so a later reader sees a decision and not an
  oversight, record the period as **unconfirmed** rather than as the provider's value, and block
  term issuance until a contract test proves the provider accepts the document. A blocked feature is
  the recoverable failure; rejected terms are not. The contract test is also what has to settle the
  period, because the constant is inside `formatDigest()` and changing it re-opens the gate — which
  is why the period is not moved to match a sample.
- **The trailing space in `finalidade ` looked like a third verbatim value and is not one, because it
  does not survive into the signed document.** A previous decision in this document kept it, on the
  reasoning that the model is the only authority — a reasoning that does not survive measurement:
  `createElement('finalidade ')` throws a `DOMException`, the model's `SimpleXMLElement` path emits a
  string libxml accepts but whose `nodeName` is `finalidade` without the space, and the
  `loadXML`/`saveXML` the signing routine performs drops the space entirely. → Name the element
  `finalidade`, record the space as one that does not survive signing rather than as one judged
  unimportant, so a later reader does not "restore fidelity to the model" and break the document, and
  keep the name itself under the same issuance gate as the other two. A requirement that mandates a
  value the signing round trip discards is not a conservative choice; it is a guarantee no
  implementation can keep.
- **The signature is proven well-formed, not proven accepted.** A local test can show that the
  envelope is correct and that the signature verifies against the certificate's public key; it
  cannot show that the provider's validator accepts it, that the `304` resubmission returns the
  token, or that the term's roles are the ones the gateway expects. → A real contract test against
  the provider is a precondition for issuing any term, written as a requirement in the spec, and
  this change does not claim that precondition is met.
- **The office certificate in the database raises the cost of an `APP_KEY` loss.** Database-resident
  ciphertext is recovered by a database backup, but a wrong key destroys every stored certificate
  and the platform credential irreversibly. → No key rotation, stated as a constraint on operations
  and already true for the platform credential; the office's remedy is to re-upload its certificate.
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
