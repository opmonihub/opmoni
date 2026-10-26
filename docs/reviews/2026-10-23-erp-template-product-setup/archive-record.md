# ERP template and product setup — archive record

Recorded 2026-10-24. Change: `align-erp-template-and-product-setup`. Schema: `spec-driven`.

## Delivery and archive

Implementation was integrated and pushed to `main` at `f8efb50ad69e23a872e5b65ab18fff48b86f3590` before this documentation workflow. The selected change has **58 completed tasks and zero incomplete tasks**; proposal, specs, design and tasks were all complete in the native artifact graph.

The user selected synchronization before archive. All eight delta paths came from the native status artifact inventory. One successful specs-instruction snapshot was fetched and reused by the inline semantic merge. Existing Purpose sections, unrelated requirements and surviving scenarios were preserved. Both new capabilities supplied their own populated Purpose sections; no TBD Purpose was introduced.

Archive: [2026-10-24-align-erp-template-and-product-setup](../../../openspec/changes/archive/2026-10-24-align-erp-template-and-product-setup/proposal.md). All 12 original artifacts moved, including the unchanged `.openspec.yaml`. Proposal/design links were rebased for the additional directory level. Incoming documentation and ledger paths now refer to the archive.

## Canonical synchronization

| Capability | Added requirements | Modified requirements | Disposition |
| --- | ---: | ---: | --- |
| access-pages | 1 | 0 | Existing spec extended |
| erp-company-foundation | 4 | 0 | Existing spec extended |
| erp-company-initialization | 5 | 0 | New canonical capability |
| erp-core-catalogs | 6 | 0 | Existing spec extended |
| erp-finance-and-cash | 0 | 1 | Existing scenarios retained and extended |
| erp-fiscal-issuance | 1 | 1 | Existing scenarios retained and extended |
| erp-product-tax-profiles | 5 | 0 | New canonical capability |
| erp-sales | 2 | 2 | Existing scenarios retained and extended |
| **Total** | **24** | **4** | **No removals or renames** |

Every selected delta requirement was compared against its resulting canonical block before the move. No delta operation headers were copied into canonical specs. Reapplying the selected deltas has no remaining content to merge.

## Verification

- `openspec validate align-erp-template-and-product-setup --strict --no-interactive`: exit 0 before the move.
- `openspec validate --specs --strict --no-interactive`: exit 0 before and after the move; **29 specifications passed, zero failed**.
- `openspec validate --archived --json --no-interactive`: exit 0; **15 archived changes passed**, including this change with no issues.
- `openspec list --json`: no active changes after archiving.
- Final full-set comparison confirmed all 28 delta requirement blocks in their canonical specs; 129 ledger rows and all original tasks/metadata were preserved. Local Markdown checks passed for 206 links, with zero missing targets.
- The tracked non-spec/document source snapshot matched its pre-operation digest (2,654 inventory entries, with the declared README documentation edit accounted for); the unrelated implementation-gap report remained byte-identical.
- Application sources/configuration were not modified. This workflow updates documentation/specifications only; application suites, browsers and external providers were not rerun.

## Evidence retention and remaining limits

The [final local acceptance](final-local-acceptance.md), [execution inventory](task-execution-status.md), [evidence map](evidence-map.md), [coverage ledger](acceptance-coverage.csv), [integrated validation](integrated-validation.md) and tracked captures retain the published acceptance record. Recorded complete checks include 1,817 frontend tests; 3,467 passing regular backend tests with three optional live-provider skips; 229 PostgreSQL/Redis integration tests; and 96 sequential real-loopback HTTP steps. Those are dated executed results, not fresh runs from this archive operation.

After integration, the user explicitly requested removal of auxiliary worktrees, local worker branches, private finishing archives and the branch recovery bundle. Those were removed. References elsewhere to ignored `.superpowers` files or former worktrees identify historical collection locations; their raw private files are not claimed to remain available. Tracked reports, recorded hashes and published source/captures remain in Git. Existing application configuration, APP_KEY and development stores were preserved.

The archive does not enable ERP registration, commercial release, fiscal production dispatch, SERPRO or WhatsApp credentials. Live provider acceptance, production canaries, physical printing, external payment-provider dispatch and complete accessibility certification remain outside the delivered local acceptance. Fiscal capture/issuance remains inside Laravel; no fiscal microservice or additional business module was introduced.

The separately prepared `2026-10-24-implementation-gap-map.md` is a new backlog analysis at application baseline `f8efb50`; it is preserved independently and does not reopen these 58 completed implementation tasks.
