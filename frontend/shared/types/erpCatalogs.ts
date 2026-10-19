export type ErpCatalogStatus = 'active' | 'archived'

export type ErpCatalogEditorMode = 'create' | 'detail' | 'edit'

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
  status: ErpCatalogStatus
  variants: ErpProductVariantRecord[]
}

export type ErpProductVariantRecord = {
  id: number
  sku: string | null
  name: string
  sale_price: string | null
  barcode: string | null
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
