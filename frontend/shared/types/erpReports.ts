export interface ErpReportFilters {
  date_from?: string
  date_to?: string
  erp_establishment_id?: number | null
  currency?: string
  status?: string
  direction?: string
  authorized_establishment_scope?: string
  authorized_establishment_ids?: number[] | null
  authorized_companywide_finance?: boolean
}

export type ErpReportValue = string | number | boolean | null | ErpReportValue[] | { [key: string]: ErpReportValue }

export interface ErpReportLedger {
  basis: 'posted_activity' | 'current_position' | 'posted_cost_settlement' | 'current_status_for_created_cohort'
  position_at: string | null
  period: { date_from: string, date_to: string } | null
  timestamp_field: string
  currency: string | null
  rows: Record<string, ErpReportValue>[]
  row_count: number
  detail_limit: number
  detail_truncated: boolean
  totals: Record<string, ErpReportValue>
  status_filter?: string | null
  status_basis?: 'current_at_generation'
  status_observed_at?: string
  direction_filter?: string | null
}

export interface ErpReportSnapshot {
  category: string
  filters: ErpReportFilters
  generated_at?: string
  historical_position?: 'unavailable'
  totals?: Record<string, string | number | null>
  sections?: Record<string, Record<string, string | number | null>>
  ledgers?: Record<string, ErpReportLedger | Record<string, ErpReportLedger>>
}

export interface ErpReportExport {
  id: number
  report_type: string
  filters: ErpReportFilters
  status: 'pending' | 'completed' | 'failed'
  expires_at: string | null
  failure_code?: string
}
