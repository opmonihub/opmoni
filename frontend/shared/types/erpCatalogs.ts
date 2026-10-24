export type ErpCatalogStatus = 'active' | 'archived'

export type ErpCatalogEditorMode = 'create' | 'detail' | 'edit'
export type ErpBarcodeType = 'C128' | 'C39' | 'EAN13' | 'EAN8' | 'UPCA' | 'UPCE'

export const erpProductTaxProfileFields = [
  'icms_cst',
  'icms_csosn',
  'icms_rate',
  'simple_national_credit_rate',
  'pis_cst',
  'pis_rate',
  'cofins_cst',
  'cofins_rate',
  'ipi_cst',
  'ipi_rate',
  'ibs_cbs_cst',
  'ibs_cbs_classification_code'
] as const

export type ErpProductTaxProfileField = typeof erpProductTaxProfileFields[number]
export type ErpProductTaxFieldIntent = 'value' | 'inherit' | 'unset'

export type ErpProductTaxProfile = {
  id: number
  name: string
  status: ErpCatalogStatus
  current_revision: number
  revision_id: number
  fields: Partial<Record<ErpProductTaxProfileField, string | null>>
  applicability: { tax_regime?: string, kind?: 'goods' | 'service', movement?: 'in' | 'out' }
}

export type ErpProductTaxFieldSource = {
  source: 'product' | 'profile' | 'company_profile' | 'unresolved'
  intent: ErpProductTaxFieldIntent
  profile_id?: number
  revision?: number
  revision_id?: number
}

export type ErpProductTaxProfileResolution = {
  version: 'product-tax-v1'
  product_id: number | null
  values: Partial<Record<ErpProductTaxProfileField, string | null>>
  sources: Partial<Record<ErpProductTaxProfileField, ErpProductTaxFieldSource>>
  unresolved_fields: ErpProductTaxProfileField[]
  profile: { profile_id: number, revision: number, revision_id: number } | null
  company_default: { revision: number, profile_id: number | null, profile_revision: number | null, profile_revision_id: number | null }
  context: { tax_regime: string | null, kind: 'goods' | 'service', movement: 'in' | 'out' | null }
  applicability: { profile: Record<string, string> | null, company_profile: Record<string, string> | null }
  company_settings_fingerprint: string
  fingerprint: string
  previous?: ErpProductTaxProfileResolution | { errors: Record<string, string[]> }
}

export type ErpProductTaxPreviewCandidate = {
  product_id?: number
  kind?: 'goods' | 'service'
  erp_product_tax_profile_id?: number | null
  tax_field_intents?: Partial<Record<ErpProductTaxProfileField, ErpProductTaxFieldIntent>>
  context?: { tax_regime?: string | null, movement?: 'in' | 'out' | null }
} & Partial<Record<ErpProductTaxProfileField, string | null>>

export type ErpContactRecord = {
  id: number
  erp_customer_group_id: number | null
  name: string
  trade_name: string | null
  tax_id: string | null
  email: string | null
  phone: string | null
  is_customer: boolean
  is_supplier: boolean
  address: Record<string, string> | null
  notes: string | null
  status: ErpCatalogStatus
}

export type ErpCustomerGroupRecord = {
  id: number
  name: string
  percentage_adjustment: string
  status: ErpCatalogStatus
}

export type ErpProductRecord = {
  id: number
  sku: string | null
  name: string
  kind: 'goods' | 'service'
  track_stock: boolean
  erp_unit_id: number
  erp_brand_id: number | null
  erp_category_id: number | null
  sale_price: string
  warranty_months: number | null
  warranty_description: string | null
  barcode: string | null
  barcode_type: ErpBarcodeType
  ncm: string | null
  cest: string | null
  origin_code: string | null
  icms_cst: string | null
  icms_csosn: string | null
  pis_cst: string | null
  cofins_cst: string | null
  ipi_cst: string | null
  icms_rate: string | null
  pis_rate: string | null
  cofins_rate: string | null
  ipi_rate: string | null
  simple_national_credit_rate: string | null
  ibs_cbs_cst: string | null
  ibs_cbs_classification_code: string | null
  erp_product_tax_profile_id?: number | null
  tax_field_intents?: Partial<Record<ErpProductTaxProfileField, ErpProductTaxFieldIntent>> | null
  status: ErpCatalogStatus
  variants: ErpProductVariantRecord[]
}

export type ErpProductVariantRecord = {
  id: number
  sku: string | null
  name: string
  sale_price: string | null
  barcode: string | null
  barcode_type: ErpBarcodeType | null
  status: ErpCatalogStatus
}

export type ErpUnitRecord = {
  id: number
  short_name: string
  actual_name: string
  allow_decimal: boolean
  base_unit_id: number | null
  conversion_factor: string | null
  status: ErpCatalogStatus
}

export type ErpCategoryRecord = {
  id: number
  parent_id: number | null
  name: string
  status: ErpCatalogStatus
}

export type ErpBrandRecord = {
  id: number
  name: string
  status: ErpCatalogStatus
}

export type ErpPriceGroupRecord = {
  id: number
  name: string
  percentage_adjustment: string
  is_default: boolean
  status: ErpCatalogStatus
}

export type ErpDiscountRuleRecord = {
  id: number
  name: string
  kind: 'percentage' | 'fixed'
  value: string
  coupon_code: string | null
  conditions: Record<string, unknown> | null
  status: ErpCatalogStatus
}

export type ErpTaxRateRecord = {
  id: number
  erp_establishment_id: number | null
  name: string
  rate: string
  tax_kind: string
  metadata: Record<string, unknown> | null
  status: ErpCatalogStatus
}

export type ErpOperationNatureRecord = {
  id: number
  erp_establishment_id: number | null
  cfop: string
  description: string
  movement: 'in' | 'out'
  fiscal_metadata: Record<string, unknown> | null
  status: ErpCatalogStatus
}

export type ErpCarrierRecord = {
  id: number
  legal_name: string
  tax_id: string | null
  ie: string | null
  address: Record<string, string> | null
  status: ErpCatalogStatus
}

export type ErpVehicleRecord = {
  id: number
  erp_carrier_id: number | null
  vehicle_type: string | null
  plate: string
  uf: string | null
  color: string | null
  model: string | null
  brand: string | null
  wheel_type: string | null
  body_type: string | null
  owner_type: string | null
  status: ErpCatalogStatus
}

export type ErpDriverRecord = {
  id: number
  name: string
  tax_id: string | null
  license_number: string | null
  status: ErpCatalogStatus
}

export type ErpPaginated<T> = {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export type ErpCatalogImportPreview = {
  token: string
  rows: Array<Record<string, unknown>>
  errors: Array<{ line: number, message: string }>
}

export type ErpCatalogPrintSettings = {
  label_width_mm: number
  label_height_mm: number
  show_price: boolean
  show_barcode: boolean
}

export type ErpCatalogPrintLayout = {
  id: number | null
  name: string
  description: string | null
  label_width_mm: string
  label_height_mm: string
  paper_width_mm: string
  paper_height_mm: string | null
  top_margin_mm: string
  left_margin_mm: string
  row_spacing_mm: string
  column_spacing_mm: string
  columns_per_row: number
  labels_per_sheet: number | null
  is_continuous: boolean
  is_default: boolean
  status?: ErpCatalogStatus
}

export type ErpCatalogPrinterProfile = {
  id: number
  name: string
  capability_profile: 'default' | 'simple' | 'SP2000' | 'TEP-200M' | 'P822D'
  status: ErpCatalogStatus
}

export type ErpCatalogLabelItem = {
  id: number
  variant_id: number | null
  product_name: string
  variant_name: string | null
  sku: string | null
  barcode: string | null
  barcode_type: ErpBarcodeType
  sale_price: string | null
  quantity: number
}

export type ErpCatalogLabelResponse = {
  data: ErpCatalogLabelItem[]
  meta: {
    layout: ErpCatalogPrintLayout
    toggles: { show_price: boolean, show_barcode: boolean }
    compatibility_fallback: boolean
    total_copies: number
    output_page_height_mm: string | null
  }
}
