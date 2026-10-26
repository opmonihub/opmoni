# MarketUP operation-nature backend insights

Recorded on 2026-10-23. Status: research and design candidates; no application change or implementation approval.

## Outcome and scope

The strongest reusable idea is to make an operation nature a named context with allowed CFOPs, an explicit default and product exceptions. Opmoni already has the central reference, company/establishment access controls and historical snapshots. Evolving that foundation would reduce repeated fiscal setup without introducing a second fiscal engine.

The user clarified that the existing Opmoni backend and template remain the base. This record therefore prioritizes backend behavior, rules and integration ideas. MarketUP screens are evidence of functionality, not a visual target.

Source: the user-selected [MarketUP operation-nature screen](https://prontofarmamedicamentos12.marketup.com/index.html#/transaction_nature), inspected through its existing authenticated Paseo tab. Inspected states: list, standard nature, custom nature, new unsaved form, CFOP search, product-exception tab and filter criteria. No business record was saved, deleted, activated, imported or issued during this analysis.

Evidence is in [evidence.json](evidence.json). Native source was inspected at commit `d2763b85220f314f920d31a57ef9a4f8724e2645`. Existing uncommitted work was preserved.

The requested website-rebuild method was used for evidence discipline. This authenticated business application depends on server behavior, corresponding to class D for a complete rebuild. The transferable deliverable here is a functional analysis; no mirror, source transplant or claim to have recovered its backend is made.

## What was actually observed

| ID | Observation | What it establishes |
| --- | --- | --- |
| E01 | The list contained 18 natures covering purchases, sales, returns, transfers and other movements; 16 delete controls were disabled and two were enabled. | A protected baseline coexists with custom records in the client. Server enforcement and installation seeding were not tested. |
| E02 | A standard sale nature exposed separate `Code`, `Name`, `Description`, `OperationTypeID`, `IsActive`, `IsWithinState` and `IsOwnEmitter` fields. | Operation identity, human-readable description, direction and applicability are distinct facts in the client projection. |
| E03 | That nature contained 17 `CfopList` entries, with exactly one `IsMain` entry. The first entry was not the default. | Nature and CFOP are not interchangeable; list order does not define the default. |
| E04 | `IsSystem=true` accompanied disabled general fields in the standard nature. A custom nature had `IsSystem=false`, editable general fields and one CFOP marked as default. | Client behavior distinguishes system-owned identity from custom identity. It does not establish server permissions. |
| E05 | A separate product-exception tab offered CFOP and product selectors. Its collection was empty for the inspected standard nature. | Per-product association is an explicit workflow. No exception was added or exercised in issuance. |
| E06 | Searching CFOP `5102` displayed its code and description in an autocomplete result. | A reusable CFOP reference catalog supports lookup; a free-text code is not the only available interaction. |
| E07 | Loaded API paths included `TransactionNature/GetPagedList`, `TransactionNature/Get`, `Cfop/List` and `TransactionNature/GetCfopItemPagedList`, under `https://api-erp.marketup.com/v1/`. | Nature lists, nature detail, CFOP references and product-exception pagination have separate API resources. HTTP methods, payloads and mutation contracts were not captured. |
| E08 | CFOP entries exposed keys including `ShowNFCe`, `ShowCFe`, `IsCfopST`, `IsCfopSale` and `IsDevolutionCFOP`. | CFOP applicability has richer metadata than a description. These property names do not prove the rules are enforced by the server. |
| E09 | The nature projection included `IsStockMovement=false` in both inspected records. No corresponding editable stock field appeared in their general forms. | A client property exists; it does not prove when stock is moved or authorize mapping it directly to Opmoni ledgers. |
| E10 | A new unsaved nature began with empty CFOP/exception collections and an unselected direction; filter criteria exposed status, code and operation type. | Draft initialization and supported query dimensions can be inspected separately from saved defaults. No filter was applied. |

The official [multiple-CFOP guide](https://suporte.marketup.com/hc/pt-br/articles/208147693-EMITA-UMA-NOTA-OU-CUPOM-FISCAL-COM-MAIS-DE-UM-CFOP) corroborates a default plus product exceptions, explicit saving and subsequent PDV synchronization. Its scope is specific to the documented workflow; it is not a universal tax-rule specification.

The official [fiscal preparation guide](https://suporte.marketup.com/hc/pt-br/articles/37648249374484-FISCAL-Como-usar-a-nova-tela-de-emiss%C3%A3o-para-notas-fiscais) treats operation nature and default CFOP as separate inputs and distinguishes saving a note from issuing it. That issuance flow was not executed in the authenticated session.

## Existing Opmoni foundation

| Existing element | Current evidence | Consequence |
| --- | --- | --- |
| Company-owned operation nature | [ErpOperationNature.php](../../../backend/app/Models/Erp/ErpOperationNature.php), lines 14–25: establishment, one `cfop`, description, direction, metadata and status. | Extend the existing aggregate if approved; do not create a competing operation-nature catalog. |
| Native catalog endpoints | [erp-catalogs.php](../../../backend/routes/erp-catalogs.php), lines 86–90, and [controller](../../../backend/app/Http/Controllers/Api/V1/Erp/Catalogs/ErpOperationNatureController.php), lines 22–89. | Keep `/api/v1/erp/tax-settings/operation-natures`, existing grants, search, response shape and field errors. The current API validates CFOP as a string up to eight characters. |
| Scoped reads, archive and attribution | [ErpCatalogController.php](../../../backend/app/Http/Controllers/Api/V1/Erp/Catalogs/ErpCatalogController.php), lines 35–87 and 100–119. | Keep company/establishment filtering and archival rather than deleting historical meaning. Disabled MarketUP buttons are not a stronger guarantee than these native checks. |
| Sales snapshot | [ErpCommercialDraftService.php](../../../backend/app/Erp/Sales/ErpCommercialDraftService.php), lines 172–173 and 267–275. | Sale-level nature selection is scoped and copied into line snapshots. Future resolution must happen before those facts become immutable. |
| Purchase snapshot | [ErpPurchaseDraftService.php](../../../backend/app/Erp/Stock/ErpPurchaseDraftService.php), lines 218–240. | Purchases already support a nature reference per line. Preserve that contract rather than imposing a sale-level assumption on every workflow. |
| Issuance configuration | [ErpFiscalConfigurationRevisionService.php](../../../backend/app/Erp/Fiscal/ErpFiscalConfigurationRevisionService.php), lines 67–84, and [ErpFiscalIssuanceService.php](../../../backend/app/Erp/Fiscal/ErpFiscalIssuanceService.php), lines 61–67. | Issuance already checks an active nature for the company/establishment and snapshots its facts. A richer resolver must remain coherent with reviewed commercial facts. |
| Fiscal source validation | [ErpFiscalSourceSnapshotFactory.php](../../../backend/app/Erp/Fiscal/ErpFiscalSourceSnapshotFactory.php), lines 114–130; issuance service, lines 470–486. | Existing source-format and return-specific checks remain authoritative. A catalog enhancement must not relax them. |
| New-company provisioning | [ErpCompanyProvisioning.php](../../../backend/app/Erp/ErpCompanyProvisioning.php), lines 19–43. | Current provisioning creates company, membership, establishment and subscription; no operation-nature baseline is inserted there. A baseline initializer would be an explicit addition. |

These are source observations, not freshly executed Opmoni regression tests. The fiscal capture architecture in [fiscal-service.md](../../architecture/fiscal-service.md) remains separate from these ERP issuance ideas.

## Backend ideas worth retaining

Priorities below express expected value for a future design, not approved implementation tasks.

### B01 — High: retain one nature and allow several CFOP choices

**Evidence:** E02–E03. The operation has its own identity and can offer several applicable fiscal codes.

**Native fit:** keep `ErpOperationNature` as the company-owned root. Preserve the existing `cfop` field as the compatible default while designing an optional child collection for additional choices. A future operational code/name may clarify identity, but must not force existing consumers to abandon their IDs or descriptions.

**Rules to specify:** duplicate choices, canonical code format, applicability, archival and how a changed default affects drafts. Reusing a stable nature identity is preferable to duplicating an entire nature merely to add one CFOP.

**Acceptance evidence needed:** old payloads still work; multiple choices remain scoped; new selection belongs to the chosen nature; historical snapshots are unchanged.

### B02 — High: resolve effective CFOP deterministically on the server

**Evidence:** E03, E05 and the official exception guide. The exact MarketUP precedence and rejection behavior were not tested.

**Design candidate:** an authorized explicit line choice → an applicable product exception within the selected nature → the nature default → an unresolved result. Every candidate must pass contextual validation; an explicitly selected invalid or archived reference must produce a field error instead of silently falling back.

Keep the resolution in a focused reusable backend service called by eligible commercial/fiscal preparation paths. It should return the chosen code, source, reference identity and revision. Existing source contracts determine whether the nature comes from the sale, purchase line or fiscal configuration.

**Acceptance evidence needed:** exception beats default; incompatible explicit choice is rejected; no matching default stays unresolved; draft review and posting use the same accepted facts.

### B03 — High: keep tax profiles separate from operational CFOP rules

**Evidence:** E02 and E08; the native product-profile proposal already distinguishes product tax facts from operation facts.

A product profile can supply supported tax fields. Nature, direction, issuer, destination and document model supply operation context. Avoid assigning one universal CFOP to a product and reusing it for every purchase, sale and return.

Reuse the in-flight [product-setup change](../../../openspec/changes/archive/2026-10-24-align-erp-template-and-product-setup/proposal.md) and its [design](../../../openspec/changes/archive/2026-10-24-align-erp-template-and-product-setup/design.md) for the existing profile/default work. Multiple-CFOP associations and product exceptions are additional research candidates; that proposal does not establish that they are approved or implemented.

**Acceptance evidence needed:** profile inheritance does not override operation context or double-apply existing commercial tax amounts.

### B04 — High: freeze the resolved rule and its provenance

**Evidence:** the native sales, purchase and issuance snapshots already preserve operation facts. MarketUP persistence/history behavior was not tested.

Record the effective CFOP and resolution source with the operation before it becomes immutable. A later nature/default/exception edit should affect newly resolved or explicitly refreshed eligible drafts. It must not rewrite posted sales, purchase history, stock valuation or fiscal source snapshots.

Configuration updates should be atomic and revisioned if the accepted design introduces mutable child rules. Concurrent edits must yield one coherent rule set or a recoverable conflict.

**Acceptance evidence needed:** a default edit leaves posted facts unchanged; stale revision is detected; no mixed-revision resolution; retries do not duplicate downstream effects.

### B05 — Medium: offer a protected, reviewable operational baseline

**Evidence:** E01 and E04. The client contains protected records, but their provisioning mechanism was not recovered.

A versioned company initializer could offer basic operation categories through native catalogs. Keep it additive and idempotent, preserve existing choices, and make conflicts visible. Reuse the proposed initialization capability instead of importing account-specific MarketUP records.

Baseline categories are not tax approval. Do not infer a company's regime, rates, destination compatibility or actual CFOP choices from the inspected tenant. Protection rules must exist in the backend, not only as disabled controls.

**Acceptance evidence needed:** reruns do not duplicate records; the correct company/establishment owns each record; custom records survive; used references remain readable after archival.

### B06 — Medium: validate applicability before irreversible effects

**Evidence:** E08; native issuance already applies format, model/source and return checks.

Use a typed context for direction, origin/destination, issuer/establishment, document model and supported operation kind. Avoid reducing every destination case to a single within-state boolean or interpreting client flags as a complete tax engine.

Validate supported choices during preparation and revalidate at the existing posting/issuance boundaries. A new implementation must preserve current returns, authoritative totals and source-provenance checks.

**Acceptance evidence needed:** incompatible context produces actionable field errors before stock/financial effects; unsupported scenarios remain explicitly unsupported.

### B07 — Medium: make reference and exception lookup reusable and bounded

**Evidence:** E06–E07 and E10. Reference lookup and product exceptions are distinct resources; exceptions have a paged read path.

A shared backend CFOP reference lookup could return code, description and supported applicability metadata. Nature choices and product exceptions should stay company scoped and searchable, with bounded pagination. Existing catalog APIs already supply part of this infrastructure.

Keep any future API under native `/api/v1` conventions. The observed MarketUP endpoint names are evidence, not a reason to copy its route naming, authentication or client stack.

**Acceptance evidence needed:** foreign references cannot be enumerated or attached; large exception sets stay paged; search and authorization semantics match native catalogs.

### B08 — Boundary: separate configuration from ledger execution

**Evidence:** E09 and the native commercial/fiscal boundaries.

Do not make a nature checkbox directly post stock or finance. Opmoni already has transaction-specific participants, accepted source facts and idempotent posting/reversal behavior. A future operation policy would have to be explicitly integrated with those mechanisms.

Similarly, saving configuration, saving a commercial draft, posting an operation and transmitting a fiscal document are separate transitions. The official guide's save/issue distinction is useful; it does not establish that their effects are identical to Opmoni's.

**Acceptance evidence needed:** editing a nature has no ledger effect; posting/retry/reversal preserves the existing exactly-once effect guarantees covered by the relevant native tests.

## Suggested order for a later implementation

1. Specify one compatible nature/default contract and the contexts each native consumer supplies.
2. Add a focused resolver with provenance, then validate it against sales, purchase-line and fiscal-configuration contracts.
3. Add allowed CFOP associations and product exceptions only within that accepted contract.
4. Connect reviewed setup/default initialization and profile inheritance without changing existing historical facts.
5. Verify scope, atomicity, revision races and existing posting/issuance boundaries before extending supported scenarios.

This research does not change canonical OpenSpec requirements, the in-flight checklist, production fiscal gates or `ERP_REGISTRATION_ENABLED`. Any accepted addition belongs in the existing change workflow.

## Validation and limits

Verified by navigation, DOM inspection, selected client-state fields, resource metadata and official help pages. The existing MarketUP tab was reused and returned to the original list at its initial 1046×612 viewport; no new tab was created. Raw authenticated screenshots, tokens, account/user labels, products and API bodies are excluded from this record.

Unknown: MarketUP server technology/schema, mutation contracts, permissions, transactional boundaries, fiscal correctness, exception precedence in real issuance, history guarantees and PDV delivery/synchronization behavior. A property or disabled button is not evidence of server enforcement.

No Opmoni application code or configuration was changed. Backend/frontend suites and live fiscal/provider tests were not run for this documentation-only research. Validation of the deliverable covers JSON structure, local source links, evidence consistency and Git scope.
