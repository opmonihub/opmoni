# Fiscal Capture Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the existing NF-e capture safe to resume after malformed XML, missing positions, hourly lookup limits and long interruptions.

**Architecture:** Keep `FiscalDocumentWriter` as the only writer and `FiscalCaptureService` as the owner of the cursor. Add a point-lookup operation to the connector, a per-client/source persisted recovery state and a shared hourly lookup budget; reconciliation runs outside the incremental cursor and never moves it. No network calls in tests.

**Tech Stack:** Laravel 13, PHP ^8.3 (runtime 8.4), PostgreSQL in production, Redis cache/queue, PHPUnit, existing HTTP fake and filesystem fake.

**Spec:** `openspec/changes/add-fiscal-document-capture/specs/tenant/fiscal-capture/spec.md` (cursor, consumption, reconciliation) and `openspec/changes/add-fiscal-document-capture/design.md` (decisions 5–7, 10–11).

## Global Constraints

- No new runtime dependencies; no XMLDSig or manifestation (`210200`). mTLS uses the client's own A1, with TLS verification and the versioned ICP-Brasil bundle.
- Tenant ownership is `account_id`; `CurrentTenant` is mutable across queue jobs. Set `account_id` from the client explicitly for every new row. Never use a dev or production database for PHPUnit: `phpunit.xml` supplies sqlite `:memory:`.
- Never log certificate passwords, raw XML, `docZip` or untrusted exception messages. Persist raw XML bytes; normalize only for display. `last_error` stays a bounded, safe sentence.
- Keep `last_run_at` before the request and `last_seen_at` after an answer. Never increment `ultNSU`, advance after an incomplete batch, or collapse `blocked` into `locked`.
- Write tests named `test_` plus Portuguese domain vocabulary; use `php artisan make:test --phpunit Nome --no-interaction` for new PHPUnit files. After PHP edits run `vendor/bin/pint --dirty --format agent` (never `--test`). Run narrow tests after each edit and `php artisan test --compact` at completion.
- No real SEFAZ traffic in tests. `FISCAL_ENVIRONMENT` defaults to `producao` and is absent from `.env.example`: a manual production trial must be separately authorized, one NF-e client first, before enabling any new schedule.

---

## File Structure

| File | Responsibility |
|---|---|
| `backend/app/Services/Fiscal/Support/FiscalXmlEncoding.php` (new) | UTF-8 view of UTF-8/Latin-1 XML, without changing persisted bytes. |
| `backend/app/Services/Fiscal/Capture/FiscalCaptureService.php` | Treat undecryptable legacy/current password as `certificate_reupload` state with no outbound call, before cursor work. |
| `backend/app/Services/Fiscal/Contracts/PullResult.php`, `backend/app/Services/Fiscal/Nfe/NfeDistributionConnector.php` | Carry safe response reason so `137` cooldown is not confused with `656` improper-consumption attention. |
| `backend/app/Services/Fiscal/Contracts/FiscalConnector.php`, `backend/app/Services/Fiscal/Nfe/NfeDistributionConnector.php` | `fetchByNsu(Client,int): ?PulledDocument` via `consNSU`, separate from existing `fetchByChave`. |
| `backend/app/Services/Fiscal/Support/DfeSoapEnvelope.php` | Explicit point-NSU query builder; keep the existing `distNSU` path. |
| `backend/app/Services/Fiscal/Capture/FiscalLookupBudget.php` (new) | Atomic, shared 20/CNPJ/hour token reservation for both point-lookup methods; false means defer, not retry. |
| `backend/database/migrations/2026_09_28_000007_create_fiscal_gaps_table.php`, `backend/app/Models/FiscalGap.php`, `backend/database/factories/FiscalGapFactory.php` (new) | One outstanding gap per client/source/NSU, with `attempts` and `next_attempt_at`. |
| `backend/app/Services/Fiscal/Capture/FiscalReconciliation.php` (new) | Find known missing NSUs, apply budget, attempt point lookup, persist through writer; never update cursor `last_nsu`. |
| `backend/app/Console/Commands/ReconcileFiscalDocuments.php`, `backend/app/Jobs/ReconcileFiscalDocumentsJob.php`, `backend/routes/console.php` | One bounded recovery job per client/source; scheduled outside business hours with configured timezone. |
| `backend/tests/Unit/FiscalXmlEncodingTest.php`, `backend/tests/Feature/Fiscal/FiscalReconciliationTest.php`, `backend/tests/Feature/Fiscal/FiscalPointLookupTest.php` | Encoding, recovery, budget, tenancy and point-query contract. |

**Already delivered; do not redo:** certificate encryption/materialization, private disk, schema, NF-e `pull`/`fetchByChave`, writer, `CaptureFiscalDocumentsJob` (`tries=1`, `timeout=85`), hourly `fiscal:capture`, capture lock (`fiscal.lock_ttl=180`). Checked/unchecked boxes in `tasks.md` are not an accurate implementation inventory. The old `2026-09-27-fiscal-capture-nfe.md` is historical, not an execution checklist.

### Task 1: Decode XML for display without corrupting storage

**Files:** Create `backend/app/Services/Fiscal/Support/FiscalXmlEncoding.php`; test `backend/tests/Unit/FiscalXmlEncodingTest.php`. The UI/API plan consumes this class.

**Interfaces:** `FiscalXmlEncoding::forDisplay(string $raw): string`; leave `PulledDocument::$xml`, `FiscalDocumentWriter::store()` and `sha256` unchanged.

- [ ] **Step 1: Write the failing tests.** Generate the test file, then add `test_converte_latin1_sem_alterar_bytes_originais` using `$raw = '<?xml version="1.0" encoding="ISO-8859-1"?><raiz>Jo'.chr(0xE3).'o</raiz>'; $this->assertSame('João', strip_tags($encoding->forDisplay($raw))); $this->assertSame(0xE3, ord($raw[strpos($raw, 'Jo') + 2]));` and `test_mantem_utf8_sem_dupla_conversao` asserting UTF-8 `João` is unchanged. Include a test that rejects an invalid encoding declaration instead of replacing characters with `?`.

  ```php
  $raw = '<?xml version="1.0" encoding="ISO-8859-1"?><raiz>Jo'.chr(0xE3).'o</raiz>';
  $view = (new FiscalXmlEncoding)->forDisplay($raw);
  $this->assertSame('João', strip_tags($view));
  $this->assertSame('UTF-8', (string) mb_detect_encoding($view, ['UTF-8'], true));
  $this->assertSame(0xE3, ord($raw[strpos($raw, 'Jo') + 2]));
  ```
- [ ] **Step 2: Verify red.** Run `cd backend && php artisan test --compact tests/Unit/FiscalXmlEncodingTest.php`; expect missing class.
- [ ] **Step 3: Implement the smallest safe decoder.** `forDisplay()` returns the input when `mb_check_encoding($raw, 'UTF-8')` is true; otherwise allow only a case-insensitive XML declaration of `ISO-8859-1`, convert with `mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1')`, rewrite only its declaration to `UTF-8`, and throw `RuntimeException('Codificação XML não suportada para prévia.')` for other malformed input. Do not convert `windows-1252` heuristically or touch the writer.

  ```php
  if (! mb_check_encoding($raw, 'UTF-8')) {
      if (preg_match('/^<\?xml\s+[^?]*encoding=["\']ISO-8859-1["\']/i', $raw) !== 1) {
          throw new RuntimeException('Codificação XML não suportada para prévia.');
      }
      $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
  }
  return preg_replace('/(<\?xml\s+[^?]*encoding=["\'])ISO-8859-1(["\'])/i', '$1UTF-8$2', $raw) ?? $raw;
  ```
- [ ] **Step 4: Verify green and commit.** Run that test and `vendor/bin/pint --dirty --format agent`; commit the two files as `feat(fiscal): normalize XML only for preview`.

### Task 2: Password unavailable at capture time

**Files:** Modify `backend/app/Services/Fiscal/Capture/FiscalCaptureService.php`; test `backend/tests/Feature/Fiscal/FiscalCaptureServiceTest.php`.

**Interfaces:** `FiscalCaptureOutcome::skipped(FiscalSkipReason::NoCertificate, int $lastNsu)` and `FiscalCursor::last_error='certificate_reupload'` on `DecryptException` only. Do not catch a general `Throwable` or expose the ciphertext.

- [ ] **Step 1: Write red test.** Create client with current unexpired certificate whose `password_encrypted` is `nao-e-um-ciphertext`; fake connector and assert no `pull`, cursor unchanged, safe `last_error` and `NoCertificate`. A subsequent re-upload with a valid password clears the reason on successful capture.

  ```php
  $client->currentCertificate->forceFill(['password_encrypted' => 'nao-e-um-ciphertext'])->save();
  $outcome = $this->service()->capture($client->refresh(), FiscalSource::NfeDistribuicao);
  $this->assertSame(FiscalSkipReason::NoCertificate, $outcome->skipReason);
  $this->assertSame('certificate_reupload', $this->cursor($client)->last_error);
  $this->assertSame([], $this->pulls);
  ```
- [ ] **Step 2: Verify red.** `php artisan test --compact tests/Feature/Fiscal/FiscalCaptureServiceTest.php --filter=test_certificado_com_senha_indecifravel_exige_reenvio`; expect `DecryptException`.
- [ ] **Step 3: Implement.** Catch only `Illuminate\Contracts\Encryption\DecryptException` around `$certificate->certificatePassword()`; persist the fixed marker, return `NoCertificate` without a request; leave missing/null password and expired paths consistent. Clear marker after successful capture.

  ```php
  try {
      $password = $certificate->certificatePassword();
  } catch (DecryptException) {
      $cursor->forceFill(['last_error' => 'certificate_reupload'])->save();
      return FiscalCaptureOutcome::skipped(FiscalSkipReason::NoCertificate, (int) $cursor->last_nsu);
  }
  ```
- [ ] **Step 4: Verify and commit.** Run the focused test, Pint; commit `fix(fiscal): report undecryptable A1 as reupload`.

### Task 3: Separate normal cooldown from improper-consumption attention

**Files:** Modify `backend/app/Services/Fiscal/Contracts/PullResult.php`, `backend/app/Services/Fiscal/Nfe/NfeDistributionConnector.php`, `backend/app/Services/Fiscal/Capture/FiscalCaptureService.php`; test `backend/tests/Feature/Fiscal/FiscalCaptureServiceTest.php` and existing NF-e connector test.

**Interfaces:** Append `public ?FiscalFailure $failure = null` to `PullResult::__construct()` after `failures` (existing positional constructors still work); use `FiscalFailure::NoDocuments` for `137`, `FiscalFailure::Blocked` for `656`, `null` for `138`. On response persist safe `last_error='blocked_consumption'` for `Blocked` and null for `NoDocuments`; preserve `blocked_until` for both.

- [ ] **Step 1: Write red test.** Feed `137` and `656` response fixtures through the real connector and capture; assert both stop one hour, only `656` writes the fixed marker, both honor `mayAdoptPosition`, and the marker clears on the next successful response.

  ```php
  $this->assertSame(FiscalFailure::Blocked, $blocked->failure);
  $this->assertSame('blocked_consumption', $cursor->fresh()->last_error);
  $this->assertSame(FiscalFailure::NoDocuments, $empty->failure);
  ```
- [ ] **Step 2: Verify red.** `php artisan test --compact tests/Feature/Fiscal/FiscalCaptureServiceTest.php --filter=test_rejeicao_por_consumo_indebido`; expect missing response reason.
- [ ] **Step 3: Implement.** Append the optional enum field to `PullResult`, pass `failure: $failure` from the `blocksForAnHour` branch of NF-e connector; in `persistAnswer` set `last_error` by incomplete batch first, then the fixed blocked marker, otherwise null. Never store `xMotivo`.

  ```php
  'last_error' => $unread > 0 ? $this->incompleteNote($unread, count($result->documents) + count($result->failures))
      : ($result->failure === FiscalFailure::Blocked ? 'blocked_consumption' : null),
  ```
- [ ] **Step 4: Verify and commit.** Run targeted NF-e/capture tests, Pint; commit `fix(fiscal): distinguish SEFAZ cooldown from consumption block`.

### Task 4: Point lookup and shared hourly CNPJ budget

**Files:** Modify `backend/app/Services/Fiscal/Contracts/FiscalConnector.php`, `backend/app/Services/Fiscal/Support/DfeSoapEnvelope.php`, `backend/app/Services/Fiscal/Nfe/NfeDistributionConnector.php`; create `backend/app/Services/Fiscal/Capture/FiscalLookupBudget.php`; test `backend/tests/Feature/Fiscal/FiscalPointLookupTest.php` and update anonymous connector fakes in `FiscalCaptureServiceTest.php`.

**Interfaces:** `FiscalConnector::fetchByNsu(Client $client, int $nsu): ?PulledDocument`; `FiscalLookupBudget::reserve(Client $client): bool`; `FiscalLookupDeferred extends RuntimeException` with fixed message `Limite horário de consultas pontuais atingido.`. Both point-lookup connector methods reserve **inside the connector before HTTP**; `pull` does not consume this budget. Client `tax_id` (CNPJ) is the quota identity across Accounts. Failed reservation throws `FiscalLookupDeferred`, caught by reconciliation to defer without charging an attempt.

- [ ] **Step 1: Write red tests.** In `FiscalPointLookupTest`, use `Http::fake()` + existing synthetic `retDistDFeInt_138.xml` to assert `fetchByNsu($client, 100)` sends `<consNSU><NSU>000000000000100</NSU></consNSU>`, preserves SOAP action/mTLS options and returns a document without moving a cursor; `137` returns null, `656` must not return null. For budget, freeze time and call `reserve($client)` 21 times: first 20 true, last false; same CNPJ on another Account also false; a different CNPJ true; after one hour true. Use `Cache::store('array')->clear()` in setup.

  ```php
  for ($attempt = 1; $attempt <= 20; $attempt++) {
      $this->assertTrue($budget->reserve($client));
  }
  $this->assertFalse($budget->reserve($client));
  Http::assertNothingSent(); // test the deferred path with the connector, not just the counter
  ```
- [ ] **Step 2: Verify red.** Run `php artisan test --compact tests/Feature/Fiscal/FiscalPointLookupTest.php`; expect missing `fetchByNsu` and budget class.
- [ ] **Step 3: Implement.** Add `DfeSoapEnvelope::pointNsu(string $envelope, int $nsu): string` which replaces exactly one `<distNSU><ultNSU>000000000000000</ultNSU></distNSU>` with `<consNSU><NSU>` + `str_pad((string) $nsu, 15, '0', STR_PAD_LEFT)` + `</NSU></consNSU>` and throws if not exactly one group was replaced; validate resulting payload against the existing XSD. Implement `fetchByNsu` through existing send/interpret/collect, returning one document on `138`, null only on `137`, otherwise a safe `FiscalException`. Before any point HTTP request, reserve the budget or throw `FiscalLookupDeferred`; this also applies to `fetchByChave`. Implement `reserve` with a Redis-compatible atomic `Cache::lock('fiscal:consulta:'.$client->tax_id, 5)->block(1, ...)` around `Cache::get/put` of an hourly window counter (`limit=20`, TTL until next hour); never key by Account. Do not include secret material in keys/logs.

  ```php
  if (! $this->lookupBudget->reserve($client)) {
      throw new FiscalLookupDeferred('Limite horário de consultas pontuais atingido.');
  }
  $point = '<consNSU><NSU>'.str_pad((string) $nsu, 15, '0', STR_PAD_LEFT).'</NSU></consNSU>';
  ```
- [ ] **Step 4: Verify green and commit.** Run the new test and `FiscalCaptureServiceTest.php`, then Pint; commit as `feat(fiscal): add bounded point lookup by NSU`.

### Task 5: Persist known gaps and reconcile without moving the cursor

**Files:** Create `backend/database/migrations/2026_09_28_000007_create_fiscal_gaps_table.php`, `backend/app/Models/FiscalGap.php`, `backend/database/factories/FiscalGapFactory.php`, `backend/app/Services/Fiscal/Capture/FiscalReconciliation.php`; modify `backend/app/Services/Fiscal/Capture/FiscalCaptureService.php`; test `backend/tests/Feature/Fiscal/FiscalReconciliationTest.php`.

**Interfaces:** `FiscalReconciliation::run(Client $client, FiscalSource $source): int` returns count of recovered documents; query only recorded gaps and explicitly bounded holes between an observed first NSU and the service-returned `lastNsu`. The connector must already implement `fetchByNsu`; the writer is the sole persistence path.

- [ ] **Step 1: Write red tests.** Create two clients with different `account_id`, fake connector calls, and `FiscalGap::factory()->create(['account_id' => $client->account_id, 'client_id' => $client->id, 'source' => FiscalSource::NfeDistribuicao, 'nsu' => 101])`. Assert a recovered `PulledDocument` is stored, gap removed, cursor unchanged, and second `run()` makes no lookup; foreign-Account gaps untouched. Add cases: unsuccessful `fetchByNsu` increments `attempts` and sets `next_attempt_at`; after configured `max_attempts=3` no further calls; 21st lookup defers without incrementing attempts. Test an incomplete batch `FailedEntry(nsu:101, ...)` creates exactly one gap while successful later entries remain persisted. Test two successive observed NSUs 100 and 102 record gap 101 but the *first* returned NSU 100 does not trigger a scan from zero; cap generated gaps per batch at `fiscal.batch_limit`.

  ```php
  $gap = FiscalGap::factory()->create(['account_id' => $client->account_id, 'client_id' => $client->id, 'source' => FiscalSource::NfeDistribuicao, 'nsu' => 101]);
  $this->assertSame(1, $reconciliation->run($client, FiscalSource::NfeDistribuicao));
  $this->assertDatabaseMissing('fiscal_gaps', ['id' => $gap->id]);
  $this->assertSame(100, $cursor->fresh()->last_nsu);
  ```
- [ ] **Step 2: Verify red.** Run `php artisan test --compact tests/Feature/Fiscal/FiscalReconciliationTest.php`; expect missing model/service.
- [ ] **Step 3: Implement schema/model.** Generate migration and model/factory through Artisan; migration columns: foreign keys `account_id`, `client_id`, `source` string, unsigned `nsu`, unsigned `attempts` default 0, nullable `next_attempt_at`, timestamps; unique `(client_id,source,nsu)` and index `(account_id,source,next_attempt_at)`. Model uses `BelongsToAccount`, casts, client relation. On `storeBatch`, upsert gap for every `FailedEntry` and failed writer operation, and between consecutive *observed* NSUs in the same returned batch only (max 50 gaps per batch), never assume zero-to-first is a gap. Include newly detected gaps in `persistAnswer`'s unread count so cursor cannot advance past them; retain successful writes for replay. Clear a gap for successful store by `(client_id,source,nsu)` only after disk+DB succeed. Set `account_id` explicitly from client.

  ```php
  $table->foreignId('account_id')->constrained();
  $table->foreignId('client_id')->constrained();
  $table->string('source');
  $table->unsignedBigInteger('nsu');
  $table->unsignedTinyInteger('attempts')->default(0);
  $table->timestamp('next_attempt_at')->nullable();
  $table->unique(['client_id', 'source', 'nsu']);
  ```
- [ ] **Step 4: Implement reconciliation.** Acquire the same `fiscal:capture:{clientId}:{source}` lock as capture (extract `lockKey` as shared `FiscalCaptureLock` if necessary), skip blocked/interrupted clients, select at most 20 outstanding gaps ordered by NSU with `attempts < 3` and due `next_attempt_at`; `fetchByNsu` returning a document at *the requested NSU* goes through writer and removes gap; `FiscalLookupDeferred` stops this run without incrementing attempts; null or safe failure increments attempts and postpones by one hour; an exception never changes `last_nsu` or logs its untrusted message. Avoid counting non-document NSUs as errors until a `consNSU` answer confirms no document.

  ```php
  try {
      $document = $connector->fetchByNsu($client, (int) $gap->nsu);
  } catch (FiscalLookupDeferred) {
      break;
  }
  if ($document !== null && $document->nsu === (int) $gap->nsu) {
      $writer->store($client, $source, $document);
      $gap->delete();
  }
  if ($document === null) {
      $gap->increment('attempts');
      $gap->forceFill(['next_attempt_at' => now()->addHour()])->save();
  }
  ```
- [ ] **Step 5: Verify green and commit.** Run `FiscalReconciliationTest.php`, `FiscalCaptureServiceTest.php`, Pint; commit as `feat(fiscal): reconcile recorded distribution gaps`.

### Task 6: Operate recovery on a bounded schedule

**Files:** Create `backend/app/Console/Commands/ReconcileFiscalDocuments.php`; modify `backend/routes/console.php`, `backend/config/fiscal.php`; test `backend/tests/Feature/Fiscal/ReconcileFiscalDocumentsCommandTest.php`.

**Interfaces:** `fiscal:reconcile {--source=nfe_distribuicao} {--client=}`; `fiscal.reconcile_timezone` default `America/Sao_Paulo`, `fiscal.reconcile_hour` default `2`, `fiscal.reconcile_max_attempts` default `3`.

- [ ] **Step 1: Write red test.** With a fake connector and `Http::preventStrayRequests()`, assert the command visits only clients with outstanding gaps, filters `--client`, refuses an unknown source and has a `Schedule` event for `fiscal:reconcile` at `0 2 * * *`, timezone `America/Sao_Paulo`, `withoutOverlapping`. Assert a client blocked until tomorrow is not queried even when the command runs.

  ```php
  Bus::fake();
  $this->artisan('fiscal:reconcile', ['--source' => 'nfe_distribuicao'])->assertSuccessful();
  Bus::assertDispatched(ReconcileFiscalDocumentsJob::class, fn ($job) => $job->clientId === $client->id);
  ```
- [ ] **Step 2: Verify red.** Run `php artisan test --compact tests/Feature/Fiscal/ReconcileFiscalDocumentsCommandTest.php`; expect command missing.
- [ ] **Step 3: Implement.** Use `Client::query()->whereHas('fiscalGaps')->chunkById(100, ...)` (add `Client::fiscalGaps(): HasMany`) and dispatch **one `ReconcileFiscalDocumentsJob` per client/source** so the scheduler never waits on a whole portfolio; job delegates to `FiscalReconciliation::run()` (max 20 point lookups), `tries=1`, `timeout=85`. Schedule `Schedule::command('fiscal:reconcile')->dailyAt(sprintf('%02d:00', (int) config('fiscal.reconcile_hour')))->timezone(config('fiscal.reconcile_timezone'))->withoutOverlapping()`; no network at registration time. Add `backend/app/Jobs/ReconcileFiscalDocumentsJob.php` to this task and change the test to use `Bus::fake()` for dispatch, plus a direct job test for blocked clients.

  ```php
  Client::query()->whereHas('fiscalGaps', fn ($query) => $query->where('source', $source->value))
      ->chunkById(100, function ($clients) use ($source): void {
          foreach ($clients as $client) {
              ReconcileFiscalDocumentsJob::dispatch((int) $client->id, $source);
          }
      });
  ```
- [ ] **Step 4: Verify green.** Run targeted tests, `php artisan schedule:list`, Pint and `php artisan test --compact`; commit as `feat(fiscal): schedule bounded recovery`.

## Release gate

The old plan asserts `(client_id,chave_acesso,event_id)` uniqueness; migration `000006` and the writer now correctly use `(client_id,chave_acesso,stage,event_id)` so summary and full XML coexist. Do not undo this. `tasks.md` item 2.6 is conditional: only add a separate performance-index migration after a PostgreSQL `EXPLAIN` on representative filtered data shows a sequential scan (never use the destructive scratch config against a populated database). Replace the synthetic fixture-only contract check with sanitized real `resNFe`/`procNFe` and a deliberately mismatched `digVal` before authorizing a production capture; obtain these fixtures without logging raw production XML or committing personal data. Run `openspec validate add-fiscal-document-capture` and review uncommitted diff; do not deploy or start live capture without explicit permission.
