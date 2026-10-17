<script setup lang="ts">
import type { MetaListItem } from '~/components/data-table/MetaList.vue'
import type { MonitoringMessage, MonitoringMessageRecovery, MonitoringMessageStub } from '~/types/serpro'
import type { MonitoringObligation } from '~/utils/monitoringNav'
import { formatMonitoringDate, monitoringDeadlinePassed } from '~/utils/monitoringPresentation'

defineOptions({ inheritAttrs: false })

const props = defineProps<{
  open: boolean
  obligation: MonitoringObligation
  clientName: string
  /** The provider knows a message by `isn` only inside one contributor's mailbox. */
  clientId: number
  /**
   * What the list already knows about this message: the subject and the three
   * timestamps, not the body. A stub is enough to name what is about to happen.
   */
  stub: MonitoringMessageStub
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'read': [id: number]
}>()

const isOpen = computed({
  get: () => props.open,
  set: value => emit('update:open', value)
})

const { readMessage, reconcileMessage } = useSerpro()
/** Registering ciência is an act on the client's behalf: `admin` and `operador` only. */
const { canManageClients, currentAccount } = useAuth()

const confirmed = ref(false)
const message = ref<MonitoringMessage | MonitoringMessageRecovery | null>(null)
const loading = ref(false)
const effectStatus = ref<'idle' | 'before_send' | 'uncertain' | 'confirmed'>('idle')
let generation = 0
const context = () => [props.open, props.obligation.slug, props.clientId, props.stub.id, currentAccount.value?.id] as const

// No provider/recovery call on open. Invalidate both successful and failed late responses.
watch(context, () => {
  generation++
  confirmed.value = false
  message.value = null
  effectStatus.value = 'idle'
  loading.value = false
}, { flush: 'sync' })
onBeforeUnmount(() => {
  generation++
})

/** The office already knows this one: the act is behind the office, not ahead. */
const scienceRecorded = computed(() => !!props.stub.ciencia_em)
/** What the stub says about the deadline — the consent names it before the read. */
const stubDeadlinePassed = computed(() => monitoringDeadlinePassed(props.stub.prazo_limite))
/** What the provider recorded — the detail is read from the message, not the stub. */
const deadlinePassed = computed(() => monitoringDeadlinePassed(message.value?.prazo_limite))

/** What the stub already knows, before the office consents to the read. */
const stubFacts = computed<MetaListItem[]>(() => [
  { label: 'Recebida em', value: formatMonitoringDate(props.stub.received_at), mono: true, truncate: true },
  { label: 'Ciência em', value: formatMonitoringDate(props.stub.ciencia_em), mono: true, truncate: true, when: scienceRecorded.value }
])

/** What the read returned, plus the provider's own code when it sent one. */
const messageFacts = computed<MetaListItem[]>(() => {
  if (!message.value) return []
  return [
    { label: 'Ciência em', value: formatMonitoringDate(message.value.ciencia_em), mono: true, truncate: true },
    { label: 'Prazo', value: formatMonitoringDate(message.value.prazo_limite), mono: true, truncate: true },
    { label: 'Código', value: message.value.body_available ? message.value.codigo ?? '' : '', mono: true, truncate: true, when: message.value.body_available && !!message.value.codigo }
  ]
})

function failureStatus(error: unknown): 'before_send' | 'uncertain' | 'confirmed' {
  const data = (error as { data?: { code?: string, effect_status?: string } })?.data
  if (data?.code === 'operation_confirmed' || data?.effect_status === 'confirmed') return 'confirmed'
  if (data?.code === 'operation_before_send' || data?.effect_status === 'pending') return 'before_send'
  // Network/lease errors are not proof that no legal act happened.
  return 'uncertain'
}

async function perform(recovery: boolean) {
  if (!props.open || !canManageClients.value || loading.value) return
  if (recovery ? effectStatus.value !== 'confirmed' : ['uncertain', 'confirmed'].includes(effectStatus.value)) return
  const active = ++generation
  const target = context()
  const current = () => active === generation && context().every((value, index) => value === target[index])
  confirmed.value = true
  loading.value = true
  try {
    const result = await (recovery ? reconcileMessage : readMessage)(props.obligation.slug, props.clientId, props.stub.id)
    if (!current()) return
    message.value = result
    effectStatus.value = 'confirmed'
    emit('read', result.id)
  } catch (error) {
    if (!current()) return
    effectStatus.value = failureStatus(error)
    confirmed.value = false
  } finally {
    if (current()) loading.value = false
  }
}

async function confirm() {
  await perform(false)
}

async function reconcile() {
  await perform(true)
}
</script>

<template>
  <UModal
    v-bind="$attrs"
    v-model:open="isOpen"
    title="Mensagem da caixa postal"
    :description="`${obligation.label} · ${clientName}`"
  >
    <template #body>
      <div v-if="!confirmed" class="flex flex-col gap-4">
        <UAlert
          v-if="effectStatus === 'uncertain'"
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Resultado da ciência não confirmado"
          description="A consulta pode ter registrado a ciência. Não repita o envio: o resultado exige verificação antes de qualquer nova ação."
        />
        <UAlert
          v-else-if="effectStatus === 'confirmed'"
          color="warning"
          variant="subtle"
          icon="i-lucide-scale"
          title="Ciência confirmada; dados locais pendentes"
          description="Reconcilie somente as datas confirmadas, sem nova consulta ao provedor. O corpo da mensagem não será recuperado."
        />
        <UAlert
          v-else-if="effectStatus === 'before_send'"
          color="neutral"
          variant="subtle"
          icon="i-lucide-info"
          title="Consulta não enviada"
          description="A operação foi interrompida antes do envio da consulta de ciência. Corrija o impedimento antes de confirmar novamente."
        />
        <UAlert
          v-if="effectStatus === 'idle' || effectStatus === 'before_send'"
          color="warning"
          variant="subtle"
          icon="i-lucide-gavel"
          title="Abrir esta mensagem registra a ciência da intimação"
          description="O Integra Contador avisa: ler uma mensagem caracteriza ciência da intimação, nos termos do art. 23, § 2º, inciso III, do Decreto nº 70.235/1972. Ao abrir a mensagem, a ciência é registrada e o prazo do escritório começa a correr."
        />

        <UAlert
          v-if="scienceRecorded"
          :color="stubDeadlinePassed ? 'error' : 'neutral'"
          variant="subtle"
          :icon="stubDeadlinePassed ? 'i-lucide-triangle-alert' : 'i-lucide-scale'"
          title="A ciência desta mensagem já foi registrada"
          :description="stubDeadlinePassed
            ? `Registrada em ${formatMonitoringDate(stub.ciencia_em)}, com prazo encerrado em ${formatMonitoringDate(stub.prazo_limite)}. Reabrir a mensagem não muda essas datas.`
            : `Registrada em ${formatMonitoringDate(stub.ciencia_em)}, com prazo até ${formatMonitoringDate(stub.prazo_limite)}. Reabrir a mensagem não muda essas datas.`"
        />

        <UAlert
          v-if="!canManageClients"
          color="neutral"
          variant="subtle"
          icon="i-lucide-lock"
          title="Só administradores e operadores abrem mensagens"
          description="Abrir a mensagem registra a ciência em nome do cliente. Peça a um administrador ou operador do escritório."
        />

        <p class="text-sm font-medium text-highlighted">
          {{ stub.assunto }}
        </p>

        <DataTableMetaList :items="stubFacts" columns="grid-cols-2 gap-x-3 gap-y-2" />
      </div>

      <USkeleton v-else-if="loading" class="h-40 w-full" />

      <div v-else-if="message" class="flex flex-col gap-4">
        <UAlert
          v-if="deadlinePassed"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="O prazo desta mensagem está vencido"
          :description="`O prazo do escritório terminou em ${formatMonitoringDate(message.prazo_limite)}.`"
        />

        <DataTableMetaList :items="messageFacts" columns="grid-cols-2 gap-x-3 gap-y-2" />

        <!-- `whitespace-pre-wrap`: the provider sends plain text and its own line
             breaks are part of what the office is being shown. -->
        <div
          v-if="message.body_available && message.corpo"
          class="max-h-80 overflow-y-auto rounded-lg bg-elevated/50 p-3 text-sm whitespace-pre-wrap text-default"
        >
          {{ message.corpo }}
        </div>

        <UAlert
          v-else-if="!message.body_available"
          color="warning"
          variant="subtle"
          icon="i-lucide-file-warning"
          title="Corpo da mensagem indisponível"
          :description="message.notice"
        />
        <UAlert
          v-else
          color="neutral"
          variant="subtle"
          icon="i-lucide-file-text"
          title="Esta mensagem não tem texto"
          description="O provedor devolveu a intimação sem o corpo. A ciência e o prazo acima são o que foi registrado."
        />
      </div>
    </template>

    <template #footer>
      <div v-if="!confirmed" class="flex justify-end gap-2">
        <UButton
          label="Cancelar"
          color="neutral"
          variant="subtle"
          @click="isOpen = false"
        />
        <UButton
          v-if="canManageClients && (effectStatus === 'idle' || effectStatus === 'before_send')"
          :label="scienceRecorded ? 'Abrir mensagem' : 'Abrir e registrar ciência'"
          icon="i-lucide-mail-open"
          color="warning"
          variant="solid"
          :loading="loading"
          :disabled="loading"
          @click="confirm"
        />
        <UButton
          v-if="canManageClients && effectStatus === 'confirmed'"
          label="Reconciliar dados locais"
          icon="i-lucide-refresh-cw"
          color="warning"
          :loading="loading"
          :disabled="loading"
          @click="reconcile"
        />
      </div>

      <div v-else class="flex justify-end">
        <UButton
          label="Fechar"
          color="neutral"
          variant="subtle"
          @click="isOpen = false"
        />
      </div>
    </template>
  </UModal>
</template>
