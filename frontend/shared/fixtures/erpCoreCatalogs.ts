import type { ErpContactRecord, ErpProductRecord } from '../types/erpCatalogs'

/** Frozen decimal/reference fixtures for downstream ERP modules (change 3.1). */
export const sampleErpContactFixture = {
  id: 101,
  erp_customer_group_id: null,
  name: 'Cliente Fixture',
  trade_name: null,
  tax_id: '39053344705',
  email: 'cliente@example.test',
  phone: null,
  is_customer: true,
  is_supplier: false,
  address: { city: 'São Paulo', uf: 'SP' },
  notes: null,
  status: 'active'
} satisfies ErpContactRecord

export const sampleErpProductFixture = {
  id: 201,
  sku: 'FX-001',
  name: 'Produto Fixture',
  kind: 'goods',
  track_stock: true,
  erp_unit_id: 11,
  erp_brand_id: null,
  erp_category_id: null,
  sale_price: '10.5000',
  warranty_months: 12,
  warranty_description: 'Garantia legal',
  barcode: '7891000100103',
  barcode_type: 'EAN13',
  ncm: null,
  cest: null,
  origin_code: null,
  icms_cst: null,
  icms_csosn: null,
  pis_cst: null,
  cofins_cst: null,
  ipi_cst: null,
  icms_rate: null,
  pis_rate: null,
  cofins_rate: null,
  ipi_rate: null,
  simple_national_credit_rate: null,
  ibs_cbs_cst: null,
  ibs_cbs_classification_code: null,
  status: 'active',
  variants: [{
    id: 301,
    sku: 'FX-001-P',
    name: 'P',
    sale_price: '10.5000',
    barcode: null,
    barcode_type: null,
    status: 'active'
  }]
} satisfies ErpProductRecord

/** Posted sale snapshot must keep these literals after catalog edits. */
export const samplePostedLineSnapshot = {
  product_id: sampleErpProductFixture.id,
  description: sampleErpProductFixture.name,
  unit_price: sampleErpProductFixture.sale_price,
  tax_reference: 'ICMS18'
} as const
