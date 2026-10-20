import type { ErpContactRecord, ErpProductRecord } from './erpCatalogs'

export type ErpImportKind = 'products' | 'contacts'
export type ErpImportError = { record: number, line: number, field: string, code: string, message: string }
export type ErpImportRow = { record: number, line: number, name: string } & Partial<Omit<ErpProductRecord, 'id' | 'status' | 'variants'> & Omit<ErpContactRecord, 'id' | 'status'>>
export type ErpImportPreview = {
  token: string
  kind: ErpImportKind
  schema: string
  expires_at: string
  state: 'pending' | 'completed'
  rows: ErpImportRow[]
  errors: ErpImportError[]
  totals: { records: number, valid: number, invalid: number }
  result: { created: number } | null
}
export type ErpImportRecovery = {
  version: 1
  identity: string
  kind: ErpImportKind
  token: string
  key: string
  unitId: number | null
  state: 'prepared' | 'submitted'
}

export const erpImportHeaders = {
  products: ['name', 'sku', 'kind', 'track_stock', 'sale_price', 'erp_brand_id', 'erp_category_id', 'barcode', 'barcode_type', 'warranty_months', 'warranty_description', 'ncm', 'cest', 'origin_code', 'icms_cst', 'icms_csosn', 'pis_cst', 'cofins_cst', 'ipi_cst', 'ibs_cbs_cst', 'ibs_cbs_classification_code', 'icms_rate', 'pis_rate', 'cofins_rate', 'ipi_rate', 'simple_national_credit_rate'],
  contacts: ['name', 'trade_name', 'tax_id', 'email', 'phone', 'is_customer', 'is_supplier', 'erp_customer_group_id', 'address_street_type', 'address_street', 'address_number', 'address_complement', 'address_district', 'address_postal_code', 'address_city', 'address_state']
} as const
