export type ErpCompanySettingsPayload = {
  name: string
  cnpj: string
  razao_social: string | null
  ie: string | null
  status: string
  time_zone?: string
  accounting_method?: string
  transaction_edit_days?: number
  [key: string]: unknown
}

export type ErpCompanySettingsMeta = {
  date_formats: Record<string, string>
  accounting_methods: Record<string, string>
  months: Record<number, string>
  time_formats: Record<number, string>
  currency_symbol_placements: Record<string, string>
  regimes: Record<string, string>
  ambientes: Record<string, string>
  decimal_places: Record<string, string>
}

export type ErpCompanySettingsResponse = {
  data: ErpCompanySettingsPayload
  meta: ErpCompanySettingsMeta
}
