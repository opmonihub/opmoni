# CT-e Distribution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capture CT-e (including service and simplified variants) through the existing document writer without sending any manifestation or using the NF-e source by mistake.

**Architecture:** One connector per source, with a shared stateless DF-e transport and entry collector. Select connectors by `FiscalSource` at the service boundary; each cursor and lock remains scoped to client and source. CT-e uses its own endpoint and 1.00 payload but the same mTLS, SOAP parser, per-entry decoder and writer as NF-e.

**Tech Stack:** Laravel 13, PHP ^8.3, PHPUnit, existing `Http::fake()`, `Storage::fake('fiscal')` and `Storage::fake('certificates')`.

**Spec:** `openspec/changes/add-fiscal-document-capture/specs/fiscal-capture/spec.md` (NF-e and CT-e, cursor, events), `openspec/changes/add-fiscal-document-capture/design.md` (decisions 1–4, 7 and CT-e notes).

## Global Constraints

- No dependencies, XML signature or manifestation; own client A1 via mTLS; TLS verification and the ICP-Brasil `.crt` stay enabled. HTTP calls are fake in tests.
- Preserve existing NF-e behavior (layout `1.01`, `fiscal.environment` defaults to `producao`), `ultNSU` from response only, `137`/consumption block for one hour, batch before cursor. No consumer queries by CT-e access key: the change design says CT-e supports NSU lookups, not `consChCTe`.
- `FiscalDocumentWriter` remains the sole writer; set `account_id` from client in queue code. Never log raw XML, `docZip`, certificate password, or private path; never write to a real production database in a test.
- Run new PHPUnit tests with `php artisan test --compact <path>` and PHP formatting with `vendor/bin/pint --dirty --format agent`; full `php artisan test --compact` at end. Test names/comments and user-facing copy use pt-BR vocabulary from `CONTEXT.md`.
- CT-e sources are enabled in the command and schedule only after the contract tests pass; live trial requires separate authorization, beginning with one client.

---

## File Structure

| File | Responsibility |
|---|---|
| `backend/app/Services/Fiscal/Support/DfeTransport.php` (new) | Shared SOAP 1.2 request, mTLS options, response handling, XSD validation and safe failure mapping extracted from existing NF-e connector. |
| `backend/app/Services/Fiscal/Support/DfeEntryCollector.php` (new) | Decode each entry, extract metadata by supplied model, accumulate `FailedEntry`; no persistence. |
| `backend/app/Services/Fiscal/Nfe/NfeDistributionConnector.php` | Thin NF-e service parameters plus `fetchByChave`/`fetchByNsu`; existing contract tests remain green. |
| `backend/app/Services/Fiscal/Cte/CteDistributionConnector.php` (new) | CT-e source, SOAP parameters, `pull`, `fetchByNsu`, no lookup by access key. |
| `backend/config/fiscal.php` | CT-e endpoint parameters; explicitly pinned SOAP action. |
| `backend/app/Services/Fiscal/Support/FiscalXmlMetadata.php`, `backend/app/Services/Fiscal/Support/FiscalXmlValidator.php`, `backend/app/Enums/FiscalModel.php` | CT-e schema/root families, model codes 57/67/64 where applicable; authorization and transport keys; no masked key indexing. |
| `backend/app/Services/Fiscal/Capture/FiscalCaptureService.php`, `backend/app/Console/Commands/CaptureFiscalDocuments.php`, `backend/app/Providers/AppServiceProvider.php`, `backend/routes/console.php` | Resolve a connector by source and dispatch CT-e separately; NF-e stays default and hourly. |
| `backend/tests/Feature/Fiscal/CteDistributionConnectorTest.php`, `backend/tests/Unit/CteXmlMetadataTest.php`, `backend/tests/Feature/Fiscal/CaptureFiscalDocumentsCommandTest.php` | CT-e contracts, forms, source resolution; existing NF-e tests as regression gate. |

**Prerequisite:** Finish recovery plan Task 2 (`fetchByNsu` contract and quota), or add its interface and tests during Task 2 here. Existing NF-e `pull` already exists despite unchecked item 4.2; job and scheduler already exist despite unchecked 5.6–5.7. Do not recreate them. The specification's five CT-e schema families must be checked against the official CT-e schema package; do not turn an unknown schema into `FiscalModel::Nfe`.

### Task 1: Extract shared DF-e mechanics without changing NF-e

**Files:** Create `backend/app/Services/Fiscal/Support/DfeTransport.php`, `backend/app/Services/Fiscal/Support/DfeEntryCollector.php`; modify `backend/app/Services/Fiscal/Nfe/NfeDistributionConnector.php`; test `backend/tests/Feature/Fiscal/NfeDistributionConnectorTest.php` (create if existing coverage is under another filename; do not duplicate tests).

**Interfaces:** `DfeTransport::request(Client $client, array $endpoint, string $body): DfeResponse` and `DfeEntryCollector::collect(DfeResponse $response, FiscalModel $model): PullResult`. Preserve all `PullResult` fields including `failures`, `mayAdoptPosition`, `blockedUntil`.

- [ ] **Step 1: Pin current contract with a red refactor test.** Add a test using `Http::fake(['*' => Http::response(file_get_contents(base_path('tests/Fixtures/fiscal/retDistDFeInt_138.xml')), 200)])`; assert NF-e `pull($client, 0, 50)` yields `FiscalSource::NfeDistribuicao`, correct parsed document and `lastNsu`, and request has `application/soap+xml` with NF-e SOAP action; a bad entry yields a `FailedEntry`, not an advanced position. `Http::preventStrayRequests()` and fake both disks.

  ```php
  Http::preventStrayRequests();
  Http::fake(['*' => Http::response(file_get_contents(base_path('tests/Fixtures/fiscal/retDistDFeInt_138.xml')), 200)]);
  $result = app(NfeDistributionConnector::class)->pull($client, 0, 50);
  $this->assertSame(FiscalSource::NfeDistribuicao, app(NfeDistributionConnector::class)->source());
  $this->assertTrue($result->mayAdoptPosition);
  $this->assertNotEmpty($result->documents);
  ```
- [ ] **Step 2: Run contract baseline.** `php artisan test --compact tests/Feature/Fiscal/NfeDistributionConnectorTest.php`; if the new test already passes, record its baseline and proceed with refactor; if it fails, fix the test's fixture assumptions rather than weakening production behavior.
- [ ] **Step 3: Extract without semantic change.** Move NF-e `request`, `interpret`, fault parsing, XSD call and `payloadOf` to `DfeTransport`; move `collect` body to `DfeEntryCollector` with `$metadata->extract($xml, $model)` and the existing safe `FailedEntry` sentences. Keep NF-e-specific endpoint, UF mapping, `fetchByChave` query construction and failure classification in the NF-e connector. Constructor DI for the two new classes; do not copy HTTP code into CT-e.

  ```php
  // DfeEntryCollector::collect(DfeResponse $response, FiscalModel $model): PullResult
  foreach ($response->entries as $entry) {
      try {
          $xml = $this->decoder->decode($entry->payload);
          $extracted = $this->metadata->extract($xml, $model);
          $documents[] = new PulledDocument(
              model: $extracted->model, kind: $extracted->kind, stage: $extracted->stage,
              chave: $extracted->chave, eventId: $extracted->eventId,
              emitenteCnpj: $extracted->emitenteCnpj, destinatarioCnpj: $extracted->destinatarioCnpj,
              valorTotal: $extracted->valorTotal, digVal: $extracted->digVal,
              nsu: $entry->nsu, schema: $entry->schema,
              emissaoAt: $extracted->emissaoAt, eventoOcorridoEmAt: $extracted->eventoOcorridoEmAt,
              xml: $xml, mascarado: $extracted->mascarado,
          );
      } catch (RuntimeException) {
          $failures[] = new FailedEntry($entry->nsu, $entry->schema, 'Entrada da distribuição não pôde ser lida.');
      }
  }
  ```
- [ ] **Step 4: Run tests and commit.** Run `NfeDistributionConnectorTest.php`, `FiscalCaptureServiceTest.php`, Pint; commit `refactor(fiscal): share distribution transport and parsing`.

### Task 2: Parse CT-e variants and masked transport references

**Files:** Modify `backend/app/Services/Fiscal/Support/FiscalXmlMetadata.php`, `backend/app/Enums/FiscalModel.php`, `backend/app/Services/Fiscal/Contracts/PulledDocument.php`, `backend/app/Services/Fiscal/Support/FiscalXmlMetadataResult.php`, `backend/app/Services/Fiscal/Capture/FiscalDocumentWriter.php`; create `backend/tests/Unit/CteXmlMetadataTest.php` and synthetic `backend/tests/Fixtures/fiscal/cte-resumo.xml`, `cte-os.xml`, `cte-simp.xml`, `cte-evento.xml`, `cte-gtve.xml` (minimal XMLs, no real taxpayer data). The existing `cteProc.xml` covers the regular processed document.

**Interfaces:** `FiscalXmlMetadata::extract(string $xml, FiscalModel::Cte): FiscalXmlMetadataResult`; the result must expose `mascarado: bool` (add to `FiscalXmlMetadataResult` and `PulledDocument`) and carry it into `FiscalDocumentWriter::store()` as `mascarado`. `chave` is always the document's *own* validated 44-digit key, not a transported NF-e key from `infDoc`.

- [ ] **Step 1: Write red tests for all five families.** In a data provider name each root/schema pair: `resCTe`, `cteProc`, `cteOSProc`, `cteSimpProc`, `procEventoCTe` (include `GTVe` as a separate case if present in the official package); assert correct model/kind/stage and validated own key. Test an `autXML` document containing `<infNFe><chave>99999999999999999999999999999999999999999999</chave></infNFe>`: `mascarado=true`, no indexed transported key, own key unchanged. Test invalid own DV refused.

  ```php
  $xml = file_get_contents(base_path('tests/Fixtures/fiscal/cteProc.xml'));
  $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Cte);
  $this->assertSame(FiscalModel::Cte, $result->model);
  $this->assertSame(FiscalStage::Document, $result->stage);
  $this->assertTrue(FiscalXmlMetadata::isValidChave($result->chave));
  ```
- [ ] **Step 2: Verify red.** `php artisan test --compact tests/Unit/CteXmlMetadataTest.php`; expect missing variant handling/`mascarado`.
- [ ] **Step 3: Implement.** Match XML *root and structure*, not only untrusted `docZip@schema`, against CT-e schema families validated against official `PL_CTeDistDFe_100`. Extend `FiscalModel` with `CteOs='cte_os'` for access-key model `67` and `Gtve='gtve'` for `64`; keep model `57` as `Cte` for regular/simplified. Make `FiscalXmlMetadata::extract($xml,FiscalModel::Cte)` accept only the CT-e family `57/67/64` but return the *actual* model from the document's own key; do not weaken NF-e's `55` guard. Extend `stageOf` for `protCTe` and simplified/OS processed documents; detect a zeroed transported key without traversing it for document identity. Add `mascarado` to value objects with explicit propagation, and set the column in writer; leave NF-e default `false`. Reject an unknown XML root as `FailedEntry` without advancing cursor. If official package family names differ from the five labels above, align fixture names and tests to the *published* XML shape, not this prose.

  ```php
  // FiscalModel::fromDocumentModel(): ?self
  return match ($model) {
      '55' => self::Nfe, '65' => self::Nfce,
      '57' => self::Cte, '67' => self::CteOs, '64' => self::Gtve,
      default => null,
  };
  // In writer: 'mascarado' => $document->mascarado, never infer from NSU.
  ```
- [ ] **Step 4: Verify and commit.** Run `CteXmlMetadataTest.php`, `FiscalXmlMetadataTest.php`, `FiscalDocumentWriterTest.php` and Pint; commit `feat(fiscal): recognize CT-e distribution families`.

### Task 3: Add CT-e transport and independent source resolution

**Files:** Create `backend/app/Services/Fiscal/Cte/CteDistributionConnector.php`, `backend/tests/Feature/Fiscal/CteDistributionConnectorTest.php`; modify `backend/config/fiscal.php`, `backend/app/Services/Fiscal/Capture/FiscalCaptureService.php`, `backend/app/Providers/AppServiceProvider.php` and existing NF-e connector tests.

**Interfaces:** `FiscalCaptureService::connectorFor(FiscalSource $source): FiscalConnector` selects *only* the matching source (throw on absent); `CteDistributionConnector::source(): FiscalSource` returns `CteDistribuicao`, `pull()` returns existing `PullResult`, `fetchByNsu()` uses `consNSU`, `fetchByChave()` explicitly refuses without HTTP.

- [ ] **Step 1: Write red source/transport tests.** Fake the CT-e response `137`, `138` and `656`, assert the exact body contains `cteDistDFeInteresse`, `cteDadosMsg`, `<distDFeInt xmlns="http://www.portalfiscal.inf.br/cte" versao="1.00">`; assert `Content-Type` action equals `http://www.portalfiscal.inf.br/cte/wsdl/CTeDistribuicaoDFe/cteDistDFeInteresse`, URL for prod is `https://www1.cte.fazenda.gov.br/CTeDistribuicaoDFe/CTeDistribuicaoDFe.asmx` and homolog is `https://hom1.cte.fazenda.gov.br/CTeDistribuicaoDFe/CTeDistribuicaoDFe.asmx`; no SOAP header/signature. `656` must block for an hour and use its returned NSU, `fetchByChave` sends zero calls. Add a fake-connector test that requesting CT-e cannot call NF-e.

  ```php
  Http::assertSent(fn ($request): bool => str_contains($request->body(), 'cteDistDFeInteresse')
      && str_contains($request->header('Content-Type')[0], 'CTeDistribuicaoDFe/cteDistDFeInteresse')
      && ! str_contains($request->body(), 'Signature'));
  $this->assertSame(FiscalSource::CteDistribuicao, $connector->source());
  ```
- [ ] **Step 2: Verify red.** `php artisan test --compact tests/Feature/Fiscal/CteDistributionConnectorTest.php`; expect missing CT-e connector.
- [ ] **Step 3: Implement.** Add `cte_distribuicao` config entry with URLs/action above, `namespace=http://www.portalfiscal.inf.br/cte/wsdl/CTeDistribuicaoDFe`, `payload_namespace=http://www.portalfiscal.inf.br/cte`, `version=1.00`, `method=cteDistDFeInteresse`, `holder=cteDadosMsg`. Use the shared transport and collector with CT-e family `FiscalModel::Cte`; add the point-lookup budget from the recovery plan to CT-e `fetchByNsu`. Wire a dedicated `FiscalConnectorRegistry::for(FiscalSource): FiscalConnector` in `AppServiceProvider` and change capture/reconciliation/command to resolve before `pull`; never use the bound NF-e connector for CT-e. Update any anonymous fake implementing `FiscalConnector` to implement `fetchByNsu`.

  ```php
  // The resolver, in one place; no default branch that sends CT-e to NF-e.
  return match ($source) {
      FiscalSource::NfeDistribuicao => app(NfeDistributionConnector::class),
      FiscalSource::CteDistribuicao => app(CteDistributionConnector::class),
  };
  ```
- [ ] **Step 4: Verify green and commit.** Run CT-e and NF-e connector/capture tests plus Pint; commit `feat(fiscal): capture CT-e through own source`.

### Task 4: Dispatch CT-e without altering the NF-e schedule

**Files:** Modify `backend/app/Console/Commands/CaptureFiscalDocuments.php`, `backend/routes/console.php`, `backend/tests/Feature/Fiscal/CaptureFiscalDocumentsCommandTest.php`.

**Interfaces:** `fiscal:capture --source=cte_distribuicao [--client=ID]`; current default stays `nfe_distribuicao`.

- [ ] **Step 1: Replace the existing CT-e rejection test with a failing dispatch test.** Use `Bus::fake()`; for one capturable client, `fiscal:capture --source=cte_distribuicao --client=ID` dispatches exactly one `CaptureFiscalDocumentsJob` with `FiscalSource::CteDistribuicao`; an unknown source still fails. Set `config(['fiscal.cte_scheduled' => true])` and assert NF-e hourly entry remains and a separate CT-e hourly entry is `withoutOverlapping`. With default `cte_scheduled=false`, assert CT-e is *not* automatically scheduled before the single-client trial.

  ```php
  Bus::fake();
  $this->artisan('fiscal:capture', ['--source' => 'cte_distribuicao', '--client' => (string) $client->id])->assertSuccessful();
  Bus::assertDispatched(CaptureFiscalDocumentsJob::class, fn ($job): bool => $job->source === FiscalSource::CteDistribuicao);
  ```
- [ ] **Step 2: Verify red.** `php artisan test --compact tests/Feature/Fiscal/CaptureFiscalDocumentsCommandTest.php`; expect CT-e refused.
- [ ] **Step 3: Implement.** Replace the single-connector `hasConnectorFor` check with source-keyed resolution, dispatch one job per client and source. Add `fiscal.cte_scheduled` default `false` (`(bool) env('FISCAL_CTE_SCHEDULED', false)`) and conditionally register `Schedule::command('fiscal:capture --source=cte_distribuicao')->hourly()->withoutOverlapping()` only when enabled *after* the authorized single-client trial. Do not change job timeout/tries, queue `retry_after`, or the existing NF-e schedule.

  ```php
  if (config('fiscal.cte_scheduled', false)) {
      Schedule::command('fiscal:capture --source=cte_distribuicao')
          ->hourly()->withoutOverlapping();
  }
  ```
- [ ] **Step 4: Verify and commit.** Run focused tests, `php artisan schedule:list`, Pint, `php artisan test --compact`; commit `feat(fiscal): schedule CT-e capture separately`.

## Contract and release gate

The published CT-e manual describes `consNSU` and examples of schema names, but does not prove that five guessed filenames match actual distribution responses. Before shipping, compare sanitized, permissioned CT-e response samples and the official schema package against every family in Task 2; do not label synthetic fixtures “real.” The SOAP action/URLs above are based on a production-tested third-party example, not a live verification from this checkout: run a manually authorized single-client homologation/prod canary and check `cStat`, without exposing certificate material. `openspec validate add-fiscal-document-capture` and full backend tests are required; no Swarm deployment from this plan.
