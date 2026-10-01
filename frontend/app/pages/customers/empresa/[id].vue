<script setup lang="ts">
import type { DropdownMenuItem, TabsItem } from '@nuxt/ui'
import type { Client, DeadlineStatus } from '~/types/client'
import {
  clientStatusLabel,
  deadlinePresentation,
  taxRegimeLabel
} from '~/utils/portfolioLabels'
import { formatDate } from '~/utils'
import { formatTaxId } from '~/utils/taxId'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const toast = useToast()
const { canManageClients } = useAuth()
const { show, update } = useClients()
const detailTitle = useState<string | null>('customers-detail-title', () => null)

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
const client = shallowRef<Client | null>(null)
const loading = ref(true)
const togglingStatus = ref(false)
const tab = ref<'fiscal' | 'cadastro' | 'contato' | 'historico'>('fiscal')

const tabItems: TabsItem[] = [
  { label: 'Fiscal', value: 'fiscal', icon: 'i-lucide-badge-check' },
  { label: 'Cadastro', value: 'cadastro', icon: 'i-lucide-building-2' },
  { label: 'Contato e endereço', value: 'contato', icon: 'i-lucide-mail' },
  { label: 'Histórico', value: 'historico', icon: 'i-lucide-history' }
]

const cadastroOpen = ref(false)
const contactOpen = ref(false)
const certificateOpen = ref(false)
const tagsOpen = ref(false)
const deleteOpen = ref(false)

async function loadById(id: number, initial = false) {
  if (!initial) loading.value = true
  try {
    const data = await show(id)
    client.value = data
    detailTitle.value = data.name
    activeId.value = id
  } catch {
    if (initial) throw createError({ statusCode: 404, statusMessage: 'Cliente não encontrado' })
    toast.add({ title: 'Não foi possível carregar o cliente', color: 'error' })
  } finally {
    loading.value = false
  }
}

await loadById(initialId, true)

watch(() => route.params.id, (param) => {
  const id = parseId(param)
  if (id === null || id === activeId.value) return
  tab.value = 'fiscal'
  void loadById(id)
})

function onSaved(saved: Client) {
  client.value = saved
  detailTitle.value = saved.name
}

async function reload() {
  await loadById(activeId.value)
}

onBeforeUnmount(() => {
  detailTitle.value = null
})

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
  return years === 1 ? '1 ano de abertura' : `${years} anos de abertura`
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

const emailHref = computed(() => client.value?.email ? `mailto:${client.value.email}` : null)
const phoneDigits = computed(() => (client.value?.phone ?? '').replace(/\D/g, ''))
const phoneHref = computed(() => phoneDigits.value ? `tel:+55${phoneDigits.value}` : null)
const phoneLabel = computed(() => {
  const digits = phoneDigits.value
  if (digits.length === 11) return digits.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3')
  if (digits.length === 10) return digits.replace(/(\d{2})(\d{4})(\d{4})/, '($1) $2-$3')
  return client.value?.phone ?? '—'
})

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

function progressBetween(start: string | null | undefined, end: string | null | undefined): number {
  if (!start || !end) return 0
  const from = new Date(start).getTime()
  const to = new Date(end).getTime()
  if (Number.isNaN(from) || Number.isNaN(to) || to <= from) return 0
  const pct = ((Date.now() - from) / (to - from)) * 100
  return Math.min(100, Math.max(0, Math.round(pct)))
}

const barClass: Record<DeadlineStatus, string> = {
  missing: 'bg-neutral',
  valid: 'bg-success',
  expiring: 'bg-warning',
  expired: 'bg-error'
}

const certDays = computed(() => daysUntil(client.value?.certificate?.valid_until))
const poaDays = computed(() => daysUntil(client.value?.ecac_power_of_attorney?.expires_on))
const certHint = computed(() => deadlineHint(certDays.value))
const poaHint = computed(() => deadlineHint(poaDays.value))
const certPct = computed(() => progressBetween(client.value?.certificate?.valid_from, client.value?.certificate?.valid_until))

const certManageLabel = computed(() => {
  if (certStatus.value === 'missing') return 'Cadastrar certificado'
  if (certStatus.value === 'expired') return 'Renovar certificado'
  return 'Gerenciar certificado'
})

/**
 * As famílias que compõem a procuração derivada, já com o estado de cada uma.
 * `family` é o código do provedor; o rótulo é o que `SerproPowerNames`
 * conhece, e o código fica visível quando não há nome — o detalhe não inventa
 * um nome para uma família que o provedor ainda não nomeou.
 */
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

const poaFamilies = computed(() => client.value?.ecac_power_of_attorney?.families ?? [])

function poaFamilyLabel(family: { family: string, state: string }): { label: string, color: 'success' | 'error' | 'warning' | 'neutral' } {
  return {
    label: poaFamilyStateLabel[family.state] ?? family.state,
    color: poaFamilyStateColor[family.state] ?? 'neutral'
  }
}

function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(date)
}

const syncedText = computed(() => {
  if (client.value?.source_updated_at) return `Receita atualizada em ${formatDate(client.value.source_updated_at)}`
  if (client.value?.looked_up_at) return `Consultado em ${formatDateTime(client.value.looked_up_at)}`
  return 'Nunca sincronizado com a Receita'
})

interface TimelineItem {
  key: string
  title: string
  date: string
  detail: string | null
  icon: string
  dot: string
}

const timeline = computed<TimelineItem[]>(() => {
  const c = client.value
  if (!c) return []
  const items: TimelineItem[] = []
  if (c.opened_at) items.push({ key: 'opened', title: 'Abertura da empresa', date: c.opened_at, detail: c.primary_activity?.description ?? null, icon: 'i-lucide-building-2', dot: 'bg-neutral' })
  if (c.certificate?.valid_from) items.push({ key: 'cert-from', title: 'Certificado A1 emitido', date: c.certificate.valid_from, detail: c.certificate.subject, icon: 'i-lucide-key-round', dot: 'bg-success' })
  if (c.certificate?.valid_until) items.push({ key: 'cert-until', title: certStatus.value === 'expired' ? 'Certificado A1 venceu' : 'Certificado A1 vence', date: c.certificate.valid_until, detail: certHint.value || null, icon: 'i-lucide-key-round', dot: barClass[certStatus.value] })
  if (c.ecac_power_of_attorney?.expires_on) items.push({ key: 'poa-until', title: poaStatus.value === 'expired' ? 'Procuração e-CAC venceu' : 'Procuração e-CAC vence', date: c.ecac_power_of_attorney.expires_on, detail: poaHint.value || null, icon: 'i-lucide-file-key-2', dot: barClass[poaStatus.value] })
  if (c.looked_up_at) items.push({ key: 'lookup', title: 'Última consulta à Receita', date: c.looked_up_at, detail: null, icon: 'i-lucide-refresh-cw', dot: 'bg-neutral' })
  items.push({ key: 'created', title: 'Cliente entrou para a carteira', date: c.created_at, detail: null, icon: 'i-lucide-plus', dot: 'bg-neutral' })
  return items
    .filter(item => !Number.isNaN(new Date(item.date).getTime()))
    .sort((a, b) => new Date(b.date).getTime() - new Date(a.date).getTime())
    .slice(0, 7)
})

const summaryText = computed(() => {
  const c = client.value
  if (!c) return ''
  const lines = [
    `${c.name}${tradeName.value ? ` (${tradeName.value})` : ''}`,
    `${documentKind.value}: ${documentLabel.value}`,
    `Situação: ${clientStatusLabel[c.status]} · Regime: ${regimeLabel.value}`,
    `Receita: ${c.registration_status ?? '—'} · Abertura: ${c.opened_at ? formatDate(c.opened_at) : '—'}`,
    `Endereço: ${hasAddress.value ? [addressLine1.value, c.address?.district, cityLabel.value, addressCep.value ? `CEP ${addressCep.value}` : null].filter(Boolean).join(', ') : '—'}`,
    `Email: ${c.email ?? '—'} · Telefone: ${phoneDigits.value ? phoneLabel.value : '—'}`,
    `Certificado A1: ${c.certificate ? `válido até ${formatDate(c.certificate.valid_until)} (${certHint.value})` : 'não cadastrado'}`,
    `Procuração e-CAC: ${c.ecac_power_of_attorney?.expires_on ? `válida até ${formatDate(c.ecac_power_of_attorney.expires_on)} (${poaHint.value})` : deadlinePresentation[poaStatus.value].label}`
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
  togglingStatus.value = true
  try {
    const saved = await update(client.value.id, { status: client.value.status === 'active' ? 'inactive' : 'active' })
    onSaved(saved)
    toast.add({ title: saved.status === 'active' ? 'Cliente ativado' : 'Cliente inativado', color: 'success' })
  } catch {
    toast.add({ title: 'Não foi possível alterar a situação', color: 'error' })
  } finally {
    togglingStatus.value = false
  }
}

function onDeleted() {
  detailTitle.value = null
  navigateTo('/customers/certificados')
}

const tagAssignment = computed(() => ({ ids: client.value ? [client.value.id] : [] }))

function onCopySummary() {
  void copyText(summaryText.value, 'Resumo')
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
  <div class="h-full overflow-y-auto">
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-3 p-3 sm:p-4">
      <template v-if="loading">
        <USkeleton class="h-28 w-full rounded-xl" />
        <USkeleton class="h-10 w-full rounded-xl" />
        <div class="grid gap-3 sm:grid-cols-2">
          <USkeleton class="h-52 w-full rounded-xl" />
          <USkeleton class="h-52 w-full rounded-xl" />
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
        <UCard :ui="{ body: 'p-3 sm:p-4' }">
          <div class="flex items-start gap-3">
            <span
              aria-hidden="true"
              class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-base font-bold tracking-tight text-primary ring-1 ring-inset ring-primary/20"
            >
              {{ initials }}
            </span>
            <div class="min-w-0 flex-1">
              <h1 class="truncate text-lg font-semibold tracking-tight text-highlighted">
                {{ client.name }}
              </h1>
              <p v-if="tradeName" class="truncate text-xs text-muted">
                {{ tradeName }}
              </p>
              <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                <p class="text-xs tabular-nums text-muted">
                  {{ documentKind }} {{ documentLabel }}
                </p>
                <UButton
                  icon="i-lucide-copy"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  :aria-label="`Copiar ${documentKind}`"
                  @click="copyText(client.tax_id ?? '', documentKind)"
                />
              </div>
              <div class="mt-2 flex flex-wrap items-center gap-1.5">
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
                  color="info"
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
              <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                <span v-if="cityLabel" class="inline-flex items-center gap-1">
                  <UIcon name="i-lucide-map-pin" class="size-3.5 shrink-0" />
                  {{ cityLabel }}
                </span>
                <span v-if="openedAge" class="inline-flex items-center gap-1">
                  <UIcon name="i-lucide-cake" class="size-3.5 shrink-0" />
                  {{ openedAge }}
                </span>
                <span class="inline-flex items-center gap-1">
                  <UIcon name="i-lucide-refresh-cw" class="size-3.5 shrink-0" />
                  {{ syncedText }}
                </span>
              </div>
              <div v-if="client.tags?.length" class="mt-2">
                <CustomersClientTags :tags="client.tags" />
              </div>
            </div>
            <div class="flex shrink-0 items-center gap-1.5">
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
                  :aria-label="`Mais ações para ${client.name}`"
                />
              </UDropdownMenu>
            </div>
          </div>
        </UCard>

        <UTabs
          v-model="tab"
          :items="tabItems"
          variant="link"
          :content="false"
          class="w-full"
        />

        <div v-if="tab === 'fiscal'" class="flex flex-col gap-3">
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
            <UCard :ui="{ body: 'flex min-h-0 flex-1 flex-col gap-2.5 p-3 sm:p-4' }">
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-key-round" class="size-4 shrink-0 text-muted" />
                <h2 class="min-w-0 flex-1 truncate text-sm font-medium text-default">
                  Certificado A1
                </h2>
                <UBadge
                  :color="deadlinePresentation[certStatus].color"
                  variant="subtle"
                  :icon="deadlinePresentation[certStatus].icon"
                  :label="deadlinePresentation[certStatus].label"
                />
              </div>

              <template v-if="client.certificate">
                <p class="text-sm tabular-nums text-highlighted">
                  Válido até {{ formatDate(client.certificate.valid_until) }}
                </p>
                <p class="text-xs text-muted">
                  {{ certHint }}
                </p>
                <div
                  class="h-1.5 overflow-hidden rounded-full bg-muted"
                  role="progressbar"
                  :aria-valuenow="certPct"
                  aria-valuemin="0"
                  aria-valuemax="100"
                  aria-label="Vigência do certificado"
                >
                  <div class="h-full rounded-full" :class="barClass[certStatus]" :style="{ width: `${certPct}%` }" />
                </div>
                <dl class="space-y-1.5 text-xs">
                  <div class="flex justify-between gap-3">
                    <dt class="shrink-0 text-muted">
                      Titular
                    </dt>
                    <dd class="min-w-0 truncate text-right text-default">
                      {{ client.certificate.subject }}
                    </dd>
                  </div>
                  <div class="flex justify-between gap-3">
                    <dt class="shrink-0 text-muted">
                      Emitido em
                    </dt>
                    <dd class="tabular-nums text-default">
                      {{ formatDate(client.certificate.valid_from) }}
                    </dd>
                  </div>
                  <div class="flex justify-between gap-3">
                    <dt class="shrink-0 text-muted">
                      Série
                    </dt>
                    <dd class="min-w-0 truncate font-mono text-[11px] text-default">
                      {{ client.certificate.serial_number }}
                    </dd>
                  </div>
                  <div class="flex justify-between gap-3">
                    <dt class="shrink-0 text-muted">
                      Arquivo
                    </dt>
                    <dd class="min-w-0 truncate text-right text-default">
                      {{ client.certificate.original_filename }}
                    </dd>
                  </div>
                </dl>
              </template>
              <p v-else class="text-sm text-muted">
                Nenhum certificado cadastrado. Sem ele, as rotinas com a Receita ficam paradas.
              </p>

              <UButton
                v-if="canManageClients"
                :label="certManageLabel"
                icon="i-lucide-key-round"
                color="neutral"
                variant="outline"
                size="sm"
                block
                class="mt-auto"
                @click="certificateOpen = true"
              />
            </UCard>

            <UCard :ui="{ body: 'flex min-h-0 flex-1 flex-col gap-2.5 p-3 sm:p-4' }">
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-file-key-2" class="size-4 shrink-0 text-muted" />
                <h2 class="min-w-0 flex-1 truncate text-sm font-medium text-default">
                  Procuração e-CAC
                </h2>
                <UBadge
                  :color="deadlinePresentation[poaStatus].color"
                  variant="subtle"
                  :icon="deadlinePresentation[poaStatus].icon"
                  :label="deadlinePresentation[poaStatus].label"
                />
              </div>

              <template v-if="client.ecac_power_of_attorney?.expires_on">
                <p class="text-sm tabular-nums text-highlighted">
                  Vigente até {{ formatDate(client.ecac_power_of_attorney.expires_on) }}
                </p>
                <p class="text-xs text-muted">
                  {{ poaHint }}
                </p>
              </template>
              <p v-else class="text-sm text-muted">
                O provedor ainda não confirmou uma outorga para este cliente. A procuração é concedida no e-CAC e lida aqui — não há cadastro manual.
              </p>

              <div v-if="poaFamilies.length > 0" class="space-y-1.5">
                <p class="text-xs font-medium text-muted">
                  Famílias de serviço
                </p>
                <ul class="space-y-1">
                  <li
                    v-for="family in poaFamilies"
                    :key="family.family"
                    class="flex items-center justify-between gap-3 text-xs"
                  >
                    <span class="min-w-0 truncate font-mono text-[11px] text-default" :title="`Família ${family.family}`">
                      {{ family.family }}
                    </span>
                    <UBadge
                      :label="poaFamilyLabel(family).label"
                      :color="poaFamilyLabel(family).color"
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
          <UCard :ui="{ body: 'p-3 sm:p-4' }">
            <div class="mb-3 flex items-center justify-between gap-2">
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
            <dl class="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2 xl:grid-cols-3">
              <div>
                <dt class="text-xs text-muted">
                  Tipo de pessoa
                </dt>
                <dd class="mt-0.5 text-sm text-default">
                  {{ personTypeLabel }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-muted">
                  {{ documentKind }}
                </dt>
                <dd class="mt-0.5 text-sm tabular-nums text-default">
                  {{ documentLabel }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-muted">
                  Situação na carteira
                </dt>
                <dd class="mt-0.5">
                  <UBadge
                    :color="statusColor"
                    variant="subtle"
                    size="sm"
                    :label="clientStatusLabel[client.status]"
                  />
                </dd>
              </div>
              <div class="sm:col-span-2 xl:col-span-1">
                <dt class="text-xs text-muted">
                  {{ isCompany ? 'Razão social' : 'Nome completo' }}
                </dt>
                <dd class="mt-0.5 text-sm font-medium text-highlighted">
                  {{ client.name }}
                </dd>
              </div>
              <div v-if="isCompany">
                <dt class="text-xs text-muted">
                  Nome fantasia
                </dt>
                <dd class="mt-0.5 text-sm text-default">
                  {{ tradeName ?? '—' }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-muted">
                  Regime tributário
                </dt>
                <dd class="mt-0.5 text-sm text-default">
                  {{ regimeLabel }}
                </dd>
              </div>
              <div v-if="isCompany">
                <dt class="text-xs text-muted">
                  Situação na Receita
                </dt>
                <dd class="mt-0.5 text-sm text-default">
                  {{ client.registration_status ?? '—' }}
                  <span v-if="client.registration_status_date" class="text-muted">
                    desde {{ formatDate(client.registration_status_date) }}
                  </span>
                </dd>
              </div>
              <div v-if="client.opened_at">
                <dt class="text-xs text-muted">
                  Abertura
                </dt>
                <dd class="mt-0.5 text-sm text-default">
                  {{ formatDate(client.opened_at) }}
                  <span v-if="openedAge" class="text-muted">({{ openedAge }})</span>
                </dd>
              </div>
              <div v-if="isCompany">
                <dt class="text-xs text-muted">
                  Porte
                </dt>
                <dd class="mt-0.5 text-sm text-default">
                  {{ client.company_size ?? '—' }}
                </dd>
              </div>
              <div v-if="isCompany" class="sm:col-span-2">
                <dt class="text-xs text-muted">
                  Natureza jurídica
                </dt>
                <dd class="mt-0.5 text-sm text-default">
                  {{ client.legal_nature ?? '—' }}
                </dd>
              </div>
              <div v-if="isCompany" class="sm:col-span-2 xl:col-span-3">
                <dt class="text-xs text-muted">
                  Atividade principal
                </dt>
                <dd class="mt-0.5 text-sm text-default">
                  <span v-if="client.primary_activity?.code" class="tabular-nums text-muted">
                    {{ client.primary_activity.code }} —
                  </span>
                  {{ client.primary_activity?.description ?? '—' }}
                </dd>
              </div>
              <div v-else class="sm:col-span-2 xl:col-span-3">
                <dt class="text-xs text-muted">
                  Observação
                </dt>
                <dd class="mt-0.5 text-sm text-muted">
                  Pessoa física com regime não aplicável. O endereço fica em Contato e endereço.
                </dd>
              </div>
            </dl>
            <template v-if="isCompany">
              <USeparator class="my-3" />
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

        <div v-else-if="tab === 'contato'" class="grid items-start gap-3 md:grid-cols-2">
          <UCard :ui="{ body: 'flex flex-col gap-3 p-3 sm:p-4' }">
            <div class="flex items-center justify-between gap-2">
              <h2 class="text-sm font-semibold text-highlighted">
                Contato
              </h2>
              <UButton
                v-if="canManageClients"
                label="Editar"
                color="neutral"
                variant="ghost"
                size="sm"
                @click="contactOpen = true"
              />
            </div>
            <div class="flex items-center gap-2.5">
              <UIcon name="i-lucide-mail" class="size-4 shrink-0 text-muted" />
              <div class="min-w-0 flex-1">
                <p class="text-xs text-muted">
                  Email
                </p>
                <p class="truncate text-sm text-default">
                  {{ client.email ?? '—' }}
                </p>
              </div>
              <UButton
                v-if="client.email"
                icon="i-lucide-copy"
                color="neutral"
                variant="ghost"
                size="xs"
                aria-label="Copiar email"
                @click="copyText(client.email, 'Email')"
              />
              <UButton
                v-if="emailHref"
                icon="i-lucide-arrow-up-right"
                color="neutral"
                variant="ghost"
                size="xs"
                aria-label="Escrever email"
                :to="emailHref"
                target="_blank"
              />
            </div>
            <USeparator />
            <div class="flex items-center gap-2.5">
              <UIcon name="i-lucide-phone" class="size-4 shrink-0 text-muted" />
              <div class="min-w-0 flex-1">
                <p class="text-xs text-muted">
                  Telefone
                </p>
                <p class="text-sm tabular-nums text-default">
                  {{ phoneDigits ? phoneLabel : '—' }}
                </p>
              </div>
              <UButton
                v-if="phoneDigits"
                icon="i-lucide-copy"
                color="neutral"
                variant="ghost"
                size="xs"
                aria-label="Copiar telefone"
                @click="copyText(client.phone ?? '', 'Telefone')"
              />
              <UButton
                v-if="phoneHref"
                icon="i-lucide-arrow-up-right"
                color="neutral"
                variant="ghost"
                size="xs"
                aria-label="Ligar"
                :to="phoneHref"
              />
            </div>
          </UCard>

          <UCard :ui="{ body: 'flex flex-col gap-2.5 p-3 sm:p-4' }">
            <div class="flex items-center justify-between gap-2">
              <h2 class="inline-flex items-center gap-1.5 text-sm font-semibold text-highlighted">
                <UIcon name="i-lucide-map-pin" class="size-4 text-muted" />
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
            <template v-if="hasAddress">
              <p class="text-sm text-default">
                {{ addressLine1 ?? '—' }}
              </p>
              <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
                <div>
                  <dt class="text-xs text-muted">
                    Bairro
                  </dt>
                  <dd class="text-default">
                    {{ client.address?.district ?? '—' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-muted">
                    Cidade/UF
                  </dt>
                  <dd class="text-default">
                    {{ cityLabel ?? '—' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-muted">
                    CEP
                  </dt>
                  <dd class="tabular-nums text-default">
                    {{ addressCep ?? '—' }}
                  </dd>
                </div>
              </dl>
              <p v-if="isCompany" class="text-xs text-muted">
                Endereço de empresa vem da Receita Federal. Para corrigir, use “Conferir na Receita” no cadastro.
              </p>
              <UButton
                v-if="canManageClients"
                label="Editar contato e endereço"
                color="neutral"
                variant="outline"
                size="sm"
                block
                @click="contactOpen = true"
              />
            </template>
            <p v-else class="text-sm text-muted">
              Nenhum endereço cadastrado.
            </p>
          </UCard>
        </div>

        <div v-else class="grid items-start gap-3 lg:grid-cols-[minmax(0,1fr)_300px]">
          <UCard :ui="{ header: 'px-3 py-2.5 sm:px-4', body: 'flex flex-col gap-2 p-3 text-sm sm:p-4' }">
            <template #header>
              <h2 class="text-sm font-semibold text-highlighted">
                Linha do tempo
              </h2>
            </template>
            <ol class="relative space-y-4 before:absolute before:top-2 before:bottom-2 before:left-[5px] before:w-px before:bg-default">
              <li v-for="item in timeline" :key="item.key" class="relative flex gap-3 pl-0">
                <span class="relative z-10 mt-1 size-3 shrink-0 rounded-full ring-4 ring-default" :class="item.dot" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                  <p class="flex items-center gap-1.5 text-sm font-medium text-default">
                    <UIcon :name="item.icon" class="size-3.5 shrink-0 text-muted" />
                    <span class="truncate">{{ item.title }}</span>
                  </p>
                  <p class="mt-0.5 text-xs tabular-nums text-muted">
                    {{ formatDate(item.date) }}
                    <span v-if="item.detail"> · {{ item.detail }}</span>
                  </p>
                </div>
              </li>
            </ol>
          </UCard>

          <div class="flex min-w-0 flex-col gap-3">
            <UCard :ui="{ body: 'flex flex-col gap-2 p-3 sm:p-4' }">
              <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-highlighted">
                  Tags
                </h2>
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

            <UCard :ui="{ body: 'flex flex-col gap-2 p-3 sm:p-4' }">
              <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-highlighted">
                  Resumo da ficha
                </h2>
                <UButton
                  icon="i-lucide-copy"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  aria-label="Copiar resumo da ficha"
                  @click="onCopySummary"
                />
              </div>
              <p class="text-xs whitespace-pre-line text-muted">
                {{ summaryText }}
              </p>
            </UCard>

            <UCard :ui="{ body: 'flex flex-col gap-1.5 p-3 text-sm sm:p-4' }">
              <h2 class="text-sm font-semibold text-highlighted">
                Ficha do sistema
              </h2>
              <div class="flex justify-between gap-3">
                <span class="text-muted">Código</span>
                <span class="tabular-nums text-default">#{{ client.id }}</span>
              </div>
              <div class="flex justify-between gap-3">
                <span class="text-muted">Na carteira desde</span>
                <span class="tabular-nums text-default">{{ formatDate(client.created_at) }}</span>
              </div>
              <div class="flex justify-between gap-3">
                <span class="text-muted">Última edição</span>
                <span class="tabular-nums text-default">{{ formatDate(client.updated_at) }}</span>
              </div>
              <USeparator />
              <p class="text-xs text-muted">
                {{ syncedText }}
              </p>
            </UCard>

            <UCard
              v-if="canManageClients"
              :ui="{ body: 'flex flex-col gap-2 p-3 sm:p-4' }"
            >
              <h2 class="text-sm font-semibold text-highlighted">
                Zona de risco
              </h2>
              <p class="text-xs text-muted">
                A exclusão tira o cliente da carteira e elimina o certificado A1 ativo.
              </p>
              <UButton
                label="Excluir cliente"
                icon="i-lucide-trash-2"
                color="error"
                variant="outline"
                size="sm"
                block
                @click="deleteOpen = true"
              />
            </UCard>
          </div>
        </div>
      </template>
    </div>

    <CustomersClientCadastroModal
      v-model:open="cadastroOpen"
      :client="client"
      @saved="onSaved"
    />
    <CustomersClientContactModal
      v-model:open="contactOpen"
      :client="client"
      @saved="onSaved"
    />
    <CustomersCertificateModal
      v-model:open="certificateOpen"
      :client="client"
      @saved="onSaved"
    />
    <CustomersTagsModal
      v-if="canManageClients"
      v-model:open="tagsOpen"
      :count="1"
      :assignment="tagAssignment"
      @applied="reload"
      @changed="reload"
    />
    <CustomersClientDeleteModal
      v-model:open="deleteOpen"
      :client="client ? { id: client.id, name: client.name } : null"
      @deleted="onDeleted"
    />
  </div>
</template>
