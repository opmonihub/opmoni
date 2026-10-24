export type ErpOperationNatureCfopChoice = {
  id: number | null
  cfop: string
  reference_version: string | null
  status: 'active' | 'archived'
  implicit: boolean
}

export type ErpOperationNatureProductException = {
  id: number
  erp_product_id: number
  erp_operation_nature_cfop_choice_id: number
  status: 'active' | 'archived'
  product?: { id: number, name: string, sku?: string | null }
}

export type ErpOperationNatureCfopReference = {
  code: string
  description: string
  reference_version: string
  source_metadata: Record<string, string>
  applicability: Record<string, boolean | string[] | string | undefined>
}

export type ErpOperationNatureRulePage<T> = {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    configuration_revision: number
  }
}
