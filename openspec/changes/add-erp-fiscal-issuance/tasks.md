# Implementation tasks: Fiscal issuance and document lifecycle

Prerequisites: [add-erp-core-catalogs](../add-erp-core-catalogs/proposal.md), [add-erp-purchasing-and-stock](../add-erp-purchasing-and-stock/proposal.md), [add-erp-sales-and-orders](../add-erp-sales-and-orders/proposal.md)

All tasks below are implementation work and remain unchecked during proposal preparation. Artifact completion is not feature completion; every task must use current evidence before checking.

## 1. Fiscal contract and runtime compatibility

- [x] 1.1 Inventory each supported source fiscal model/event/contingency/print action and official schema/provider requirements; verify every core action has a concrete API and acceptance fixture.
- [x] 1.2 Assess and pin compatible fiscal/rendering dependencies for Laravel 13/PHP and PostgreSQL; verify generated certificate/XML/PDF fixtures before replacing source adapters.
- [x] 1.3 Add company fiscal configuration, encrypted certificate, document/number allocation, attempt/event and private-artifact persistence; verify constraints, secret redaction and additive migrations.

## 2. Provider lifecycle and UI

- [x] 2.1 Implement the shared durable fiscal intent/attempt/number-allocation state machine and coherent certificate revision snapshots; verify after-commit dispatch, rollback and no mixed credential revision.
- [x] 2.2 Adapt NF-e generation/signature/transmission/consultation against local schema/provider fixtures; verify authorization, rejection and accepted-but-timeout reconciliation without blind resubmission.
- [x] 2.3 Adapt NFC-e generation/signature/transmission/consultation against local fixtures; verify its model-specific required inputs and truthful authorization state.
- [x] 2.4 Adapt CT-e issuance and complement/substitution actions against local fixtures; verify correlated source-document references, model-valid events and preservation of accepted history.
- [x] 2.5 Adapt MDF-e issuance, consultation and closure against local fixtures; verify transport/reference validation, authorized closure and repeated-event idempotency.
- [x] 2.6 Implement NF-e/NFC-e cancellation/correction, applicable returns and number invalidation; verify each model permits its requested event and failed events preserve original authorization.
- [x] 2.7 Implement explicit source-supported NFC-e contingency and later reconciliation; verify local/contingent output never claims provider authorization.
- [x] 2.8 Implement encrypted issuer certificate upload/rotation and private XML/PDF/auxiliary rendering APIs; verify real file bytes, length/hash, safe metadata and cleanup on all failure paths.
- [x] 2.9 Build company fiscal setup and certificate/configuration forms; verify permissions, password/secret clearing, current revision and retryable failed saves.
- [x] 2.10 Build NF-e/NFC-e draft/document/event/printing screens; verify model field errors, duplicate-submit protection and distinct pending/rejected/indeterminate/authorized states.
- [x] 2.11 Build CT-e/MDF-e draft, complement/substitution/closure and document screens; verify supported model actions, transport field preservation and actual provider outcomes.
- [x] 2.12 Implement the durable authorized-artifact contract for accounting delivery; verify storage failure, corrupt bytes and incomplete XML never publish a ready artifact.

## 3. Concurrency and acceptance

- [x] 3.1 Run independent PostgreSQL/Redis numbering/transmission/event/artifact races and local HTTP/mTLS failure contracts; verify single effects and no secret/temporary-file leaks.
- [x] 3.2 Run regression and browser suites, then record separately authorized scoped homologation canaries per model; verify missing credentials remain explicit unverified acceptance and do not enable production gates.

## Verification commands and evidence boundaries

Create the domain suites with the planned filter prefixes below and require a nonzero executed-test count. Use the repository-layout backend environment: `cd backend && FISCAL_ENVIRONMENT=producao vendor/bin/phpunit --filter 'ErpFiscal|ErpNfe|ErpNfce|ErpCte|ErpMdfe'`, expanding to the complete regular suite for cross-cutting final verification. Run `vendor/bin/pint --dirty --format agent` for changed PHP. The standard test bootstrap forces SQLite/array isolation; never substitute the development database.

For required process races, use `vendor/bin/phpunit -c phpunit.pgsql.xml --filter 'ErpFiscalConcurrency'` in the guarded backend test runtime with `opmoni_test`, isolated Redis 13/14/15 and independent processes. Follow README setup and destructive-fixture guards; mock providers and protect development artifacts/data. A SQLite test or sequential transaction is not process-race evidence.

Run `pnpm --dir frontend lint`, `pnpm --dir frontend typecheck`, `pnpm --dir frontend test`, `pnpm --dir frontend build`, and scoped desktop/mobile/SSR browser acceptance. Repeat only after changes or failures justify it. Separate fake/local transport evidence, independent-process evidence, deployed storage/recovery and authorized live-provider canaries. Validate planning changes with `openspec validate --all --strict --no-interactive`.

### Evidence (2026-10-17)

- `ErpFiscalIssuanceCompleteTest` (model transmit/consult/contingency/cancel/production gate per model 55/65/57/58/operator-canary status); `ErpFiscalActionCatalog`; `FakeErpFiscalProvider` + `ErpFiscalIssuanceService` routes (`transmit`, `consult`, `contingency`, events/invalidations `submit`, XML/PDF download)
- `ErpFiscalAccountingConcurrencyTest` + `ErpModulesConcurrencyTest` (PostgreSQL/Redis independent workers, 10/10)
- Frontend `/erp/fiscal` hub + `erpFiscalContract` tabs (`pnpm --dir frontend test`, 685 pass)
- SQLite focused suite (`ErpFiscal|ErpRelease|FiscalScienceRolloutGate|FiscalScienceLive|ReconcileFiscalXmlAccounting`): **36 tests OK**
- Live SEFAZ homologation/production canaries per model (task 3.2): **unverified** — credenciais/escopo autorizado ausentes; `ERP_FISCAL_PRODUCTION_ENABLED` false; harness `/erp/fiscal/operator-canary/status` reporta `live_verification: unverified`; transmissão produção bloqueada com `fiscal_production_blocked` em todos os modelos
