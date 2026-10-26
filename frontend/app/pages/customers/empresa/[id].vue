<script setup lang="ts">
import type { DropdownMenuItem, TabsItem, TimelineItem } from '@nuxt/ui'
import type { MetaListItem } from '~/components/data-table/MetaList.vue'
import type { Client, DeadlineStatus } from '~/types/client'
import type { ClientDispatchContact } from '~/types/whatsapp'
import DispatchContactFormModal from '~/components/monitoring/DispatchContactFormModal.vue'
import { apiErrorMessage } from '~/composables/useApiError'
import {
  clientStatusLabel,
  deadlinePresentation,
  taxRegimeLabel
} from '~/utils/portfolioLabels'
import { formatDate } from '~/utils'
import { pageDetailClass, pageRecordScrollClass } from '~/utils/pageShell'
import { formatTaxId } from '~/utils/taxId'
import {
  whatsappDispatchContactName,
  whatsappDispatchContactValueLabel
} from '~/utils/whatsappDispatch'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const toast = useToast()
const { canManageClients } = useAuth()
const { show, update } = useClients()
const dispatchContactsApi = useDispatchContacts()

function parseId(param: unknown): number | null {
  const raw = Array.isArray(param) ? param[0] : param
  const id = Number(raw)
  return Number.isInteger(id) && id > 0 ? id : null
}

const initialId = parseId(route.params.id)
if (initialId === null) {
  throw createError({ statusCode: 404, statusMessage: 'Página não encontrada' })
}

const activeId = ref(initialId)
const togglingStatus = ref(false)
const tab = ref<'visao' | 'cadastro' | 'contato' | 'endereco' | 'acesso' | 'historico'>('visao')

const tabItems: TabsItem[] = [
  { label: 'Visão geral', value: 'visao', icon: 'i-lucide-layout-dashboard' },
  { label: 'Cadastro', value: 'cadastro', icon: 'i-lucide-building-2' },
  { label: 'Contato', value: 'contato', icon: 'i-lucide-phone' },
  { label: 'Endereço', value: 'endereco', icon: 'i-lucide-map-pin' },
  { label: 'Acesso', value: 'acesso', icon: 'i-lucide-key-round' },
  { label: 'Histórico', value: 'historico', icon: 'i-lucide-history' }
]

const recordCardUi = {
  header: 'px-3 py-2.5 sm:px-4',
  body: 'flex flex-col gap-3 p-3 sm:p-4',
  footer: 'px-3 py-2.5 sm:px-4'
} as const

function openSection(next: typeof tab.value) {
  tab.value = next
}

const cadastroOpen = ref(false)
const contactOpen = ref(false)
const certificateOpen = ref(false)
const tagsOpen = ref(false)
const deleteOpen = ref(false)

const { registry: navigationRegistry } = useListNavigationRegistry()
const readAuthority = computed(() => navigationRegistry.value?.authority ?? '')
let entryGeneration = 0
let entryDisposed = false
onBeforeUnmount(() => {
  entryDisposed = true
  entryGeneration++
})
function commandOwner() {
  const generation = entryGeneration
  const authority = readAuthority.value
  return () => !entryDisposed && generation === entryGeneration && authority === readAuthority.value
}
const confirmedClient = shallowRef<Client | null>(null)
watch(readAuthority, () => {
  entryGeneration++
  togglingStatus.value = false
  confirmedClient.value = null
}, { flush: 'sync' })
const clientReadKey = computed(() => `client-detail-${readAuthority.value}-${activeId.value}`)
const { data: client, status: clientStatus, error: clientError, refresh: refreshClient } = await useAsyncData<Client | null>(
  clientReadKey,
  (_nuxtApp, { signal }) => show(activeId.value, { signal }),
  { default: () => confirmedClient.value, getCachedData: (key, nuxtApp) => nuxtApp.isHydrating ? nuxtApp.payload.data[key] : undefined }
)
if (clientError.value) throw createError({ statusCode: 404, statusMessage: 'Cliente não encontrado' })
const loading = computed(() => clientStatus.value === 'pending')
watch(readAuthority, () => {
  client.value = null
  cadastroOpen.value = false
  contactOpen.value = false
  certificateOpen.value = false
  tagsOpen.value = false
  deleteOpen.value = false
}, { flush: 'sync' })
watch(client, (value) => {
  if (value) confirmedClient.value = value
}, { immediate: true, flush: 'sync' })

function onSaved(saved: Client) {
  if (entryDisposed || saved.id !== activeId.value || !canManageClients.value || !client.value) return
  client.value = saved
}

async function reload() {
  const isCurrent = commandOwner()
  await refreshClient()
  if (!isCurrent()) return
  if (clientError.value) toast.add({ title: 'Não foi possível carregar o cliente', color: 'error' })
}

const isCompany = computed(() => client.value?.person_type !== 'individual')

const personTypeLabel = computed(() => {
  if (client.value?.person_type === 'individual') return 'Pessoa física'
  if (client.value?.person_type === 'company') return 'Pessoa jurídica'
  return '—'
})

const documentKind = computed(() => client.value?.person_type === 'individual' ? 'CPF' : 'CNPJ')

const documentLabel = computed(() => {
  const value = client.value?.tax_id
  if (!value) return '—'
  return formatTaxId(value)
})

const tradeName = computed(() => {
  const trade = client.value?.trade_name?.trim()
  if (!trade || trade === client.value?.name) return null
  return trade
})

const initials = computed(() => {
  const name = client.value?.name?.trim() ?? ''
  const parts = name.split(/\s+/).filter(Boolean).slice(0, 2)
  const letters = parts.map(part => part[0]).join('').toUpperCase()
  return letters || '•'
})

const regimeLabel = computed(() => {
  const regime = client.value?.tax_regime
  return regime ? taxRegimeLabel[regime] : '—'
})

const statusColor = computed(() => client.value?.status === 'active' ? 'success' as const : 'neutral' as const)

const registrationTone = computed(() => {
  const status = (client.value?.registration_status ?? '').toLowerCase()
  if (status.includes('ativa')) return 'success' as const
  if (status.includes('baixada') || status.includes('cancelada') || status.includes('inapta') || status.includes('suspensa') || status.includes('nula') || status.includes('impedida')) return 'error' as const
  if (status.includes('pendente') || status.includes('irregular')) return 'warning' as const
  return 'neutral' as const
})

const openedAge = computed(() => {
  const opened = client.value?.opened_at
  if (!opened) return null
  const start = new Date(opened)
  if (Number.isNaN(start.getTime())) return null
  const now = new Date()
  let months = (now.getFullYear() - start.getFullYear()) * 12 + (now.getMonth() - start.getMonth())
  if (now.getDate() < start.getDate()) months--
  if (months < 0) return null
  if (months < 12) return months <= 1 ? 'menos de 1 ano' : `${months} meses`
  const years = Math.floor(months / 12)
  return years === 1 ? '1 ano' : `${years} anos`
})

const cityLabel = computed(() => {
  const city = client.value?.address?.city
  const state = client.value?.address?.state
  if (city && state) return `${city}/${state}`
  return city ?? state ?? null
})

const addressLine1 = computed(() => {
  const a = client.value?.address
  if (!a) return null
  const street = [a.street_type, a.street].filter(Boolean).join(' ')
  let line = street || null
  if (a.number) line = line ? `${line}, ${a.number}` : a.number
  if (a.complement) line = line ? `${line} — ${a.complement}` : a.complement
  return line
})

const addressCep = computed(() => {
  const raw = client.value?.address?.postal_code ?? ''
  const cep = raw.replace(/\D/g, '')
  if (cep.length === 8) return cep.replace(/(\d{5})(\d{3})/, '$1-$2')
  return raw || null
})

const hasAddress = computed(() => {
  const a = client.value?.address
  return !!a && !!(a.street || a.number || a.district || a.city || a.state || a.postal_code)
})

const mapsUrl = computed(() => {
  const query = [addressLine1.value, client.value?.address?.district, cityLabel.value, addressCep.value]
    .filter(Boolean).join(', ')
  if (!query) return null
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(query)}`
})

const phoneLabel = computed(() => {
  const phone = client.value?.phone ?? ''
  const digits = phone.replace(/\D/g, '')
  if (digits.length === 11) return digits.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3')
  if (digits.length === 10) return digits.replace(/(\d{2})(\d{4})(\d{4})/, '($1) $2-$3')
  return phone || '—'
})

const clientContactFacts = computed<MetaListItem[]>(() => [
  { label: 'E-mail', value: client.value?.email ?? '—', truncate: true },
  { label: 'Telefone', value: phoneLabel.value, mono: true }
])

const certStatus = computed<DeadlineStatus>(() => client.value?.certificate_status ?? 'missing')
const poaStatus = computed<DeadlineStatus>(() => client.value?.ecac_power_of_attorney_status ?? 'missing')

function daysUntil(value: string | null | undefined): number | null {
  if (!value) return null
  const target = new Date(value)
  if (Number.isNaN(target.getTime())) return null
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  target.setHours(0, 0, 0, 0)
  return Math.round((target.getTime() - today.getTime()) / 86400000)
}

function deadlineHint(days: number | null): string {
  if (days === null) return ''
  if (days === 0) return 'vence hoje'
  if (days === 1) return 'vence amanhã'
  if (days > 1) return `vence em ${days} dias`
  const overdue = Math.abs(days)
  if (overdue === 1) return 'venceu ontem'
  return `venceu há ${overdue} dias`
}

function deadlineDistance(days: number | null): string {
  if (days === null) return ''
  if (days === 0) return 'hoje'
  if (days === 1) return 'amanhã'
  if (days > 1) return `em ${days} dias`
  const overdue = Math.abs(days)
  if (overdue === 1) return 'ontem'
  return `há ${overdue} dias`
}

function progressBetween(start: string | null | undefined, end: string | null | undefined): number {
  if (!start || !end) return 0
  const from = new Date(start).getTime()
  const to = new Date(end).getTime()
  if (Number.isNaN(from) || Number.isNaN(to) || to <= from) return 0
  const pct = ((Date.now() - from) / (to - from)) * 100
  return Math.min(100, Math.max(0, Math.round(pct)))
}

const certDays = computed(() => daysUntil(client.value?.certificate?.valid_until))
const poaDays = computed(() => daysUntil(client.value?.ecac_power_of_attorney?.expires_on))
const certHint = computed(() => deadlineHint(certDays.value))
const poaHint = computed(() => deadlineHint(poaDays.value))
const certDistance = computed(() => deadlineDistance(certDays.value))
const poaDistance = computed(() => deadlineDistance(poaDays.value))
const certPct = computed(() => progressBetween(client.value?.certificate?.valid_from, client.value?.certificate?.valid_until))

const certManageLabel = computed(() => {
  if (certStatus.value === 'missing') return 'Cadastrar certificado'
  if (certStatus.value === 'expired') return 'Renovar certificado'
  return 'Gerenciar certificado'
})

const poaFamilies = computed(() => client.value?.ecac_power_of_attorney?.families ?? [])

const poaFamilyStateLabel: Record<string, string> = {
  established: 'Em vigor',
  expired: 'Vencida',
  rejected: 'Recusada',
  pending: 'Pendente'
}

const poaFamilyStateColor: Record<string, 'success' | 'error' | 'warning' | 'neutral'> = {
  established: 'success',
  expired: 'error',
  rejected: 'error',
  pending: 'warning'
}

const poaFacts = computed<MetaListItem[]>(() => {
  const summary = client.value?.ecac_power_of_attorney
  if (!summary || poaStatus.value === 'missing') return []
  const expiresOn = summary.expires_on
  const items: MetaListItem[] = [
    { label: 'Validade', value: expiresOn ? formatDate(expiresOn) : 'Sem vencimento informado', mono: Boolean(expiresOn) }
  ]
  if (poaDistance.value) items.push({ label: 'Prazo', value: poaDistance.value })
  return items
})

function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(date)
}

const syncedText = computed(() => {
  if (client.value?.source_updated_at) return `Receita · ${formatDate(client.value.source_updated_at)}`
  if (client.value?.looked_up_at) return `Consulta · ${formatDateTime(client.value.looked_up_at)}`
  return 'Nunca sincronizado'
})

interface HistoryEvent {
  key: string
  title: string
  date: string
  detail: string | null
  icon: string
}

function historyDetail(detail: string | null): string | undefined {
  if (!detail) return undefined
  const commonName = detail.match(/CN=([^/]+)/)
  if (commonName?.[1]) return commonName[1]
  return detail
}

const historyEvents = computed<HistoryEvent[]>(() => {
  const c = client.value
  if (!c) return []
  const items: HistoryEvent[] = []
  if (c.opened_at) items.push({ key: 'opened', title: 'Abertura da empresa', date: c.opened_at, detail: c.primary_activity?.description ?? null, icon: 'i-lucide-building-2' })
  if (c.certificate?.valid_from) items.push({ key: 'cert-from', title: 'Certificado A1 emitido', date: c.certificate.valid_from, detail: c.certificate.subject, icon: 'i-lucide-key-round' })
  if (c.certificate?.valid_until) items.push({ key: 'cert-until', title: certStatus.value === 'expired' ? 'Certificado A1 venceu' : 'Certificado A1 vence', date: c.certificate.valid_until, detail: certDistance.value || null, icon: 'i-lucide-key-round' })
  if (c.ecac_power_of_attorney?.expires_on) items.push({ key: 'poa-until', title: poaStatus.value === 'expired' ? 'Procuração e-CAC venceu' : 'Procuração e-CAC vence', date: c.ecac_power_of_attorney.expires_on, detail: poaDistance.value || null, icon: 'i-lucide-file-key-2' })
  if (c.looked_up_at) items.push({ key: 'lookup', title: 'Última consulta à Receita', date: c.looked_up_at, detail: null, icon: 'i-lucide-refresh-cw' })
  items.push({ key: 'created', title: 'Cliente entrou para a carteira', date: c.created_at, detail: null, icon: 'i-lucide-plus' })
  return items
    .filter(item => !Number.isNaN(new Date(item.date).getTime()))
    .sort((a, b) => new Date(b.date).getTime() - new Date(a.date).getTime())
    .slice(0, 7)
})

const historyItems = computed<TimelineItem[]>(() => historyEvents.value.map(item => ({
  value: item.key,
  date: formatDate(item.date),
  title: item.title,
  description: historyDetail(item.detail),
  icon: item.icon
})))

const summaryText = computed(() => {
  const c = client.value
  if (!c) return ''
  const lines = [
    `${c.name}${tradeName.value ? ` (${tradeName.value})` : ''}`,
    `${documentKind.value}: ${documentLabel.value}`,
    `Situação: ${clientStatusLabel[c.status]} · Regime: ${regimeLabel.value}`,
    `Receita: ${c.registration_status ?? '—'} · Abertura: ${c.opened_at ? formatDate(c.opened_at) : '—'}${openedAge.value ? ` · Idade: ${openedAge.value}` : ''}`,
    `Endereço: ${hasAddress.value ? [addressLine1.value, c.address?.district, cityLabel.value, addressCep.value ? `CEP ${addressCep.value}` : null].filter(Boolean).join(', ') : '—'}`,
    `E-mail: ${c.email ?? '—'} · Telefone: ${phoneLabel.value}`,
    dispatchContacts.value.length
      ? dispatchContacts.value.map(item => `${item.channel === 'whatsapp' ? 'WhatsApp' : 'E-mail'}: ${whatsappDispatchContactValueLabel(item)}`).join(' · ')
      : 'Contatos de envio: —',
    `Certificado A1: ${c.certificate ? `${formatDate(c.certificate.valid_until)}${certDistance.value ? ` · ${certDistance.value}` : ''}` : 'não cadastrado'}`,
    `Procuração e-CAC: ${c.ecac_power_of_attorney?.expires_on ? `${formatDate(c.ecac_power_of_attorney.expires_on)}${poaDistance.value ? ` · ${poaDistance.value}` : ''}` : deadlinePresentation[poaStatus.value].label}`
  ]
  return lines.join('\n')
})

async function copyText(value: string, label: string) {
  if (!value) return
  try {
    await navigator.clipboard.writeText(value)
    toast.add({ title: `${label} copiado`, color: 'success' })
  } catch {
    toast.add({ title: 'Não foi possível copiar', description: `Tente selecionar o ${label.toLowerCase()} manualmente.`, color: 'error' })
  }
}

async function toggleStatus() {
  if (!client.value || togglingStatus.value) return
  const isCurrent = commandOwner()
  togglingStatus.value = true
  try {
    const saved = await update(client.value.id, { status: client.value.status === 'active' ? 'inactive' : 'active' })
    if (!isCurrent()) return
    onSaved(saved)
    toast.add({ title: saved.status === 'active' ? 'Cliente ativado' : 'Cliente inativado', color: 'success' })
  } catch {
    if (!isCurrent()) return
    toast.add({ title: 'Não foi possível alterar a situação', color: 'error' })
  } finally {
    if (isCurrent()) togglingStatus.value = false
  }
}

function onDeleted() {
  if (entryDisposed || !client.value || !canManageClients.value) return
  navigateTo('/customers/certificados')
}

const tagAssignment = computed(() => ({ ids: client.value ? [client.value.id] : [] }))

function onCopySummary() {
  void copyText(summaryText.value, 'Resumo')
}

function contactCaption(item: ClientDispatchContact): string | null {
  const label = item.label?.trim()
  if (!label || label === whatsappDispatchContactValueLabel(item)) return null
  return label
}

const primaryActivityLabel = computed(() => {
  const activity = client.value?.primary_activity
  if (!activity?.code && !activity?.description) return null
  if (activity.code && activity.description) return `${activity.code} — ${activity.description}`
  return activity.description ?? activity.code ?? null
})

const cadastroFacts = computed<MetaListItem[]>(() => {
  const current = client.value
  if (!current) return []
  return [
    { label: 'Tipo de pessoa', value: personTypeLabel.value },
    { label: documentKind.value, value: documentLabel.value, mono: true },
    { label: 'Situação na carteira', value: clientStatusLabel[current.status] },
    { label: isCompany.value ? 'Razão social' : 'Nome completo', value: current.name },
    { label: 'Nome fantasia', value: tradeName.value ?? '—', when: isCompany.value },
    { label: 'Regime tributário', value: regimeLabel.value },
    {
      label: 'Situação na Receita',
      value: current.registration_status ?? '—',
      when: isCompany.value
    },
    {
      label: 'Data da situação',
      value: current.registration_status_date ? formatDate(current.registration_status_date) : '—',
      mono: true,
      when: isCompany.value && Boolean(current.registration_status_date) && current.registration_status_date?.slice(0, 10) !== current.opened_at?.slice(0, 10)
    },
    {
      label: 'Abertura',
      value: current.opened_at ? formatDate(current.opened_at) : '—',
      mono: true,
      when: Boolean(current.opened_at)
    },
    {
      label: 'Idade',
      value: openedAge.value ?? '—',
      when: Boolean(openedAge.value)
    },
    { label: 'Porte', value: current.company_size ?? '—', when: isCompany.value },
    { label: 'Natureza jurídica', value: current.legal_nature ?? '—', when: isCompany.value }
  ]
})

const addressFacts = computed<MetaListItem[]>(() => [
  { label: 'Bairro', value: client.value?.address?.district ?? '—' },
  { label: 'Cidade/UF', value: cityLabel.value ?? '—' },
  { label: 'CEP', value: addressCep.value ?? '—', mono: true }
])

const certificateFacts = computed<MetaListItem[]>(() => {
  const certificate = client.value?.certificate
  if (!certificate) return []
  return [
    { label: 'Titular', value: historyDetail(certificate.subject) ?? certificate.subject, truncate: true },
    { label: 'Emitido em', value: formatDate(certificate.valid_from), mono: true },
    { label: 'Série', value: certificate.serial_number, mono: true, truncate: true },
    { label: 'Arquivo', value: certificate.original_filename, truncate: true }
  ]
})

const systemFacts = computed<MetaListItem[]>(() => {
  const current = client.value
  if (!current) return []
  return [
    { label: 'Código', value: `#${current.id}`, mono: true },
    { label: 'Na carteira desde', value: formatDate(current.created_at), mono: true },
    { label: 'Última edição', value: formatDate(current.updated_at), mono: true }
  ]
})

const addressEmptyActions = computed(() => {
  if (!canManageClients.value) return undefined
  if (isCompany.value) {
    return [{
      label: 'Conferir na Receita',
      icon: 'i-lucide-refresh-cw',
      color: 'neutral' as const,
      variant: 'outline' as const,
      onClick: () => {
        tab.value = 'cadastro'
        cadastroOpen.value = true
      }
    }]
  }
  return [{
    label: 'Editar endereço',
    icon: 'i-lucide-pencil',
    color: 'neutral' as const,
    variant: 'outline' as const,
    onClick: () => {
      contactOpen.value = true
    }
  }]
})

/**
 * Os Contatos de envio do cliente (WhatsApp ou e-mail, um canal por
 * registro): os destinos do Enviar guias da folha de monitoramento.
 * Qualquer membro lê a lista; cadastrar/editar/remover é admin|operador —
 * o backend responde 403 e a tela esconde os botões.
 */
const dispatchContactFormOpen = ref(false)
const dispatchContactEditing = ref<ClientDispatchContact | null>(null)
const dispatchContactDeleting = ref<ClientDispatchContact | null>(null)
const confirmedContacts = shallowRef<ClientDispatchContact[]>([])
watch(readAuthority, () => {
  confirmedContacts.value = []
}, { flush: 'sync' })
const contactsReadKey = computed(() => `client-dispatch-contacts-${readAuthority.value}-${activeId.value}`)
const { data: dispatchContacts, status: contactsStatus, error: contactsError, refresh: loadDispatchContacts } = await useAsyncData<ClientDispatchContact[]>(
  contactsReadKey,
  async (_nuxtApp, { signal }) => {
    try {
      return await dispatchContactsApi.list(activeId.value, { signal })
    } catch (error) {
      // Feature desligada (404) é estado inerte: sem contatos, sem alerta.
      if (apiStatus(error) === 404) return []
      throw error
    }
  },
  { default: () => confirmedContacts.value, getCachedData: (key, nuxtApp) => nuxtApp.isHydrating ? nuxtApp.payload.data[key] : undefined }
)
watch(dispatchContacts, (value) => {
  confirmedContacts.value = value
}, { immediate: true, flush: 'sync' })
watch(readAuthority, () => {
  dispatchContacts.value = []
  dispatchContactFormOpen.value = false
  dispatchContactEditing.value = null
  dispatchContactDeleting.value = null
}, { flush: 'sync' })
const dispatchContactsLoading = computed(() => contactsStatus.value === 'pending')
const dispatchContactsFailed = computed(() => Boolean(contactsError.value))

function openDispatchContactForm(contact: ClientDispatchContact | null) {
  dispatchContactEditing.value = contact
  dispatchContactFormOpen.value = true
}

const overviewContacts = computed(() => dispatchContacts.value.slice(0, 3))
const overviewContactsExtra = computed(() => Math.max(0, dispatchContacts.value.length - overviewContacts.value.length))

const contactEmptyActions = computed(() => {
  if (!canManageClients.value) return undefined
  return [{
    label: 'Adicionar',
    icon: 'i-lucide-plus',
    color: 'neutral' as const,
    variant: 'outline' as const,
    onClick: () => openDispatchContactForm(null)
  }]
})

async function removeDispatchContact(contact: ClientDispatchContact) {
  if (!client.value) return
  const isCurrent = commandOwner()
  try {
    await dispatchContactsApi.remove(client.value.id, contact.id)
    if (!isCurrent()) return
    dispatchContacts.value = dispatchContacts.value.filter(item => item.id !== contact.id)
    toast.add({ title: 'Contato de envio removido', color: 'success' })
  } catch (e) {
    if (!isCurrent()) return
    toast.add({
      title: 'Não foi possível remover o Contato de envio',
      description: apiErrorMessage(e) ?? 'Tente novamente.',
      color: 'error'
    })
  } finally {
    if (isCurrent()) dispatchContactDeleting.value = null
  }
}

const overflowItems = computed<DropdownMenuItem[][]>(() => {
  const copyItem: DropdownMenuItem = { label: 'Copiar resumo da ficha', icon: 'i-lucide-copy', onSelect: onCopySummary }
  if (!canManageClients.value) return [[copyItem]]
  return [[
    { label: 'Editar cadastro', icon: 'i-lucide-pencil', onSelect: () => { cadastroOpen.value = true } },
    { label: 'Editar contato', icon: 'i-lucide-mail', onSelect: () => { contactOpen.value = true } },
    { label: 'Gerenciar tags', icon: 'i-lucide-tags', onSelect: () => { tagsOpen.value = true } },
    copyItem
  ], [
    { label: client.value?.status === 'active' ? 'Inativar cliente' : 'Ativar cliente', icon: 'i-lucide-power', disabled: togglingStatus.value, onSelect: () => { void toggleStatus() } },
    { label: 'Excluir cliente', icon: 'i-lucide-trash-2', color: 'error', onSelect: () => { deleteOpen.value = true } }
  ]]
})
</script>

<template>
  <div :class="pageRecordScrollClass">
    <div :class="pageDetailClass">
      <template v-if="loading">
        <USkeleton class="h-28 w-full rounded-lg" />
        <USkeleton class="h-9 w-full" />
        <div class="grid gap-3 lg:grid-cols-[minmax(0,1.4fr)_minmax(16rem,0.7fr)]">
          <USkeleton class="h-64 w-full rounded-lg" />
          <USkeleton class="h-64 w-full rounded-lg" />
        </div>
      </template>

      <UEmpty
        v-else-if="!client"
        icon="i-lucide-user-x"
        title="Cliente não encontrado"
        description="O cliente pode ter sido excluído ou o link está incorreto."
        variant="naked"
        :actions="[{ label: 'Recarregar', icon: 'i-lucide-refresh-cw', onClick: () => reload() }]"
      />

      <template v-else>
        <UCard :ui="{ body: 'px-3 py-2 sm:px-3.5 sm:py-2' }">
          <div class="flex items-start gap-2.5">
            <UAvatar
              :text="initials"
              :alt="client.name"
              size="md"
              class="mt-0.5"
              color="primary"
            />
            <div class="min-w-0 flex-1">
              <div class="flex items-start gap-2">
                <div class="min-w-0 flex-1">
                  <h1 class="truncate text-base font-semibold leading-tight text-highlighted">
                    {{ client.name }}
                  </h1>
                  <p v-if="tradeName" class="truncate text-xs leading-4 text-muted">
                    {{ tradeName }}
                  </p>
                  <div class="mt-0.5 flex flex-wrap items-center gap-x-0.5 gap-y-0.5">
                    <p class="text-xs tabular-nums text-muted">
                      {{ documentKind }} {{ documentLabel }}
                    </p>
                    <UButton
                      icon="i-lucide-copy"
                      color="neutral"
                      variant="ghost"
                      size="xs"
                      class="-my-0.5"
                      :aria-label="`Copiar ${documentKind}`"
                      @click="copyText(client.tax_id ?? '', documentKind)"
                    />
                  </div>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                  <UButton
                    v-if="canManageClients"
                    :label="client.status === 'active' ? 'Inativar' : 'Ativar'"
                    icon="i-lucide-power"
                    color="neutral"
                    variant="outline"
                    size="xs"
                    class="hidden sm:inline-flex"
                    :loading="togglingStatus"
                    @click="toggleStatus"
                  />
                  <UDropdownMenu :items="overflowItems" :content="{ align: 'end' }">
                    <UButton
                      icon="i-lucide-ellipsis-vertical"
                      color="neutral"
                      variant="ghost"
                      size="xs"
                      :aria-label="`Mais ações para ${client.name}`"
                    />
                  </UDropdownMenu>
                </div>
              </div>
              <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                <div class="flex flex-wrap items-center gap-1">
                  <UBadge
                    :color="statusColor"
                    variant="subtle"
                    size="sm"
                    :label="clientStatusLabel[client.status]"
                  />
                  <UBadge
                    color="neutral"
                    variant="subtle"
                    size="sm"
                    :label="personTypeLabel"
                  />
                  <UBadge
                    color="neutral"
                    variant="subtle"
                    size="sm"
                    :label="regimeLabel"
                  />
                  <UBadge
                    v-if="client.registration_status"
                    :color="registrationTone"
                    variant="subtle"
                    size="sm"
                    :label="client.registration_status"
                  />
                </div>
                <div class="flex flex-wrap items-center gap-1">
                  <UBadge
                    :color="deadlinePresentation[certStatus].color"
                    variant="subtle"
                    size="sm"
                    :icon="deadlinePresentation[certStatus].icon"
                    :label="`A1: ${deadlinePresentation[certStatus].label}`"
                  />
                  <UBadge
                    :color="deadlinePresentation[poaStatus].color"
                    variant="subtle"
                    size="sm"
                    :icon="deadlinePresentation[poaStatus].icon"
                    :label="`e-CAC: ${deadlinePresentation[poaStatus].label}`"
                  />
                </div>
              </div>
              <div class="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-xs leading-4 text-muted">
                <span v-if="cityLabel" class="inline-flex items-center gap-1">
                  <UIcon name="i-lucide-map-pin" class="size-3.5 shrink-0" />
                  {{ cityLabel }}
                </span>
                <span v-if="openedAge" class="inline-flex items-center gap-1">
                  <UIcon name="i-lucide-calendar" class="size-3.5 shrink-0" />
                  {{ openedAge }}
                </span>
                <span class="inline-flex items-center gap-1">
                  <UIcon name="i-lucide-refresh-cw" class="size-3.5 shrink-0" />
                  {{ syncedText }}
                </span>
              </div>
              <div v-if="client.tags?.length" class="mt-1.5">
                <CustomersClientTags :tags="client.tags" />
              </div>
            </div>
          </div>
        </UCard>

        <div class="sticky top-0 z-20 -mx-3 bg-default px-3 sm:-mx-4 sm:px-4">
          <UTabs
            v-model="tab"
            :items="tabItems"
            variant="link"
            :content="false"
            class="w-full"
          />
        </div>

        <div v-if="tab === 'visao'" class="grid items-start gap-3 lg:grid-cols-[minmax(0,1.4fr)_minmax(16rem,0.7fr)]">
          <UCard :ui="recordCardUi">
            <section class="flex flex-col gap-2.5">
              <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-highlighted">
                  Cadastro
                </h2>
                <UButton
                  label="Abrir"
                  aria-label="Abrir cadastro"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  trailing-icon="i-lucide-arrow-right"
                  @click="openSection('cadastro')"
                />
              </div>
              <DataTableMetaList
                :items="[
                  { label: 'Regime', value: regimeLabel },
                  { label: 'Na Receita', value: client.registration_status ?? '—' }
                ]"
                columns="grid-cols-2 gap-x-4 gap-y-2"
              />
              <p v-if="isCompany" class="text-sm text-default">
                <span class="mb-0.5 block text-xs text-muted">Atividade principal</span>
                <span class="line-clamp-2">{{ primaryActivityLabel ?? '—' }}</span>
              </p>
            </section>

            <USeparator />

            <section class="flex flex-col gap-2.5">
              <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-highlighted">
                  Endereço
                </h2>
                <UButton
                  label="Abrir"
                  aria-label="Abrir endereço"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  trailing-icon="i-lucide-arrow-right"
                  @click="openSection('endereco')"
                />
              </div>
              <template v-if="hasAddress">
                <p class="text-sm text-highlighted">
                  {{ addressLine1 ?? '—' }}
                </p>
                <p class="text-xs text-muted">
                  {{ [client.address?.district, cityLabel, addressCep].filter(Boolean).join(' · ') }}
                </p>
              </template>
              <p v-else class="text-sm text-muted">
                Nenhum endereço cadastrado.
              </p>
            </section>
          </UCard>

          <UCard :ui="recordCardUi">
            <section class="flex flex-col gap-2.5">
              <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-highlighted">
                  Acesso
                </h2>
                <UButton
                  label="Abrir"
                  aria-label="Abrir acesso"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  trailing-icon="i-lucide-arrow-right"
                  @click="openSection('acesso')"
                />
              </div>
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="text-sm text-default">
                    Certificado A1
                  </p>
                  <p class="text-xs text-muted">
                    {{ client.certificate ? [formatDate(client.certificate.valid_until), certDistance].filter(Boolean).join(' · ') : deadlinePresentation[certStatus].label }}
                  </p>
                </div>
                <UBadge
                  :color="deadlinePresentation[certStatus].color"
                  variant="subtle"
                  size="sm"
                  :icon="deadlinePresentation[certStatus].icon"
                  :label="deadlinePresentation[certStatus].label"
                />
              </div>
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="text-sm text-default">
                    Procuração e-CAC
                  </p>
                  <p class="text-xs text-muted">
                    {{ client.ecac_power_of_attorney?.expires_on ? [formatDate(client.ecac_power_of_attorney.expires_on), poaDistance].filter(Boolean).join(' · ') : deadlinePresentation[poaStatus].label }}
                  </p>
                </div>
                <UBadge
                  :color="deadlinePresentation[poaStatus].color"
                  variant="subtle"
                  size="sm"
                  :icon="deadlinePresentation[poaStatus].icon"
                  :label="deadlinePresentation[poaStatus].label"
                />
              </div>
            </section>

            <USeparator />

            <section class="flex flex-col gap-2.5">
              <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-highlighted">
                  Contato
                </h2>
                <UButton
                  label="Abrir"
                  aria-label="Abrir contato"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  trailing-icon="i-lucide-arrow-right"
                  @click="openSection('contato')"
                />
              </div>
              <DataTableMetaList
                v-if="client.email || client.phone"
                :items="clientContactFacts.filter(item => item.value !== '—')"
                layout="stack"
              />
              <p v-if="dispatchContactsLoading && dispatchContacts.length === 0" class="text-sm text-muted">
                Carregando Contatos de envio…
              </p>
              <ErrorRetryAlert
                v-else-if="dispatchContactsFailed"
                title="Não foi possível carregar os Contatos de envio"
                @retry="loadDispatchContacts"
              />
              <p v-else-if="dispatchContacts.length === 0" class="text-sm text-muted">
                Nenhum Contato de envio cadastrado.
              </p>
              <ul v-else class="flex flex-col gap-2">
                <li
                  v-for="item in overviewContacts"
                  :key="item.id"
                  class="flex items-center justify-between gap-2 text-sm"
                >
                  <span class="min-w-0 truncate tabular-nums text-default">{{ whatsappDispatchContactValueLabel(item) }}</span>
                  <UBadge
                    :color="item.channel === 'whatsapp' ? 'success' : 'neutral'"
                    variant="subtle"
                    size="sm"
                    :label="item.channel === 'whatsapp' ? 'WhatsApp' : 'E-mail'"
                  />
                </li>
              </ul>
              <p v-if="overviewContactsExtra > 0" class="text-xs text-muted">
                Mais {{ overviewContactsExtra }} na lista.
              </p>
            </section>
          </UCard>
        </div>

        <div v-else-if="tab === 'acesso'" class="flex flex-col gap-3">
          <UAlert
            v-if="certStatus === 'expired'"
            color="error"
            variant="subtle"
            icon="i-lucide-circle-alert"
            title="Certificado A1 vencido"
            :description="client.certificate ? `Venceu em ${formatDate(client.certificate.valid_until)} (${certHint}). Renove para manter as rotinas fiscais.` : 'Cadastre um certificado válido para manter as rotinas fiscais.'"
            :actions="canManageClients ? [{ label: 'Renovar agora', color: 'error', variant: 'solid', onClick: () => { certificateOpen = true } }] : undefined"
          />
          <UAlert
            v-if="poaStatus === 'expired'"
            color="error"
            variant="subtle"
            icon="i-lucide-circle-alert"
            title="Procuração e-CAC vencida"
            :description="client.ecac_power_of_attorney?.expires_on ? `Venceu em ${formatDate(client.ecac_power_of_attorney.expires_on)} (${poaHint}). A outorga precisa ser renovada no e-CAC.` : 'A procuração que o provedor confirmou está vencida. A outorga precisa ser renovada no e-CAC.'"
          />
          <UAlert
            v-if="certStatus !== 'expired' && poaStatus !== 'expired' && (certStatus === 'expiring' || poaStatus === 'expiring')"
            color="warning"
            variant="subtle"
            icon="i-lucide-clock-alert"
            title="Atenção aos vencimentos"
            :description="[
              certStatus === 'expiring' && client.certificate ? `Certificado ${certHint} (${formatDate(client.certificate.valid_until)}).` : null,
              poaStatus === 'expiring' && client.ecac_power_of_attorney?.expires_on ? `Procuração ${poaHint} (${formatDate(client.ecac_power_of_attorney.expires_on)}).` : null
            ].filter(Boolean).join(' ')"
          />

          <div class="grid items-stretch gap-3 sm:grid-cols-2">
            <UCard :ui="recordCardUi">
              <template #header>
                <div class="flex items-center gap-2">
                  <h2 class="min-w-0 flex-1 truncate text-sm font-semibold text-highlighted">
                    Certificado A1
                  </h2>
                  <UBadge
                    :color="deadlinePresentation[certStatus].color"
                    variant="subtle"
                    :icon="deadlinePresentation[certStatus].icon"
                    :label="deadlinePresentation[certStatus].label"
                  />
                </div>
              </template>

              <template v-if="client.certificate">
                <p class="text-sm tabular-nums text-highlighted">
                  {{ formatDate(client.certificate.valid_until) }}
                </p>
                <p v-if="certDistance" class="text-xs text-muted">
                  {{ certDistance }}
                </p>
                <UProgress
                  :model-value="certPct"
                  :color="deadlinePresentation[certStatus].color"
                  size="sm"
                  :get-value-label="() => 'Vigência do certificado'"
                />
                <DataTableMetaList :items="certificateFacts" layout="stack" />
              </template>
              <UEmpty
                v-else
                variant="naked"
                size="sm"
                icon="i-lucide-key-round"
                title="Nenhum certificado"
                description="Sem ele, as rotinas com a Receita ficam paradas."
                :ui="{ root: 'p-2 sm:p-3' }"
              />

              <template v-if="canManageClients" #footer>
                <UButton
                  :label="certManageLabel"
                  icon="i-lucide-key-round"
                  color="neutral"
                  variant="outline"
                  size="sm"
                  block
                  @click="certificateOpen = true"
                />
              </template>
            </UCard>

            <UCard :ui="recordCardUi">
              <template #header>
                <div class="flex items-center gap-2">
                  <h2 class="min-w-0 flex-1 truncate text-sm font-semibold text-highlighted">
                    Procuração e-CAC
                  </h2>
                  <UBadge
                    :color="deadlinePresentation[poaStatus].color"
                    variant="subtle"
                    :icon="deadlinePresentation[poaStatus].icon"
                    :label="deadlinePresentation[poaStatus].label"
                  />
                </div>
              </template>

              <DataTableMetaList
                v-if="poaFacts.length"
                :items="poaFacts"
                layout="stack"
              />
              <UEmpty
                v-else
                variant="naked"
                size="sm"
                icon="i-lucide-file-key-2"
                title="Sem outorga confirmada"
                description="A procuração é concedida no e-CAC e lida aqui. Não há cadastro manual."
                :ui="{ root: 'p-2 sm:p-3' }"
              />
              <div v-if="poaFamilies.length" class="flex flex-col gap-1.5">
                <p class="text-xs font-medium text-muted">
                  Famílias de serviço
                </p>
                <ul class="flex flex-col gap-1">
                  <li
                    v-for="family in poaFamilies"
                    :key="family.family"
                    class="flex items-center justify-between gap-3 text-xs"
                  >
                    <span class="min-w-0 truncate tabular-nums text-default" :title="`Família ${family.family}`">
                      {{ family.family }}
                    </span>
                    <UBadge
                      :label="poaFamilyStateLabel[family.state] ?? family.state"
                      :color="poaFamilyStateColor[family.state] ?? 'neutral'"
                      variant="subtle"
                      size="sm"
                      class="shrink-0"
                    />
                  </li>
                </ul>
              </div>
            </UCard>
          </div>
        </div>

        <div v-else-if="tab === 'cadastro'" class="flex flex-col gap-3">
          <UCard :ui="recordCardUi">
            <template #header>
              <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-highlighted">
                  Informações básicas
                </h2>
                <UButton
                  v-if="canManageClients"
                  label="Editar"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  @click="cadastroOpen = true"
                />
              </div>
            </template>
            <DataTableMetaList :items="cadastroFacts" />
            <p v-if="isCompany" class="text-sm text-default">
              <span class="mb-0.5 block text-xs text-muted">Atividade principal</span>
              {{ primaryActivityLabel ?? '—' }}
            </p>
            <p v-else class="text-sm text-muted">
              Pessoa física com regime não aplicável. O endereço fica na aba Endereço.
            </p>
            <template v-if="isCompany" #footer>
              <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-muted">
                  {{ syncedText }}
                </p>
                <UButton
                  v-if="canManageClients"
                  label="Conferir na Receita"
                  icon="i-lucide-refresh-cw"
                  color="neutral"
                  variant="outline"
                  size="sm"
                  class="w-fit"
                  @click="cadastroOpen = true"
                />
              </div>
            </template>
          </UCard>
        </div>

        <div v-else-if="tab === 'contato'" class="flex flex-col gap-3">
          <UCard :ui="recordCardUi">
            <template #header>
              <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-highlighted">
                  Contato do cadastro
                </h2>
                <UButton
                  v-if="canManageClients"
                  label="Editar contato"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  @click="contactOpen = true"
                />
              </div>
            </template>
            <DataTableMetaList :items="clientContactFacts" layout="stack" />
          </UCard>

          <UCard :ui="recordCardUi">
            <template #header>
              <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-highlighted">
                  Contatos de envio
                </h2>
                <UButton
                  v-if="canManageClients && dispatchContacts.length > 0"
                  label="Adicionar"
                  icon="i-lucide-plus"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  @click="openDispatchContactForm(null)"
                />
              </div>
            </template>
            <p class="text-xs text-muted">
              Destinos do Enviar guias. Apenas as linhas de WhatsApp recebem guias.
            </p>

            <USkeleton v-if="dispatchContactsLoading && dispatchContacts.length === 0" class="h-16 w-full" />

            <UAlert
              v-else-if="dispatchContactsFailed"
              color="error"
              variant="subtle"
              icon="i-lucide-circle-alert"
              title="Não foi possível carregar os contatos"
              description="A lista de envio não respondeu. Tente de novo sem sair da ficha."
              :actions="[{ label: 'Tentar novamente', color: 'error', variant: 'solid', onClick: () => loadDispatchContacts() }]"
            />

            <UEmpty
              v-else-if="dispatchContacts.length === 0"
              variant="naked"
              size="sm"
              icon="i-lucide-phone"
              title="Nenhum Contato de envio"
              description="Cadastre um destino. O envio de guias usa as linhas de WhatsApp."
              :actions="contactEmptyActions"
              :ui="{ root: 'px-2 py-4 sm:px-3' }"
            />

            <ul v-else class="flex flex-col divide-y divide-default">
              <li
                v-for="item in dispatchContacts"
                :key="item.id"
                class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0"
              >
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm tabular-nums text-default">
                    {{ whatsappDispatchContactValueLabel(item) }}
                  </p>
                  <p v-if="contactCaption(item)" class="truncate text-xs text-muted">
                    {{ contactCaption(item) }}
                  </p>
                </div>
                <UBadge
                  :color="item.channel === 'whatsapp' ? 'success' : 'neutral'"
                  variant="subtle"
                  size="sm"
                  :icon="item.channel === 'whatsapp' ? 'i-lucide-message-circle' : 'i-lucide-mail'"
                  :label="item.channel === 'whatsapp' ? 'WhatsApp' : 'E-mail'"
                />
                <template v-if="canManageClients">
                  <UButton
                    icon="i-lucide-pencil"
                    color="neutral"
                    variant="ghost"
                    size="xs"
                    :aria-label="`Editar ${whatsappDispatchContactName(item)}`"
                    @click="openDispatchContactForm(item)"
                  />
                  <UButton
                    icon="i-lucide-trash-2"
                    color="error"
                    variant="ghost"
                    size="xs"
                    :loading="dispatchContactDeleting?.id === item.id"
                    :aria-label="`Remover ${whatsappDispatchContactName(item)}`"
                    @click="dispatchContactDeleting = item"
                  />
                </template>
              </li>
            </ul>
          </UCard>
        </div>

        <div v-else-if="tab === 'endereco'" class="flex flex-col gap-3">
          <UCard :ui="recordCardUi">
            <template #header>
              <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-highlighted">
                  Endereço
                </h2>
                <UButton
                  v-if="mapsUrl"
                  label="Ver no mapa"
                  icon="i-lucide-arrow-up-right"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  :to="mapsUrl"
                  target="_blank"
                />
              </div>
            </template>
            <template v-if="hasAddress">
              <p class="text-sm text-highlighted">
                {{ addressLine1 ?? '—' }}
              </p>
              <DataTableMetaList
                :items="addressFacts"
                columns="grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-3"
              />
              <p v-if="isCompany" class="text-xs text-muted">
                Endereço de empresa vem da Receita Federal. Para corrigir, use Conferir na Receita no cadastro.
              </p>
              <UButton
                v-if="canManageClients && !isCompany"
                label="Editar endereço"
                color="neutral"
                variant="outline"
                size="sm"
                class="w-fit"
                @click="contactOpen = true"
              />
            </template>
            <UEmpty
              v-else
              variant="naked"
              size="sm"
              icon="i-lucide-map-pin"
              title="Nenhum endereço cadastrado"
              :description="isCompany ? 'Empresa corrige o endereço em Conferir na Receita, na aba Cadastro.' : 'Pessoa física pode informar o endereço aqui.'"
              :actions="addressEmptyActions"
              :ui="{ root: 'px-2 py-4 sm:px-3' }"
            />
          </UCard>
        </div>

        <div v-else class="grid items-start gap-3 lg:grid-cols-[minmax(0,1fr)_18rem]">
          <UCard :ui="recordCardUi">
            <template #header>
              <h2 class="text-sm font-semibold text-highlighted">
                Linha do tempo
              </h2>
            </template>
            <UTimeline
              v-if="historyItems.length"
              :items="historyItems"
              color="neutral"
              size="sm"
              :ui="{ description: 'text-muted text-sm text-pretty break-words' }"
            />
            <UEmpty
              v-else
              variant="naked"
              size="sm"
              icon="i-lucide-history"
              title="Nenhum evento"
              description="Abertura, certificado e consultas à Receita aparecem aqui."
              :ui="{ root: 'px-2 py-4 sm:px-3' }"
            />
          </UCard>

          <div class="flex min-w-0 flex-col gap-3">
            <UCard :ui="recordCardUi">
              <template #header>
                <div class="flex items-center justify-between gap-2">
                  <h2 class="text-sm font-semibold text-highlighted">
                    Ficha
                  </h2>
                  <UButton
                    icon="i-lucide-copy"
                    label="Copiar"
                    color="neutral"
                    variant="ghost"
                    size="xs"
                    aria-label="Copiar resumo da ficha"
                    @click="onCopySummary"
                  />
                </div>
              </template>
              <DataTableMetaList :items="systemFacts" layout="stack" />
              <p class="text-xs text-muted">
                {{ syncedText }}
              </p>
              <USeparator />
              <div class="flex items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-highlighted">
                  Tags
                </h3>
                <UButton
                  v-if="canManageClients"
                  label="Gerenciar"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  @click="tagsOpen = true"
                />
              </div>
              <CustomersClientTags v-if="client.tags?.length" :tags="client.tags" />
              <p v-else class="text-sm text-muted">
                Sem tags.
              </p>
            </UCard>

            <UAlert
              v-if="canManageClients"
              color="error"
              variant="subtle"
              icon="i-lucide-trash-2"
              title="Excluir cliente"
              description="A exclusão tira o cliente da carteira e elimina o certificado A1 ativo."
              :actions="[{ label: 'Excluir cliente', icon: 'i-lucide-trash-2', color: 'error', variant: 'outline', onClick: () => { deleteOpen = true } }]"
            />
          </div>
        </div>
      </template>
    </div>

    <CustomersClientCadastroModal
      :key="`cadastro-${readAuthority}`"
      v-model:open="cadastroOpen"
      :client="client"
      @saved="onSaved"
    />
    <CustomersClientContactModal
      :key="`contact-${readAuthority}`"
      v-model:open="contactOpen"
      :client="client"
      @saved="onSaved"
    />
    <CustomersCertificateModal
      :key="`certificate-${readAuthority}`"
      v-model:open="certificateOpen"
      :client="client"
      @saved="onSaved"
    />
    <CustomersTagsModal
      v-if="canManageClients"
      :key="`tags-${readAuthority}`"
      v-model:open="tagsOpen"
      :count="1"
      :assignment="tagAssignment"
      @applied="reload"
      @changed="reload"
    />
    <CustomersClientDeleteModal
      :key="`delete-${readAuthority}`"
      v-model:open="deleteOpen"
      :client="client ? { id: client.id, name: client.name } : null"
      @deleted="onDeleted"
    />
    <DispatchContactFormModal
      v-if="client"
      :key="`dispatch-${readAuthority}`"
      v-model:open="dispatchContactFormOpen"
      :client-id="client.id"
      :client-name="client.name"
      :contact="dispatchContactEditing"
      @saved="loadDispatchContacts()"
    />

    <UModal
      :open="dispatchContactDeleting !== null"
      title="Remover Contato de envio"
      :description="dispatchContactDeleting ? `Remover ${whatsappDispatchContactName(dispatchContactDeleting)} (${whatsappDispatchContactValueLabel(dispatchContactDeleting)})? Este destino deixa de receber guias.` : ''"
      @update:open="dispatchContactDeleting = null"
    >
      <template #footer>
        <div class="flex w-full items-center justify-end gap-1.5">
          <UButton
            label="Cancelar"
            color="neutral"
            variant="outline"
            @click="dispatchContactDeleting = null"
          />
          <UButton
            label="Remover"
            color="error"
            @click="dispatchContactDeleting && removeDispatchContact(dispatchContactDeleting)"
          />
        </div>
      </template>
    </UModal>
  </div>
</template>
