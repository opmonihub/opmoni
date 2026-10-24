# CFOP reference advisory for task 1.4

Research date: 2026-10-23 (America/Sao_Paulo). Native checkout: `7ad0994`, isolated research worktree `align-erp-cfop-research`. This research was integrated as task 1.4 documentation in the implementation worktree based on `6b1d00d`; the reviewed workbook, parsed rows and two official PDFs are retained under [`cfop-sources/`](cfop-sources/). The research supports design decision 8 and later task 7.6. The current batch changes documentation only: no application/spec implementation, reference adoption, nature seeding, legal classification, migration, provider action or production acceptance is delivered.

## Findings

An official, downloadable reference exists: Portal NF-e's **Tabela de CFOP published 04/09/2026**, identified by **Informe Técnico 2023.002 v2.10**. Its complete XLSX was downloaded and parsed successfully, not merely discovered in the portal index. It is suitable as the provenance for a future code-owned read-only dataset, subject to retaining its exact source facts and a separate versioned applicability overlay.

The table's `indNFe` is not a sufficient NFC-e eligibility flag. The current official technical baseline located is **NT 2026.002 v1.11**, listed by the portal on **01/10/2026**, whose cover/version history says September 2026. It includes a more recent I08-150 list than the November 2020 MOC. In particular `5910` and conditional `5949` observations must not be lost by presenting the MOC list as exhaustive today. None of these flags supplies the taxpayer's tax treatment.

Native operation natures are scalar free-text defaults today. Commercial and purchase snapshots preserve that scalar with different shapes; linked fiscal validation compares item CFOP with the frozen commercial line, while current issuer nature is separately copied into the fiscal source. The extension must preserve old meanings, introduce explicit effective/default/provenance separation only in new versions, and avoid certifying or normalizing old strings.

## Authoritative sources and reproducible provenance

Only government NF-e/CONFAZ/SEFAZ material supports the fiscal statements below. Non-government search results were not adopted as evidence. The native MarketUP report is functional evidence only.

| ID | Official source | Verified date/version and use |
| --- | --- | --- |
| S1 | [Portal NF-e — Diversos](https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=%2FNJarYc9nus%3D) | Current retrieved HTML lists the 04/09/2026 CFOP table, as well as older 25/08/2026 and 11/04/2023 publications. The latest listed table was selected deliberately. |
| S2 | [Official XLSX — CFOP v2.10](https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=74KmX8poGpM%3D) | Downloaded full body, 65,625 bytes, XLSX, attachment filename `IT_2023_002_v2_10_Tabela_CFOP_Descrição.xlsx`; SHA-256 `577e05eec452294945d0e9df1f9bb9b21a4af115938e75ec74cf6a541ae4dacf`. |
| S3 | [IT 2023.002 v2.10](https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=GyPA%2FcEjrMk%3D) and [official current IT index](https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=B%2F6oigHgyAw%3D) | Portal publication 04/09/2026; PDF cover September 2026, 5 pages, 245,017 bytes; SHA-256 `dd47206ec74040b391cf015ef1f1e55e158c0d3f8b339153667e64f379092b86`. Pages 4–5 explain columns and legal provenance. |
| S4 | [CONFAZ — Convênio SINIEF s/nº, 15/12/1970](https://www.confaz.fazenda.gov.br/legislacao/ajustes/sinief/cvsn_70) | The originating norm identified by the official IT, especially Anexo II and subsequent Ajustes SINIEF. Article 5 was accessible through web retrieval; full consolidated Annex II comparison was not completed because repeated subsequent requests timed out. |
| S5 | [MOC 7.0, Anexo I — official CONFAZ PDF](https://www.confaz.fazenda.gov.br/legislacao/arquivo-manuais/moc7-anexo-i-leiaute-e-rv.pdf) | Cover November 2020, 153 pages; browser retrieval parsed the PDF. Field I08 on printed p18; rules I08 on p91–93; recipient/export rules on p83–84. It supplies the underlying rules, with later NTs applied rather than ignored. |
| S6 | [NT 2026.002 v1.11](https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=m8aACnFLK%2FY%3D) and [official current NT index](https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=6WfrpZYE4Ik%3D) | Portal publication 01/10/2026; cover September 2026; 24 pages, 752,233 bytes; SHA-256 `72c033b2c7490710f61488a14d0bb3d7bb54e5345aa8d33d6ff2c270c228f046`. Printed p8–9 and p12 contain the relevant 55/65 restrictions and CFOP list. |
| S7 | [Portal NF-e — notices](https://www.nfe.fazenda.gov.br/portal/informe.aspx?AspxAutoDetectCookieSupport=1&ehCTG=false&page=0&pagesize=30) | The 05/10/2026 notice postpones named NT deployments, including 2026.007 v1.10, to 26/10 homologation and 16/11 production. Do not activate exclusive-IBS/CBS support based only on the older IT schedule. |

S3 says the new title/explanatory text is informational and does not itself change code usage. The legal text and subsequent Ajustes SINIEF prevail over its reproduction. Version identity, publication date, per-code validity and provider deployment dates are separate facts. Its older v2.00 schedule for `indExcIBSCBS` does not establish that the corresponding capability is in production on the research date. [IT v2.10](https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=GyPA%2FcEjrMk%3D), [deployment notice](https://www.nfe.fazenda.gov.br/portal/informe.aspx?AspxAutoDetectCookieSupport=1&ehCTG=false&page=0&pagesize=30).

The portal's ASP.NET cookie-detection redirects looped with stateless fetches. Successful downloads used a normal cookie-preserving Python `urllib` opener; no authentication, bypass or business endpoint was involved. Record source URL plus hash, not a session URL. Hashes above identify the actual bytes inspected; no hash is claimed for an undownloaded consolidated legal annex.

### Complete dataset parsing result

S2 contains one sheet named `CFOP`, 620 populated rows including the header, **619 distinct codes**. All codes match four digits with initial digit in `1,2,3,5,6,7`; all rows contain a title and an explanatory note. `indNFe` is `1` for 585 records and `0` for 34. All end-validity cells are blank in this particular publication. Dates are spreadsheet dates, not publication dates; for the examples below, the start is 2006-01-01. That does not mean those codes were legally created on that day.

The 14 source columns are `CFOP`, start validity, end validity, `indNFe`, `indComunica`, `indTransp`, `indDevol`, `indRetor`, `indAnula`, `indRemes`, `indComb`, `indExcIBSCBS`, title and explanatory note. There is **no `ShowNFCe`, `IsCfopSale`, taxpayer regime mapping or tenant nature mapping**. `indComb` actually contains `0`, `1` and `2`, so a lossless ingestion must not cast every indicator to Boolean or invent a meaning for `2` without further official verification. [Official workbook](https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=74KmX8poGpM%3D).

## Canonical representation and verified examples

New API/reference identities should be strings such as `5102`. `5.102` is the dotted presentation used in normative/manual text, not a different choice. IT v2.10 defines four-digit codes; MOC field I08 is numeric length four. Keep dotted display/search presentation separate from canonical persistence and XML. This is not permission to strip punctuation or otherwise rewrite existing native rows or snapshots in bulk. [IT](https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=GyPA%2FcEjrMk%3D), [MOC p18](https://www.confaz.fazenda.gov.br/legislacao/arquivo-manuais/moc7-anexo-i-leiaute-e-rv.pdf).

The following are verified reference identities, **not proposed tenant presets or recommended classifications**. Meaning is abbreviated; the future lookup should retain the full official title/note rather than substitute these summaries. All are present in S2 with blank end validity.

| Canonical / dotted | Reference meaning, abbreviated | Verified context consequence |
| --- | --- | --- |
| `5101` / `5.101` | Sale of own production | `indNFe=1`; appears in current I08-150. Classification still needs the actual facts. |
| `5102` / `5.102` | Sale of third-party goods; current title also describes MEI exceptions | `indNFe=1`; current I08-150 candidate; the native common test code. Do not shorten the official title permanently. |
| `6102` / `6.102` | Corresponding interstate reference, with MEI qualifications | `indNFe=1`; not a 65 candidate. This is not a universal code for all interstate sales. |
| `7102` / `7.102` | Sale of third-party goods in the export group | `indNFe=1`, but export preparation is unsupported by the current domestic-only source shape. |
| `5405` / `5.405` | Third-party goods under ST as substituted taxpayer | `indNFe=1`; I08-150 includes it. Presence never determines CST/CSOSN, rates or whether ST applies. |
| `1102` / `1.102` | Purchase for commercialization | `indNFe=1`; preserve purchase/import compatibility, not goods-sale resolution. |
| `1202`, `2202` / `1.202`, `2.202` | Returns of third-party goods sales, with current MEI qualifications | `indNFe=1`, `indDevol=1`; preserve the native restricted return contract. |
| `5353` / `5.353` | Transport service to a commercial establishment | `indNFe=0`, `indTransp=1`; CT-e builder fixture, outside goods 55/65 choices. |
| `5910`, `5949`, `5933` | Bonus/donation; other unspecified output; ISS service | Real reference records, but each has additional operation/model constraints described below. |

Evidence: [official workbook S2](https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=74KmX8poGpM%3D), [current I08-150 in NT v1.11 p12](https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=m8aACnFLK%2FY%3D). Dataset existence and model admissibility are not a legal approval of any particular transaction.

## Supported context checks and explicit limits

### NF-e 55: goods-sale preparation

Use actual company/authorized establishment, direction and reviewed origin/destination facts. In the MOC, I08-10/20 distinguish inbound (`1/2/3`) from outbound (`5/6/7`); I08-30/40/50 link the operation group to `idDest` 3/2/1 respectively. I08-60/70/90 have UF/consumption/presence exceptions. Prefix checks are necessary structural checks, not proof that the operation is a sale or that a tax rule applies. [MOC p91–92](https://www.confaz.fazenda.gov.br/legislacao/arquivo-manuais/moc7-anexo-i-leiaute-e-rv.pdf).

For the bounded addition, a useful supported baseline is a domestic outbound goods sale with a selected model, known issuer/transaction destination, normal purpose, a reference-valid active code and verified sale semantics. Missing destination/model must yield unresolved applicability, not assumed internal sale. A lookup with incomplete context may still return verified reference facts, but must distinguish them from an eligible resolved operational choice.

Do not classify solely by comparing contact address UF to issuer UF: counter sales, actual delivery/consumption and the MOC exceptions can differ. A cautious implementation can require review or leave exceptional contexts unsupported instead of encoding an incorrect universal rule. A product's `origin_code` is goods-origin tax classification; it is not the transaction origin UF.

### NFC-e 65: a separate explicit overlay

NT v1.11 B11-10/B11a-10/B25-20/B25a-10 require output, internal destination, normal purpose and final consumer; B25b-20 allows presence indicators 1, 4, 5. I08-150 lists `5101,5102,5103,5104,5115,5405,5656,5667,5910,5933`. It additionally describes possible `5949` exceptions for RS (CSOSN 900 or CST 90) and SP (CSOSN 900 or CST 40). Preserve these as conditional facts; do not assume either UF has enabled a discretionary exception. [NT v1.11 p8–9, p12](https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=m8aACnFLK%2FY%3D).

The reference list and the app's supported **goods sale** subset are separate. `5910` does not authorize introducing a new donation/remittance workflow; `5933` does not add ISS service issuance; fuel entries require facts/builders not proved by this research. `5667` describes an out-of-UF customer's fueling in the issuer's UF, illustrating why destination cannot be inferred from a title or mailing address alone. Keep these cases reference-readable but unresolved/unsupported for new operational selection until supported evidence exists.

NF-e 55 with DANFE Simplificado Tipo 2 (`tpImp=6`) also falls under the newer retail restrictions in S6. Current native source validation permits print types 0–5 only. Do not mark all 55 contexts equivalent or add `6` as part of this lookup task. The v1.11 postponement to 14/12/2026 names other reference-document rules; it is not a blanket declaration that every rule or every CFOP list is deferred. Preserve per-rule timelines rather than deriving one effective date for the whole PDF.

### Cases to retain without claiming support

| Case | Reference/catalog treatment | New fiscal preparation treatment |
| --- | --- | --- |
| Missing model, direction or required origin/destination | Retain selected value and show known reference facts | Unresolved, with missing-context fields; basic non-fiscal drafts retain their existing contract. |
| Unknown legacy text, dotted string, malformed or removed code | Read its exact original value; unrelated edits remain possible | Unresolved until deliberate verified confirmation/correction; never silently rewrite history. |
| Valid reference but unsupported special operation | Keep identity/title/version available for history and hydration | Explain unsupported context; do not claim the code itself is nonexistent/illegal. |
| Export (`7xxx`, foreign recipient/EX) | Reference exists and stays readable | Unsupported current source contract, not a new international workflow. |
| NFC-e interstate/export or inbound choice | Preserve the reference identity | Reject the explicit incompatible candidate; never fall through to default. |
| NFC-e `5949` possible UF exception | Conditional metadata, no universal eligible flag | Unverified unless UF choice and complete applicable facts are independently verified. |
| Exclusive-IBS/CBS indicator | Preserve source value losslessly | No new issuer regime or rollout; not current readiness proof. |
| Lookup artifact unavailable/corrupt | Preserve current form/selection and saved row | Explicit recoverable unavailable state; neither empty set nor automatic fallback. |

Export is a concrete native limitation: `ErpFiscalSourceSnapshotFactory.php:104–111,460–479` requires domestic CPF/CNPJ, Brazilian-state UF and CEP; `Nfe55DocumentBuilder.php:110–118` only emits CPF/CNPJ for a recipient. Thus syntactically accepting a `7xxx` code does not supply foreign participant/EX support. The official export fields are documented in MOC group E and rule E03a-10; native support must not be fabricated by lookup metadata. [MOC](https://www.confaz.fazenda.gov.br/legislacao/arquivo-manuais/moc7-anexo-i-leiaute-e-rv.pdf).

## Native compatibility inventory

All paths here are relative to this checkout. These are direct source observations, not freshly passing workflow tests.

| Area | Existing source/shape | Compatibility requirement |
| --- | --- | --- |
| Aggregate/storage | `backend/app/Models/Erp/ErpOperationNature.php:14–27`; migration `2026_10_17_180000_create_erp_core_catalog_tables.php:140–151`: company, optional establishment, string `cfop` length 8, description, movement default `out`, JSON metadata, status | Keep IDs/scopes, original strings and unrelated metadata; no reference version, choice children or nature configuration revision exists in this baseline. |
| API | `ErpOperationNatureController.php:27–38,74–86`: `cfop` required on create and optional on partial update, string max8; `movement` optionally in/out; serialization returns scalar and metadata | Old payload remains scalar; stricter validation applies to deliberately configured new canonical choices without invalidating unrelated legacy edits. |
| Routes/catalog | `backend/routes/erp-catalogs.php:86–90`; base `ErpCatalogController.php:35–87` | Reuse native authorized company lookup and recoverable envelopes; existing DELETE archives rather than erasing history. |
| Sales input | `ErpCommercialDraftService.php:172–173,267–275` | A sale-level nature is scoped, then each line freezes `product_snapshot.operation_nature = {id,cfop,description,movement,erp_establishment_id}` or null. No per-line explicit CFOP input/resolution exists yet. |
| Commercial versions | Same service `:29,523–544` | `commercial-v1`; full snapshot contains lines, party, establishment and `fiscal_context = {model:null,schema_version:null,readiness:'unverified',issuer_resolution:null}`. Acceptance adds actor/time/draft revision; it is not model-specific legal readiness. |
| Saved edits/conversions | Same service `:321–428,553–579` | Retained-line edits preserve line facts; changing nature without explicit lines errors. Quote/order conversion copies frozen line snapshots. New reference data must not silently opt old drafts into new resolution. |
| Fiscal configuration | `ErpFiscalConfigurationRevisionService.php:67–84` | `models[55/65].operation_nature_id` must be an active company nature applicable to issuer establishment. It does not establish reference-code validity or tax legality. |
| Fiscal preparation | `ErpFiscalIssuanceService.php:45–88` | Current issuer settings copy `{id,description,cfop,movement,fiscal_metadata}` to top-level `source.operation_nature`, replacing its description in identity. This is distinct from frozen line facts and must not become a replacement resolver. |
| Linked posted sale | Same service `:498–540`; `ErpCommercialDraftService.php:531–535` | Reads accepted posted snapshot, compares total/count/party/classification and **item CFOP to the line's frozen `operation_nature.cfop`**. Do not weaken equality or fetch today's default to repair a missing snapshot. |
| Typed source/XML | `ErpFiscalSourceSnapshotFactory.php:114–136,215–218`; `Nfe55DocumentBuilder.php:42–68`; `Nfce65DocumentBuilder.php:11–16` | Items require four digits matching `[123567]`; XML uses item CFOP directly. Format checking alone does not validate table membership/direction/UF/NFC-e list. The NFC-e builder checks output/final consumer but its message about internal sale does not itself enforce destination/list. |
| Purchases | `ErpPurchaseDraftService.php:25,218–240,283–302` | `purchase-draft-v1` accepts a nature per purchase line and freezes `{id,description,cfop,erp_establishment_id}` **without movement**; also `source_item` and `legal_applicability:null`. Preserve that shape and lack of fabricated provenance; no automatic purchase resolver. |
| Existing returns | `ErpFiscalIssuanceService.php:458–495` | Posted source return, accepted line provenance, input movement, `tpNF=0`, and exact `1202`/`2202` restriction; items equal the configured return code. New nature readers must not widen this workflow or force sale-output assumptions onto it. |

Important incompatibilities to make explicit in implementation review:

1. Native max8 text includes values that fiscal four-digit validation cannot consume. A new lookup must not imply every readable legacy default is ready to issue.
2. Root `source.operation_nature.cfop` and line-effective CFOP are not currently separately versioned. New snapshots need the approved effective `operation_nature.cfop`, explicit `default_cfop` and independent `cfop_resolution`; old shapes must keep their existing meaning without invented revision/reference metadata.
3. Issuer default selection can differ from a previously selected/frozen commercial nature. New preparation must fail incompatible configuration before dispatch instead of substituting issuer choice; preserve frozen-source comparisons.
4. `postedSnapshot()` requires posted status and accepted marker, while `acceptedSnapshot()` explicitly checks `commercial-v1`. Adding a new version requires auditing both paths and dependent return readers, not just the snapshot writer.
5. Sale/purchase shapes differ, and some historical tests freeze only `{cfop:'5102'}`. Consumers cannot unconditionally require new nature IDs/revisions in old snapshots or backfill them from today's catalog.

### Existing examples/tests inspected

| Native evidence | Present reference example and what it proves |
| --- | --- |
| `backend/database/factories/Erp/ErpOperationNatureFactory.php:19`; `backend/tests/Support/BuildsErpFiscalHarness.php:53,84–95` | `5102`; harness derives fiscal CFOP from the accepted sale snapshot. Not tax-treatment approval. |
| `backend/tests/Feature/ErpCommercialDraftWorkflowTest.php:250–270` | Sale nature supplied as a native reference; workflow supports catalog snapshot immutability. |
| `backend/tests/Feature/ErpFiscalIssuanceCompleteTest.php:868`; `ErpFiscalDurableAttemptTest.php:393` | Historical/minimal line shape `operation_nature:{cfop:'5102'}`. Must remain readable according to old contract. |
| `backend/tests/Feature/ErpFiscalDurableAttemptTest.php:306` | Return nature `1202`, movement `in`; source checks are deliberately restricted. `2202` is supported in production code but this inspected test does not establish specific coverage for it. |
| `backend/tests/Feature/ErpFiscalModelBuilderSchemaTest.php:527,550` | Goods XML item `5102`; typed CT-e service `5353`. A schema-valid fixture is not a live SEFAZ acceptance test. |
| `backend/tests/Feature/ErpLegacyPortTest.php:130,272,296`; `ErpXmlAndFiscalDeepTest.php:170` | Imported/read XML uses `5102` and purchase `1102`; reference extension must not mutate imported facts. |
| `backend/tests/Feature/ErpCatalogAttributionTest.php:132`; `ErpCatalogTask5ContractTest.php:495` | Single scalar CFOP payload and attribution remain compatibility cases. |

No existing test observed here proves the future lookup, NFC-e reference filtering, dotted-input compatibility, updated official list enforcement or new resolution provenance. These are implementation acceptance gaps, not claimed failures of the present task.

## Minimum actionable strategy for later task 7.6

1. **Pin an immutable generated reference artifact in code**, with proposed identity `nfe-it-2023.002-v2.10-2026-09-04`, official URL, publication, retrieval date, source hash, transformation version and row-count assertion. Preserve canonical code, official title/note, source dates and raw indicators. Generate at development/build time, not by live fiscal-site fetch during a sale. The ID is a proposal, not an already-delivered native contract.
2. **Keep reference truth separate from supported applicability.** Add a small versioned code-owned overlay citing rule IDs/source/version for supported goods sale 55/65 contexts. `indNFe=1` alone cannot populate a universal `models:['55','65']`, and prefix5/6 alone cannot classify all operations as sales. Use explicit unknown/unsupported states for rules not verified. Do not derive rate/CST/CSOSN/ST treatment or auto-create a nature.
3. **Expose the approved bounded route** `/api/v1/erp/tax-settings/cfops` under existing company/nature-index authority. Reuse native pagination limits/envelopes and authorization. Permit stable code search/title search and selected-record hydration outside a page, while returning source version and applicability status/reasons. A reference is global code-owned data; an authorized lookup still does not grant foreign nature/product access.
4. **Filter only what context proves.** Resolve company/establishment access first, then code validity, direction, operation/destination facts, selected model and supported sale semantics. A missing model cannot be silently 55; missing destination cannot be silently internal. Unknown contextual eligibility must not remove the selected reference from historical hydration or appear as an empty reference dataset.
5. **Preserve legacy scalar behavior.** Read existing `cfop` exactly, expose its implicit one-choice set and unverified status where needed, allow unrelated edits, and require deliberate new canonical confirmation before fiscal preparation claims resolution. No bulk normalization, migrated default assignment, MarketUP flags, tenant choice mappings or active fiscal-nature presets.
6. **Test reference-service behavior with independent expected evidence:** pinned count/hash/uniqueness/headers; selected examples with source indicators; four-digit representation; direction/model mismatch; updated NFC-e list distinctions; unsupported export/special cases; missing-context unresolved; authorized hydration/pagination; unavailable/corrupt artifact preserves selections. Verify raw indicator `2` survives. Native old scalar API and old sales/purchase/return snapshot regressions belong in tasks 7.7–8.6, with no provider claim from these tests.

The minimum set of **positive test contexts**, not presets, can use the already-native `5102` for a reviewed domestic outbound goods transaction under 55 and 65, and an explicitly reviewed interstate example under 55 such as `6102`. They must include the actual nature, establishment, movement, destination and model facts. Do not present `6102` as a universal choice for every buyer or replace unresolved tax facts with fixture values. Confirmed existence of `5405` can test conditional metadata without auto-assigning ST treatment.

## Scope discipline and remaining limits

Read canonical `openspec/specs/erp-core-catalogs`, `erp-sales`, `erp-fiscal-issuance`, the corresponding approved change deltas, design decisions 8–9, tasks 1.4/7.6–8.6 and `docs/architecture/fiscal-service.md`. Canonical history, company authority and immutable source contracts remain controlling. Fiscal capture is separate from this ERP issuance reference addition.

Read `docs/reviews/2026-10-23-marketup-operation-nature/report.md`: adopt the functional distinction between nature, allowed/default choice, product exceptions and preparation/execution. Neither the inspected tenant's 17 entries nor `ShowNFCe`, `IsSystem`, `IsStockMovement`, `IsCfopST` or `IsCfopSale` was imported or used to establish tax eligibility. No MarketUP business system was accessed in this task.

The XLSX and both PDFs were downloaded and parsed in the earlier isolated research run; source-code investigation and explicit dataset assertions completed there. This documentation batch retains those exact reviewed bytes and the compatibility findings. No application/DB tests, browser workflow, provider canary or actual fiscal submission was run for task 1.4. The earlier research run changed no tracked files; this integration commits documentation and source artifacts only.

Full consolidated legal Annex II comparison and discretionary UF activation are not verified. A SEFAZ-SP historical Anexo V page was inspected but explicitly marks its table revoked in 2022; it was rejected as a current legal baseline rather than silently used as present authority. [Historical page showing revocation](https://legislacao.fazenda.sp.gov.br/Paginas/l6an5.aspx). The official 2026 portal table/IT are stronger current reference evidence, while legal applicability remains conditional on the actual operation and authoritative rules.

This report establishes provenance and a bounded reference strategy. It does not establish readiness for exports, fuels, ISS, donations, special UF exceptions, new issuer regimes, all possible 55 printing modes, automatic purchases or any production fiscal rollout.
