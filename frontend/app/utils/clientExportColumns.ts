import type { ClientSheet } from '~/types/client'
import { formatDate } from '~/utils/index'
import { clientDocumentStateOptions } from '~/utils/clientListFilterOptions'
import {
  clientSheetTaxIdLabel,
  clientStatusPresentation,
  taxRegimeLabel
} from '~/utils/portfolioLabels'

export const clientExportColumns = [
  { id: 'name', label: 'Nome/Razão social' },
  { id: 'tags', label: 'Tags' },
  { id: 'tax_regime', label: 'Regime' },
  { id: 'status', label: 'Situação' },
  { id: 'certificate', label: 'Cert. A1' },
  { id: 'ecac_power_of_attorney', label: 'e-CAC' }
] as const

export type ClientExportColumnId = (typeof clientExportColumns)[number]['id']

export type ClientExportField = {
  header: string
  value: (client: ClientSheet) => string
}

function choiceLabel(value: string | null | undefined) {
  return clientDocumentStateOptions.find(option => option.value === value)?.label ?? ''
}

/** Colunas do arquivo, na ordem da tabela. Nome, certificado e procuração levam o documento e a validade junto. */
export function clientExportFields(columnIds: readonly string[]): ClientExportField[] {
  const chosen = new Set(columnIds)
  const fields: ClientExportField[] = []

  if (chosen.has('name')) {
    fields.push(
      { header: 'Nome/Razão social', value: client => client.name },
      { header: 'CPF/CNPJ', value: client => clientSheetTaxIdLabel(client) }
    )
  }
  if (chosen.has('tags')) {
    fields.push({ header: 'Tags', value: client => client.tags?.map(tag => tag.name).join(', ') ?? '' })
  }
  if (chosen.has('tax_regime')) {
    fields.push({
      header: 'Regime',
      value: client => client.tax_regime ? (taxRegimeLabel[client.tax_regime] ?? client.tax_regime) : ''
    })
  }
  if (chosen.has('status')) {
    fields.push({
      header: 'Situação',
      value: client => clientStatusPresentation[client.status]?.label ?? client.status
    })
  }
  if (chosen.has('certificate')) {
    fields.push(
      { header: 'Cert. A1', value: client => choiceLabel(client.certificate_status) },
      {
        header: 'Validade do certificado',
        value: client => client.certificate?.valid_until ? formatDate(client.certificate.valid_until) : ''
      }
    )
  }
  if (chosen.has('ecac_power_of_attorney')) {
    fields.push(
      { header: 'e-CAC', value: client => choiceLabel(client.ecac_power_of_attorney_status) },
      {
        header: 'Validade da procuração',
        value: client => client.ecac_power_of_attorney?.expires_on ? formatDate(client.ecac_power_of_attorney.expires_on) : ''
      }
    )
  }

  return fields
}
