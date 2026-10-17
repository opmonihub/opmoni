export type ErpCatalogStatus = 'active' | 'archived'

export type ErpContactRecord = {
  id: number
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
