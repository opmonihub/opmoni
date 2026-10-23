## Why

ERP screens have drifted from `.dashboard` and established Opmoni navigation, theme and form proportions, while basic product creation exposes a long tax editor and company defaults do not reduce repeated tax entry. The completed source/browser audit and functional MarketUP research support one coordinated change that restores consistency, supplies usable initial catalogs and resolves reusable product tax and operational CFOP configuration without rewriting historical operations.

## What Changes

- Align active ERP pages and consumed components with the reference's semantic colors, typography, proportions, action roles and page-family composition; remove redundant headings and generic instructions while retaining meaningful business guidance.
- Use one permission-aware destination registry for sidebar, search and local navigation. Fix Fiscal hash/model correspondence, missing stock cost layers, permitted defaults and persistent catalog/settings navigation. Allow one fully expanded sidebar group across both menu regions, matching Opmoni's manual-fold and pinned-current-destination behavior.
- Make basic product creation require only name and a valid company unit, with optional commercial, fiscal and variant sections and reachable actions. Correct code-string preservation, address coherence, field errors, dependent finance references and scalable commercial lookup.
- Initialize new companies with a versioned operating preset and native basic records: currency contract, unit, default counter-sale customer and usable commercial numbering/layout. Provide previewed, additive reconciliation for existing companies without guessing legacy IDs or overwriting choices.
- Add company-owned reusable product tax profiles, explicit per-field inheritance/overrides and effective-value provenance. Resolve coherent revisions before operation snapshots freeze; declare fiscal intent explicitly and validate model readiness before a fiscal-intended sale becomes immutable.
- Extend the existing company/establishment operation-nature catalog with allowed CFOP choices, an explicit default and product-specific exceptions. Retain native identities, the existing single-CFOP payload contract, attributable changes and archived historical references.
- Resolve effective CFOP server-side for eligible goods Sales/NF-e/NFC-e preparation using an authorized explicit line choice, then an applicable product exception, then the nature default. Validate the supported context, return unresolved or invalid-reference errors without silent substitution, and keep product tax-profile inheritance separate from operation-specific CFOP resolution.
- Freeze CFOP choice, rule source and coherent configuration revision with the reviewed operation alongside the existing tax provenance. Changes apply to newly resolved or explicitly refreshed eligible drafts; configuration writes do not themselves move stock, create financial effects or transmit fiscal documents.
- Preserve posted sales, financial effects, fiscal sources, authority changes, pending-operation recovery and existing production gates. These are additive contracts; existing product null/omission behavior remains supported.

Scope includes V01–V09 and A01–A13/F1–F9 from the [audit](../../../docs/reviews/2026-10-23-template-consistency-audit/report.md). Related office Settings labels/navigation and shared consumers receive focused consistency fixes. The unconfirmed populated office-Fiscal scrolling risk requires reproduction before any correction.

The [MarketUP backend insights](../../../docs/reviews/2026-10-23-marketup-operation-nature/report.md) add functional references for multiple CFOP choices, product exceptions, deterministic resolution and provenance. Opmoni's current Laravel backend and existing dashboard/template remain the implementation base; this extension adds no MarketUP visual-design target. Existing purchase-line references and posted return/source contracts remain compatible; automatic purchase CFOP resolution and new fiscal operation kinds are outside this addition.

Non-goals: wholesale legacy or MarketUP import, demonstration identities/data, arbitrary tax-rule scripting, assumed tax rates/classifications/regimes or universal product CFOPs, automatic fiscal-nature presets without reviewed compatible mappings, direct stock/finance effects from nature flags, new issuance models, a separate fiscal service, new dependencies, production enablement, historical reprocessing, or unrelated office/admin redesign. Commercial invoice layouts do not replace fiscal DANFE builders.

## Capabilities

### New Capabilities

- `erp-company-initialization`: Versioned native settings/catalog initialization, actual default consumption and safe existing-company reconciliation.
- `erp-product-tax-profiles`: Company-scoped reusable multi-tax configurations, explicit inheritance/overrides, revisioned resolution and immutable operation provenance.

### Modified Capabilities

- `erp-company-foundation`: Shared visual baseline, truthful permission-aware destinations, single-group expansion and typed/grouped settings presentation.
- `erp-core-catalogs`: Minimal product workflow, optional advanced sections, coherent addresses and preserved reference/decimal semantics; scoped operation natures with bounded CFOP reference lookup, explicit allowed/default choices, product exceptions, atomic revisions and historical compatibility.
- `erp-sales`: Searchable paginated references, native commercial defaults and explicit fiscal intent with reviewed tax/CFOP resolution and immutable rule provenance.
- `erp-fiscal-issuance`: Action-triggered model-aware drafting, correct field-error recovery and strict use of resolved frozen tax/CFOP sources without substitution from current catalog rules.
- `erp-finance-and-cash`: Visible compatibility validation for dependent account/session selections without weakening backend checks.
- `access-pages`: Consistent Portuguese office Settings navigation/copy while retaining the established shell and explicit demonstration boundaries.

## Impact

Frontend changes affect ERP/shared layouts, theme tokens, navigation projections, settings, catalog editors, commercial/finance/fiscal forms and their actual consumers. Laravel changes affect company provisioning/settings, native catalog defaults, product profile APIs/resolution, operation-nature/CFOP choices and exception APIs, deterministic rule resolution, commercial numbering/printing and snapshot/readiness boundaries. Browser/SSR retain relative `/api/v1`, private ingress, CSRF and session-selected Company/Account assertions; profile and initializer APIs use existing company authorization conventions.

Additive migrations and scoped concurrency protection are required for native setup preferences, profile revisions/field intent, operation-nature CFOP associations/exceptions and configuration revisions, and compatible snapshot metadata. PostgreSQL/Redis verification must use independent isolated processes. No environment values, credentials, identities or production gates are changed by this proposal.

D009 and the accepted destination/access architecture remain in force. This proposal revisits the archived `standardize-erp-dashboard-ui` choices to retain ERP-only light-mode token overrides and `max-w-4xl` settings forms; implementation must reconcile shared appearance with measured contrast and comparable task geometry, rather than edit the archive. No accepted architecture decision is otherwise superseded.

Detailed implementation choices follow the design; unresolved legacy city/currency namespaces remain explicit compatibility dependencies, never permission to infer same-number mappings. Canonical CFOP reference data and supported applicability checks require authoritative verification before implementation; the inspected MarketUP tenant does not supply an approved fiscal mapping. The artifacts describe pending work, not delivered functionality or production acceptance.
