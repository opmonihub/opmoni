#!/usr/bin/env bash
# Sync read-only FULL ERP PHP core from opmonihub/sistema (pinned commit).
# Includes all Models, Services, Utils, Helpers, config, and migrations.
# Excludes secrets, vendor, dumps, and public uploads per security rule.
set -euo pipefail

PIN=e7b77beccdad983ddd990c0a045be750fc3f7e25
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REF="$ROOT/reference/sistema-e7b77be"
CACHE="${SISTEMA_REF_CACHE:-/tmp/opmoni-sistema-ref}"

CONTROLLERS=(
  # Change 1
  BusinessController.php BusinessLocationController.php ManageUserController.php UserController.php RoleController.php
  # Change 3
  ContactController.php ContatoController.php CustomerGroupController.php DocumentAndNoteController.php
  ProductController.php VariationTemplateController.php WarrantyController.php
  UnitController.php BrandController.php TaxonomyController.php SellingPriceGroupController.php
  DiscountController.php CupomController.php
  TaxRateController.php GroupTaxController.php NaturezaController.php DifalController.php PercentualImpostoController.php IbptController.php
  CityController.php CidadeController.php TransportadoraController.php VeiculoController.php LocationSettingsController.php
  ImportProductsController.php BarcodeController.php LabelsController.php PrinterController.php
  # Change 4
  AccountController.php AccountTypeController.php BankController.php ExpenseCategoryController.php ExpenseController.php RevenueController.php
  TransactionPaymentController.php CashRegisterController.php BoletoController.php RemessaController.php
  # Change 5
  PurchaseController.php PurchaseReturnController.php CombinedPurchaseReturnController.php PurchaseXmlController.php NfeEntradaController.php
  OpeningStockController.php ImportOpeningStockController.php StockTransferController.php StockAdjustmentController.php
  # Change 6
  SellController.php SellPosController.php SellReturnController.php SalesCommissionAgentController.php ImportSalesController.php
  InvoiceSchemeController.php InvoiceLayoutController.php
  # Change 7
  NfeController.php NfceController.php ContigenciaController.php NfceContigenciaController.php
  CteController.php EmiteCteController.php MdfeController.php DevolucaoController.php InutilController.php
  # Change 8
  ManifestoController.php ImportXmlController.php
  # Change 9
  AccountReportsController.php ReportController.php HomeController.php DashboardConfiguratorController.php
  NotificationController.php NotificationTemplateController.php BackUpController.php
)

VIEW_DIRS=(
  business contact product sale purchase report home layouts/partials
)

should_skip() {
  local base
  base="$(basename "$1")"
  case "$base" in
    .env|.env.*|*.pem|*.pfx|*.p12|*.key|credentials.json|*.sql|*.dump|*.bak)
      return 0
      ;;
  esac
  case "$1" in
    */vendor/*|*/node_modules/*|*/public/uploads/*|*/storage/app/public/*)
      return 0
      ;;
  esac
  return 1
}

copy_php_tree() {
  local src_dir="$1"
  local dest_dir="$2"
  mkdir -p "$dest_dir"
  if [[ ! -d "$src_dir" ]]; then
    return 0
  fi
  while IFS= read -r -d '' f; do
    if should_skip "$f"; then
      continue
    fi
    rel="${f#"$src_dir"/}"
    mkdir -p "$dest_dir/$(dirname "$rel")"
    cp "$f" "$dest_dir/$rel"
  done < <(find "$src_dir" -type f -name '*.php' -print0)
}

if [[ ! -d "$CACHE/.git" ]]; then
  git clone --depth 1 https://github.com/opmonihub/sistema.git "$CACHE"
fi

git -C "$CACHE" fetch --depth 1 origin "$PIN" 2>/dev/null || git -C "$CACHE" fetch origin "$PIN"
git -C "$CACHE" checkout --detach "$PIN"

mkdir -p "$REF/app/Http/Controllers" "$REF/resources/views" "$REF/routes" \
  "$REF/Modules/Superadmin/Http/Controllers"

for c in "${CONTROLLERS[@]}"; do
  if [[ -f "$CACHE/app/Http/Controllers/$c" ]]; then
    cp "$CACHE/app/Http/Controllers/$c" "$REF/app/Http/Controllers/"
  fi
done

copy_php_tree "$CACHE/app/Models" "$REF/app/Models"
copy_php_tree "$CACHE/app/Services" "$REF/app/Services"
copy_php_tree "$CACHE/app/Utils" "$REF/app/Utils"
copy_php_tree "$CACHE/app/Helpers" "$REF/app/Helpers"
copy_php_tree "$CACHE/config" "$REF/config"
copy_php_tree "$CACHE/database/migrations" "$REF/database/migrations"

for d in "${VIEW_DIRS[@]}"; do
  if [[ -d "$CACHE/resources/views/$d" ]]; then
    rm -rf "$REF/resources/views/$d"
    cp -r "$CACHE/resources/views/$d" "$REF/resources/views/"
  fi
done

cp "$CACHE/Modules/Superadmin/Http/Controllers/BusinessController.php" \
   "$REF/Modules/Superadmin/Http/Controllers/" 2>/dev/null || true

rg -n "BusinessController|BusinessLocation|ManageUser|RoleController|ContactController|ProductController|PurchaseController|SellController|NfeController|ReportController" \
  "$CACHE/routes/web.php" 2>/dev/null | head -120 > "$REF/routes/web-core-routes.txt" || true

MODEL_COUNT=$(find "$REF/app/Models" -type f -name '*.php' 2>/dev/null | wc -l | tr -d ' ')
SERVICE_COUNT=$(find "$REF/app/Services" -type f -name '*.php' 2>/dev/null | wc -l | tr -d ' ')
UTIL_COUNT=$(find "$REF/app/Utils" -type f -name '*.php' 2>/dev/null | wc -l | tr -d ' ')
HELPER_COUNT=$(find "$REF/app/Helpers" -type f -name '*.php' 2>/dev/null | wc -l | tr -d ' ')
CONFIG_COUNT=$(find "$REF/config" -type f -name '*.php' 2>/dev/null | wc -l | tr -d ' ')
MIGRATION_COUNT=$(find "$REF/database/migrations" -type f -name '*.php' 2>/dev/null | wc -l | tr -d ' ')
CONTROLLER_COUNT=$(find "$REF/app/Http/Controllers" -type f -name '*.php' 2>/dev/null | wc -l | tr -d ' ')

cat > "$REF/META.json" <<EOF
{
  "source": "https://github.com/opmonihub/sistema",
  "commit": "$PIN",
  "synced_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "bundle": "full-php-core",
  "counts": {
    "models": $MODEL_COUNT,
    "services": $SERVICE_COUNT,
    "utils": $UTIL_COUNT,
    "helpers": $HELPER_COUNT,
    "config": $CONFIG_COUNT,
    "migrations": $MIGRATION_COUNT,
    "controllers": $CONTROLLER_COUNT
  },
  "note": "Read-only reference. Port into backend/ and frontend/ with erp_* tables and Opmoni access contracts."
}
EOF

echo "Synced sistema full PHP core @$PIN -> $REF"
echo "  models=$MODEL_COUNT services=$SERVICE_COUNT utils=$UTIL_COUNT helpers=$HELPER_COUNT config=$CONFIG_COUNT migrations=$MIGRATION_COUNT controllers=$CONTROLLER_COUNT"
