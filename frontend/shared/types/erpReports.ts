export interface ErpReportFilters {
  date_from?: string
  date_to?: string
  erp_establishment_id?: number | null
  currency?: string
  status?: string
  direction?: string
}

export interface ErpReportSnapshot {
  category: string
  filters: ErpReportFilters
  totals?: Record<string, string | number>
  sections?: Record<string, Record<string, string | number>>
}

export interface ErpReportExport {
  id: number
  report_type: string
  filters: ErpReportFilters
  status: 'pending' | 'completed' | 'failed'
  expires_at: string | null
  failure_code?: string
}
