# Sistema → Opmoni (foundation slice)

Pinned source: `e7b77beccdad983ddd990c0a045be750fc3f7e25`.

This tree is a **literal copy** of the legacy ERP company/establishment/user slice. Do not run it inside Opmoni as-is (Laravel 8, MySQL, `business_id` session, Spatie roles per business).

## Where each file lands

| Reference (sistema) | Opmoni target (change 1) |
| --- | --- |
| `app/Models/Business.php` | `backend/app/Models/Erp/ErpCompany.php` + additive columns from migration |
| `app/Models/BusinessLocation.php` | `backend/app/Models/Erp/ErpEstablishment.php` |
| `app/Http/Controllers/BusinessController.php` | `backend/app/Http/Controllers/Api/V1/Erp/*` + Nuxt `/erp/configuracoes` |
| `app/Http/Controllers/BusinessLocationController.php` | ERP establishments API + UI |
| `app/Http/Controllers/ManageUserController.php`, `RoleController.php` | Company memberships & permissions (not office `AccountMembership`) |
| `app/Utils/BusinessUtil.php` | Split: defaults/seeding → later catalog change; keep pure helpers only |
| `resources/views/business/*` | Nuxt pages under `frontend/app/pages/erp/configuracoes/` (field list + flows) |
| `Modules/Superadmin/.../BusinessController.php` | Change 2 `add-erp-commercial-access` (not foundation) |
| `database/migrations/*business*` | Map columns to `erp_companies` / `erp_establishments` PostgreSQL migrations |

## Port workflow (fast)

1. Open the reference controller action and the Blade partial it returns.
2. Implement the same **behavior and fields** on the Opmoni JSON API + Nuxt form (existing theme).
3. Replace `business_id` with `CurrentCompany` / session company; replace `User` password flows with global identity rules from `openspec/changes/add-erp-company-foundation/design.md`.
4. Copy **verbatim** only stateless helpers (e.g. UF/IBGE maps from `Business.php`) into `backend/app/Erp/` when they have no Laravel 8 deps.

Re-sync from GitHub: `./scripts/sync-sistema-reference.sh`
