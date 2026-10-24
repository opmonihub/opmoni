export type ErpProductVariantUsage = 'sales' | 'purchase'

export interface ErpProductVariantReference {
  id: number
  name: string
  sku: string | null
  status: 'active' | 'archived'
  product: {
    id: number
    name: string
    sku: string | null
    kind: 'goods' | 'service'
    track_stock: boolean
    erp_unit_id: number
    status: 'active' | 'archived'
  }
}

export interface ErpProductVariantLabelSource {
  name?: string
  sku?: string | null
  product?: { name?: string, sku?: string | null }
}

export function erpProductVariantQuery(usage: ErpProductVariantUsage): Record<string, string> {
  return { usage }
}

export function formatErpProductVariantLabel(reference: ErpProductVariantLabelSource): string {
  const parts = [reference.product?.name?.trim() ?? '', reference.name?.trim() ?? '']
    .filter((part, index, all) => part !== '' && (index === 0 || part !== all[index - 1]))
  const sku = reference.sku?.trim() || reference.product?.sku?.trim()
  if (sku) parts.push(sku)
  return parts.join(' · ')
}

export function isErpProductVariantCompatible(reference: ErpProductVariantReference, usage: ErpProductVariantUsage): boolean {
  if (reference.status !== 'active' || reference.product.status !== 'active')
    return false

  return usage === 'sales' || (reference.product.kind === 'goods' && reference.product.track_stock)
}
