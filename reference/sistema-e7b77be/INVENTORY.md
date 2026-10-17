# Inventário de referência (sistema @e7b77be)

Sincronizado via `scripts/sync-sistema-full-core.sh` (também invocado por `sync-sistema-reference.sh`).

| Área | Arquivos |
| --- | ---: |
| `app/Http/Controllers` | 77 |
| `app/Models` | 14 |
| `app/Services` | 6 |
| `resources/views` | 142 |
| `database/migrations` (business/location) | 41 |
| `app/Utils/BusinessUtil.php` | 1 |

## Controllers por change OpenSpec

| Owner | Família | Controllers copiados |
| --- | --- | --- |
| 1 company-foundation | Empresa, estabelecimento, usuários, papéis | `BusinessController`, `BusinessLocationController`, `ManageUserController`, `UserController`, `RoleController` |
| 3 core-catalogs | Contatos, produtos, unidades, fiscal ref., logística, import/print | `Contact*`, `Product*`, `Unit*`, `Brand*`, `Tax*`, `Natureza*`, `City*`, `Transportadora*`, `ImportProducts*`, `Barcode*`, `Labels*`, `Printer*`, … |
| 4 finance-and-cash | Contas, despesas, pagamentos, caixa, boleto | `Account*`, `Expense*`, `Revenue*`, `TransactionPayment*`, `CashRegister*`, `Boleto*`, `Remessa*` |
| 5 purchasing-and-stock | Compras, estoque | `Purchase*`, `OpeningStock*`, `StockTransfer*`, `StockAdjustment*`, `NfeEntrada*` |
| 6 sales-and-orders | Vendas, PDV, faturamento comercial | `Sell*`, `InvoiceScheme*`, `InvoiceLayout*`, `SalesCommission*` |
| 7 fiscal-issuance | NF-e, CT-e, MDF-e, devolução | `Nfe*`, `Nfce*`, `Cte*`, `Mdfe*`, `Devolucao*`, `Inutil*`, `Contigencia*` |
| 8 accounting-documents | Manifesto / import XML | `Manifesto*`, `ImportXml*` |
| 9 reports-and-launch | Relatórios, home, notificações, backup | `Report*`, `Home*`, `DashboardConfigurator*`, `Notification*`, `BackUp*` |
| Excluded | E-commerce, restaurante, installer | **Não copiados** (`Ecommerce*`, `TypesOfService*`, `UpdateController`, …) |
| 2 commercial-access | Superadmin empresa | `Modules/Superadmin/Http/Controllers/BusinessController.php` (referência apenas) |

## Views destacadas (change 1)

- `resources/views/business/settings.blade.php` + `partials/settings_*.blade.php` → Nuxt `/erp/configuracoes/*`

## Próximo porte sugerido (fila)

1. **Change 1** — concluir abas de configurações (`settings_*`), estabelecimentos (`BusinessLocationController`), membros (`ManageUserController` / `RoleController`).
2. **Change 3** — contatos e produtos após gate comercial (change 2).
3. **Changes 4–9** — na ordem de dependência do registry em `openspec/changes/add-erp-company-foundation/design.md`.
