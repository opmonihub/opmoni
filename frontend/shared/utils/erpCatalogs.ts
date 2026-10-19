import type { ErpCatalogPrintSettings, ErpPaginated } from '../types/erpCatalogs'

/** API route families for ERP core catalogs (additive change 3). */
export const erpCatalogRoutes = {
  contacts: '/erp/contacts',
  products: '/erp/products',
  units: '/erp/units',
  categories: '/erp/categories',
  brands: '/erp/brands',
  priceGroups: '/erp/price-groups',
  customerGroups: '/erp/customer-groups',
  discountRules: '/erp/discount-rules',
  taxRates: '/erp/tax-settings/tax-rates',
  operationNatures: '/erp/tax-settings/operation-natures',
  carriers: '/erp/carriers',
  vehicles: '/erp/vehicles',
  drivers: '/erp/drivers',
  importProductsPreview: '/erp/catalog/import/products/preview',
  importProductsConfirm: '/erp/catalog/import/products/confirm',
  importContactsPreview: '/erp/catalog/import/contacts/preview',
  importContactsConfirm: '/erp/catalog/import/contacts/confirm',
  printSettings: '/erp/catalog/print-settings',
  productLabels: '/erp/catalog/product-labels'
} as const satisfies Record<string, string>

export function erpCatalogListQuery(search: string, page = 1) {
  const params = new URLSearchParams({ page: String(page) })
  if (search.trim())
    params.set('search', search.trim())
  return params.toString()
}

export function formatErpDecimal(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '')
    return '0'
  return String(value)
}

export function formatErpCatalogStatus(status: unknown): string {
  if (status === 'active')
    return 'Ativo'
  if (status === 'archived')
    return 'Arquivado'
  if (status === 'inactive')
    return 'Inativo'
  return status === null || status === undefined || status === '' ? '—' : String(status)
}

export function emptyPaginated<T>(): ErpPaginated<T> {
  return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
}

export const defaultErpCatalogPrintSettings: ErpCatalogPrintSettings = {
  label_width_mm: 50,
  label_height_mm: 30,
  show_price: true,
  show_barcode: true
}

/** Nuxt page paths for cadastros hub. */
export const erpCadastrosPages = {
  hub: '/erp/cadastros',
  contacts: '/erp/cadastros/contatos',
  products: '/erp/cadastros/produtos',
  units: '/erp/cadastros/unidades',
  brands: '/erp/cadastros/marcas',
  categories: '/erp/cadastros/categorias',
  pricing: '/erp/cadastros/precos',
  customerGroups: '/erp/cadastros/grupos-clientes',
  fiscal: '/erp/cadastros/fiscal',
  logistics: '/erp/cadastros/logistica',
  import: '/erp/cadastros/importacao',
  labels: '/erp/cadastros/etiquetas'
} as const satisfies Record<string, string>
