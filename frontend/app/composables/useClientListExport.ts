import type { Ref } from 'vue'
import type { ClientSheet } from '~/types/client'
import { formatDate } from '~/utils'
import { clientDocumentStateOptions } from '~/utils/clientListFilterOptions'
import {
  clientSheetTaxIdLabel,
  clientStatusPresentation,
  taxRegimeLabel
} from '~/utils/portfolioLabels'

type UseClientListExportOptions = {
  rows: Ref<ClientSheet[]>
  columnVisibility: Ref<Record<string, boolean>>
  isClientSelected: (id: number) => boolean
  selectedCount: Ref<number>
}

function choiceLabel(options: readonly { label: string, value: string }[], value: string | null | undefined) {
  return options.find(option => option.value === value)?.label ?? ''
}

/**
 * CSV formula-injection guard. Excel/LibreOffice evaluate a cell that starts
 * with `=`, `+`, `-` or `@`, so a client named `=HYPERLINK("http://x")` would
 * become a live link in the exported sheet. Prefixing `'` forces text and is
 * dropped on display. Every exported cell here is a formatted string (no
 * numbers), so a leading `-` is never a legitimate negative number.
 */
const FORMULA_PREFIX = /^[-+=@]/

function csvCell(value: string) {
  const safe = FORMULA_PREFIX.test(value) ? `'${value}` : value
  return /[;"\n\r]/.test(safe) ? `"${safe.replaceAll('"', '""')}"` : safe
}

/**
 * Chrome downloads need the anchor in the document at click time (Firefox
 * ignores a detached one) and the object URL alive until the click is
 * processed — revoking in the same tick aborts the download. So: attach, click,
 * detach, then revoke on the next task.
 */
function downloadCsv(fileName: string, body: string) {
  // BOM first: pt-BR Excel only honors UTF-8 when the file starts with U+FEFF.
  const blob = new Blob([`\uFEFF${body}`], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = fileName
  link.rel = 'noopener'
  link.style.display = 'none'
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 0)
}

export function useClientListExport(options: UseClientListExportOptions) {
  const toast = useToast()

  function exportClients(onlySelection = false) {
    const source = onlySelection
      ? options.rows.value.filter(client => options.isClientSelected(client.id))
      : options.rows.value
    if (!source.length) {
      toast.add({ title: 'Nada para exportar', color: 'warning' })
      return
    }

    const visible = (id: string) => options.columnVisibility.value[id] !== false
    const fields: { header: string, value: (client: ClientSheet) => string }[] = []
    if (visible('name')) {
      fields.push(
        { header: 'Nome/Razão social', value: client => client.name },
        { header: 'CPF/CNPJ', value: client => clientSheetTaxIdLabel(client) }
      )
    }
    if (visible('tags')) {
      fields.push({ header: 'Tags', value: client => client.tags?.map(tag => tag.name).join(', ') ?? '' })
    }
    if (visible('tax_regime')) {
      fields.push({
        header: 'Regime',
        value: client => client.tax_regime ? (taxRegimeLabel[client.tax_regime] ?? client.tax_regime) : ''
      })
    }
    if (visible('status')) {
      fields.push({
        header: 'Situação',
        value: client => clientStatusPresentation[client.status]?.label ?? client.status
      })
    }
    if (visible('certificate')) {
      fields.push(
        { header: 'Cert. A1', value: client => choiceLabel(clientDocumentStateOptions, client.certificate_status) },
        {
          header: 'Validade do certificado',
          value: client => client.certificate?.valid_until ? formatDate(client.certificate.valid_until) : ''
        }
      )
    }
    if (visible('ecac_power_of_attorney')) {
      fields.push(
        { header: 'e-CAC', value: client => choiceLabel(clientDocumentStateOptions, client.ecac_power_of_attorney_status) },
        {
          header: 'Validade da procuração',
          value: client => client.ecac_power_of_attorney?.expires_on ? formatDate(client.ecac_power_of_attorney.expires_on) : ''
        }
      )
    }
    if (!fields.length) {
      toast.add({ title: 'Nenhuma coluna visível para exportar', color: 'warning' })
      return
    }

    const lines = [
      fields.map(field => field.header),
      ...source.map(client => fields.map(field => field.value(client)))
    ]
    const body = lines.map(line => line.map(csvCell).join(';')).join('\r\n')
    downloadCsv(`clientes-${new Date().toISOString().slice(0, 10)}.csv`, body)

    if (onlySelection && source.length < options.selectedCount.value) {
      toast.add({
        title: 'Exportamos os clientes já carregados',
        description: 'A seleção inclui clientes que ainda não estão na tabela.',
        color: 'warning'
      })
      return
    }
    toast.add({ title: onlySelection ? 'Seleção exportada' : 'Tabela exportada', color: 'success' })
  }

  return { exportClients }
}
