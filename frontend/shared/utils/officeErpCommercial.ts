import type { OfficeErpAllowance } from '../types/officeErpCommercial'

export function defaultOfficeErpAllowance(): OfficeErpAllowance {
  return { enabled: false, licence_limit: 0, module_keys: [] }
}

export const officeErpModuleOptions = [
  { value: 'purchases', label: 'Compras' },
  { value: 'add_sale', label: 'Vendas' },
  { value: 'pos_sale', label: 'Ponto de venda' },
  { value: 'stock_transfers', label: 'Transferências de estoque' },
  { value: 'stock_adjustment', label: 'Ajustes de estoque' },
  { value: 'expenses', label: 'Despesas' },
  { value: 'cte', label: 'CT-e' },
  { value: 'mdfe', label: 'MDF-e' }
]
