## Context

See [proposal.md](proposal.md) for the approved motivation and scope. Reference: [pinned source](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25). The current system has Nuxt presentation, Laravel-owned access/data, PostgreSQL and Redis, and existing dirty implementation work outside this planning change.

**Dependencies:** None.

This design is required because it changes multiple layers, tenant/data contracts or external-effect/migration behavior. It is a target design, not a statement of delivered behavior.

## Goals / Non-Goals

**Goals:** Implement company foundation and unified workspace using the shared foundation contracts; make authorization, persistence, failure outcomes and acceptance traceable across API, UI and tests.

**Non-Goals:** Separate runtimes/databases/identity stores, destructive migration, old production-data import, excluded complements and automatic production release. Preserve unrelated source work and current office behavior.

## Decisions

### Company is the ERP tenant
Keep `accounts` and existing office `clients` unchanged. Introduce `erp_companies`, `erp_establishments`, `erp_company_memberships`, company roles/permission grants and office-delegation records. Global `users` remain the sole credential authority. Company CNPJ is normalized with the existing identifier rules; an establishment belongs to exactly one company. Do not turn financial ledger Accounts into office Accounts: use ERP namespaced models and `erp_*` tables. Existing-user matches require authentication, never password replacement or automatic membership based on email/CNPJ.

### Context and authorization
Use immutable request Company context, selected server-side in the session. ERP route binding follows context resolution. `X-Company-Id` asserts the selected company; missing returns 422 and stale returns 409 `company_context_changed`. Foreign resource IDs return 404; foreign relationship IDs and caller-supplied context fields return 422. Office APIs continue using their own `X-Account-Id`. Shared authenticated transport determines the domain from the route, never from a caller-provided tenant identifier. A worker explicitly enters/restores Company context in finally, including exceptions.

Identity, company listing/selection, company registration/creation and platform endpoints are access/setup operations with explicit guards and no current-company assertion prerequisite. Only the company-switch endpoint accepts a target company identifier to change selection; ordinary operating bodies cannot do so. A delegated selection binds the selected Account and delegation as well as Company. Changing office invalidates an existing delegated company selection; a stale ERP request then receives company-context conflict rather than silently operating through a different office. Company creation/selection cannot be used as an exception to platform bootstrap, owner or commercial permissions.

Define company roles as owner, manager, operator and viewer. Owner manages company memberships and delegation; manager administers operating configuration; operator receives explicit action permissions; viewer reads only. Granular catalog, purchase, stock, sale, finance, cash, fiscal, report and certificate permissions are enforced in Laravel. A delegation stores a company-selected allowlist, defaults to read-only and excludes membership, ownership and contract administration. Delegated actions additionally require a current active office membership, active office and an appropriate office role; read-only office users cannot mutate. Keep actor user, company and delegating Account in audit records. Lock the company when accepting/replacing/revoking the single active delegation. Revocation and suspension are rechecked before mutation and dispatch, without trusting cached identity permissions.

### Unified navigation and identity
Keep the current Nuxt versions, theme/tokens and components; create an ERP layout at `/erp/*` in the same frontend. `/me` retains existing fields and adds `companies` and `current_company`, using safe id/name/status/role/permission projections. The ordinary login destination stays the earliest active office membership; when no active office exists, select the earliest active direct company membership and enter `/erp`. Delegated companies appear only through an explicitly selected authorized office and delegation. Explicit ERP navigation may select an authorized company without changing office membership. New logins reset selections to those rules; reload preserves confirmed session choices. Never extend the existing absolute authentication deadline.

The top-of-sidebar context menu separates Offices and Companies. Successful changes discard both domains' sensitive cached results, forms, selections and late responses before showing the destination; failed changes preserve the prior view. Company-only users can use ERP without an office, and users with neither authorization reach unavailable access with logout. Platform navigation retains its own guard. No new OAuth service, second password store, standalone ERP backend or second database is introduced.

### Additive deployment and dependency boundary
The ERP starts empty. Do not run source migrations that create/drop `users`, office Accounts or existing tables; produce additive PostgreSQL migrations and constrained foreign keys to existing global users. Company setup is an authenticated foundation primitive; public commercial registration and subscription provisioning belong exclusively to change 2. Until that gate exists, operational ERP writes are denied. Source credentials, database dumps, vendor folders, public XML, uploads and runtime data are excluded from any later import. Dependency additions require a concrete Laravel 13/PHP compatibility assessment and focused contract tests.

### Alternatives and compatibility

The selected design keeps domain ownership inside the destination application so one authenticated transaction can enforce company permissions and consistent local effects. Separate legacy Laravel/MySQL services were rejected by the user's unification decision; blindly copying source schema/controllers would collide with global identities and Account semantics. A frontend-only port was rejected because authorization and real provider/transaction outcomes must be server-enforced. Shared protocols and prerequisite deltas are read from the foundation design; this change does not restate a different version of them.


### Shared contract and capability ownership registry

This document is the authoritative integration map for the suite. Cross-change links are planning dependencies; OpenSpec artifact completion does not imply prerequisite implementation. Read the listed prerequisite deltas alongside canonical specs before applying a dependent change. Complete all nine planning packages before any application implementation begins.

| Owner change | Owned capability/contract | Consumers |
| --- | --- | --- |
| 1 company-foundation | erp-company-foundation; existing auth/access-pages/isolation extensions; Company identity/context, permissions, API envelopes and decimal/idempotency conventions | All |
| 2 commercial-access | erp-commercial-access; company contracts and entitlement gate | All ordinary module operations; 9 launch |
| 3 core-catalogs | erp-core-catalogs; company contacts/products/services/units/prices/tax/logistics IDs and historical snapshots | 4–9 |
| 4 finance-and-cash | erp-finance-and-cash; atomic obligation/payment/cash participants, ledger balances and reversals | 5, 6, 9 |
| 5 purchasing-and-stock | erp-purchasing and erp-inventory; purchase/stock state, valuations and stock participants | 6, 7, 9 |
| 6 sales-and-orders | erp-sales; commercial lifecycle and immutable approved-sale snapshot | 7, 9 |
| 7 fiscal-issuance | erp-fiscal-issuance; issuer authority, model state, number/key/provider evidence and private artifacts | 8, 9 |
| 8 accounting-documents | erp-accounting-documents and added ERP-origin fiscal-documents requirements | 9 and current office Fiscal |
| 9 reports-and-launch | erp-reports and added whole-core application-runtime release requirements | Public release |
| Existing complete-xml-accounting-workflow | Incoming full-XML readiness, integrity attention, science eligibility/recovery and their gates | 8 must depend on its implemented contract |
| Existing add-super-admin-platform | Earlier pending office/platform targets; delivered canonical D009 baseline governs reuse | No ERP bypass or completion claim from this suite |

Only the foundation modifies the existing auth/access-page/isolation blocks. Foundation changes application-runtime transport and adds Company runtime; change 9 adds a different ERP release requirement. Existing XML completion owns its background/science requirement blocks; change 8 adds distinct ERP-origin publication requirements without replacing them. Child changes introduce only their own new capabilities and reference shared types/participants instead of cloning requirements. Future sync must rebase against any intervening canonical changes and preserve all surviving scenarios.

Shared APIs use `/api/v1/erp/*` via the current private Nuxt proxy; platform company commerce uses `/api/v1/admin/erp/*`. Domain resources use `{data:...}` and lists use Laravel paginator `meta.current_page,last_page,per_page,total`, default 15 with a validated maximum 100. New field errors use 422, unauthenticated 401, denied operations 403, unknown/foreign resource 404, context/idempotency conflicts 409 and explicit throttling 429/Retry-After. Sensitive responses use no-store. Error bodies reuse existing application `code`/`message` conventions and safe field errors rather than raw exceptions/provider payloads. Read filters must be bounded and server-applied before pagination.

Company identities contain safe id/name/status/role/permission fields and an explicit direct/delegated access-kind projection; delegated choices also identify the authorized office. Direct membership selection never acquires office access; delegated selection binds the real selected office and delegation. Identity refresh resolves current permissions. Persist session selection separately for the two domains; ordinary requests never write a persistent current-company field on the user. No auth/delegation secret is serialized.

Amounts/quantities/conversions are decimal strings, with precision and rounding preserved from the mapped source field/document rule. Shared fixtures must cover rounding boundaries and prohibit JS floating-point totals as authoritative values. Idempotency-Key is an opaque bounded request identifier for posted/external actions; canonical payload hashes exclude plaintext credentials and include the effective Company/actor/operation. Lock source document first, sorted stock rows second, financial obligations/allocations third and cash session last. Shared participants join the caller transaction, acquire no upstream source locks and never commit or call providers independently.

Shared launch gating is defined by change 9 and consulted by all ERP entrypoints. Earlier changes implement behind disabled ERP access and isolated preview fixtures; their completion cannot enable public signup or provider traffic. Company commercial setup remains independently pending until explicit activation in change 2. User decisions authorize the product direction and these artifacts; they do not prove any feature, migration or live-provider acceptance.

### Pinned source-to-destination inventory

Source: opmonihub/sistema commit `e7b77beccdad983ddd990c0a045be750fc3f7e25`, inspected 2026-10-17. Static inventory contains 101 root models, 292 root migrations and 685 root Blade views/partials including excluded modules; those totals are not the promised migrated-core count. Runtime/provider correctness of the old system is not assumed. Source `routes/web.php`, controllers and `resources/views` define the discoverable behavior; `routes/api.php` is mainly e-commerce and is not a ready ERP fiscal API.

Each row assigns source controller families to a concrete target owner, HTTP family and page family. The implementation preflight expands each assigned family's actual actions/forms/exports/permissions into executable checks; it cannot drop an included behavior silently. A source function belonging to an excluded complement remains excluded even when located in a mixed controller. Keep the per-action inventory in this design as evidence before implementation, rather than copying unrelated source files. Source root `Account` is a financial ledger concept, not the existing office Account; source User/business_id session authority is replaced by global identity and Company membership.

| Source family | Source controllers (under app/Http/Controllers) | Owner | Target behavior/API/page |
| --- | --- | --- | --- |
| Companies, establishments, users and roles | `BusinessController`, `BusinessLocationController`, `ManageUserController`, `UserController`, `RoleController` | 1 | Company/establishment/membership/delegation APIs; /erp/configuracoes and shared context navigation |
| Contacts, customer groups and commercial attachments | `ContactController`, `ContatoController`, `CustomerGroupController`, `DocumentAndNoteController` | 3 | /erp/contacts; /erp/cadastros/contatos; separate from office Clients; private attachments |
| Goods, services, variants and warranties | `ProductController`, `VariationTemplateController`, `WarrantyController` | 3 | /erp/products; /erp/cadastros/produtos; source variants and product warranty metadata |
| Units, categories, brands and selling prices | `UnitController`, `BrandController`, `TaxonomyController`, `SellingPriceGroupController` | 3 | /erp/units,categories,brands,price-groups; /erp/cadastros |
| Discounts and supported commercial coupon rules | `DiscountController`, `CupomController` | 3 | Validated catalog/discount configuration consumed by sale totals; /erp/cadastros |
| Fiscal classifications and geographical references | `TaxRateController`, `GroupTaxController`, `NaturezaController`, `DifalController`, `PercentualImpostoController`, `IbptController`, `CityController`, `CidadeController` | 3 | Fiscal reference/settings APIs; /erp/cadastros/fiscal; preserve applicable source tax fields |
| Carriers, vehicles and establishment settings | `TransportadoraController`, `VeiculoController`, `LocationSettingsController` | 3 | Logistics and establishment configuration for issuance; /erp/cadastros/logistica |
| Product imports, barcodes, labels and printing setup | `ImportProductsController`, `BarcodeController`, `LabelsController`, `PrinterController` | 3 | Explicit catalog import preview/confirm and authorized label/print configuration |
| Financial accounts, types, bank records, expenses and revenue | `AccountController`, `AccountTypeController`, `BankController`, `ExpenseCategoryController`, `ExpenseController`, `RevenueController` | 4 | ERP LedgerAccount/FinancialAccount, obligations and finance APIs; /erp/financeiro |
| Payments, cash sessions and company boleto/remittance documents | `TransactionPaymentController`, `CashRegisterController`, `BoletoController`, `RemessaController` | 4 | Allocations, reversals, cash, private boleto/remittance artifacts; /erp/financeiro and /erp/caixa |
| Purchases, receipts, supplier returns and purchase XML preview | `PurchaseController`, `PurchaseReturnController`, `CombinedPurchaseReturnController`, `PurchaseXmlController`, `NfeEntradaController` | 5 | /erp/purchases,purchase-returns; /erp/compras; issued fiscal actions remain owned by 7 |
| Opening stock, transfers and adjustments | `OpeningStockController`, `ImportOpeningStockController`, `StockTransferController`, `StockAdjustmentController` | 5 | /erp/stock/*; /erp/estoque; explicit import confirmation and stock ledger |
| Quotes, orders, counter sales, sale returns and commissions | `SellController`, `SellPosController`, `SellReturnController`, `SalesCommissionAgentController`, `ImportSalesController` | 6 | /erp/quotes,orders,sales,sale-returns; /erp/vendas; commission snapshots and finance participants |
| Commercial invoice numbering/layout and ordinary sale receipts | `InvoiceSchemeController`, `InvoiceLayoutController` | 6 | Company/establishment commercial numbering and authorized receipt printing; fiscal auxiliary rendering owned by 7 |
| NF-e, NFC-e and explicit contingency | `NfeController`, `NfceController`, `ContigenciaController`, `NfceContigenciaController` | 7 | /erp/fiscal/documents/events; /erp/fiscal; source fiscal services adapted to private storage |
| CT-e, complement/substitution and MDF-e lifecycle | `CteController`, `EmiteCteController`, `MdfeController` | 7 | Transport-model draft/transmit/consult/event/close flows; /erp/fiscal |
| Fiscal returns and inutilization | `DevolucaoController`, `InutilController` | 7 | Explicit model-valid fiscal return/invalidation actions; commercial returns remain owned by 5/6 |
| Incoming fiscal distribution/manifestation reference | `ManifestoController`, `ImportXmlController` | 8 | Use current Opmoni capture and complete-XML workflow; only verified ERP-issued-origin handoff is added, not a second incoming science implementation |
| Core reporting and real ERP dashboard | `AccountReportsController`, `ReportController`, `HomeController`, `DashboardConfiguratorController` | 9 | /erp/reports/* and real ERP overview; /erp/relatorios; source report categories reconciled to posted ledgers |
| Core operational notices and runtime recovery | `NotificationController`, `NotificationTemplateController`, `BackUpController` | 9 | Scoped operating feedback and existing runtime backup/recovery; do not expose unrestricted filesystem/backup administration |
| Excluded ERP updater/installer | `UpdateController` | Excluded/reference | Existing Opmoni deployment remains authoritative; no imported web installer/updater |
| Excluded restaurant and service-staff features | `TypesOfServiceController` | Excluded/reference | No restaurant/table/service-staff module in the selected core |
| Excluded e-commerce storefront, orders and promotional content | `CarrinhoController`, `CarrosselController`, `CidadeFreteGratisController`, `ClienteEcommerceController`, `EcommerceController`, `EnderecoEcommerceController`, `InformativoController`, `PedidoEcommerceController` | Excluded/reference | No storefront/cart/online order/promotion module |
| Excluded legacy SaaS checkout adapters | `PaymentController`, `PesaPalController` | Excluded/reference | Company contract/activation is owned by 2; no migration of legacy automatic SaaS payment adapters |
| Framework base controller | `Controller` | Excluded/reference | Infrastructure reference only, never copied over the current base Controller |


Source services NFeService/NFCeService/CTeService/MDFeService/DevolucaoService belong to 7. Source DFeService/Manifesto capture is a reference to existing Opmoni Fiscal, not permission to replace its incoming-document contracts. Source models Business/BusinessLocation map to ERP Company/establishment; source User authentication and all its schema collisions are adapted, never installed. Source Transaction/lines/payments are split by the owner state machines in changes 4–6. Company owner/financial/cash/stock/fiscal actor references use global user IDs.

Modules Crm, Essentials/HR, Manufacturing, Repair, Woocommerce and ProductCatalogue storefront are excluded. Modules/Superadmin contributes company commercial behavior only to change 2, translated into current platform access without importing its authentication or payment stack. Keep the existing office Portfolio, SERPRO, Fiscal capture, Work, Team and WhatsApp as established modules. Core sale receipts, barcodes/labels, commission snapshots, imports and company boleto/remittance outputs remain assigned above; restaurant/hospitality and e-commerce-specific variants do not.

Import rules exclude .env, bd.sql/other dumps, credentials, certificate bytes/passwords, vendor/node_modules, public XML/output/upload trees and source runtime storage. Record source paths and hashes of any subsequently reused logic, with copyright/license provenance retained. The source compiler/runtime/manifests are reference inputs to compatibility review; they never replace the destination composer/package manifests wholesale.

## Risks / Trade-offs

- Legacy MySQL SQL/framework/package behavior may not translate directly → prove PostgreSQL query/constraint behavior and installed-runtime compatibility before enabling affected operations; do not install the source lockfile over Opmoni.
- Cached permissions, delegated access and worker reuse can outlive authorization → revalidate at the transaction/effect boundary and restore explicit domain contexts on every exit.
- Multiple module owners can duplicate effects or drift wire contracts → use the foundation ownership registry, caller-owned transactions, stable source-operation keys and producer/consumer fixtures.
- Local provider fakes and static source inventory cannot prove deployed or live-provider acceptance → report those evidence classes separately and preserve explicit rollout gates.

## Migration Plan

1. Read prerequisite proposals/specs/designs from disk, canonical capabilities and the existing dirty baseline; expand the assigned source actions into verification rows without changing agreed scope.
2. Add only this domain's additive PostgreSQL migrations/API/UI/worker behavior behind disabled ERP release mode; preserve the existing office rows, runtime configuration and gates.
3. Verify focused fixtures, dependent-module contracts and required independent PostgreSQL/Redis races using isolated resources; run current frontend lint/typecheck/test/build and relevant backend checks.
4. Rehearse schema/data preservation and disable-first recovery. Public release remains owned by change 9 after full-core acceptance, not by completion of this change.

Rollback disables this domain's ERP entrypoints/background effects and retains committed ledgers, provider evidence and private artifacts. Do not run destructive table drops or reset office/Company identity data as an operational rollback. No unresolved product question changes this design; implementation compatibility discoveries must be surfaced before altering the agreed contract.
