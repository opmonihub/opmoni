<script setup lang="ts">
import type { MonitoringMessage, MonitoringMessageStub } from '~/types/serpro'
import type { MonitoringObligation } from '~/utils/monitoringNav'
import { formatMonitoringDate, monitoringDeadlinePassed } from '~/utils/monitoringPresentation'

defineOptions({ inheritAttrs: false })

const props = defineProps<{
  open: boolean
  obligation: MonitoringObligation
  clientName: string
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

const toast = useToast()
const { readMessage } = useSerpro()

const confirmed = ref(false)
const message = ref<MonitoringMessage | null>(null)
const loading = ref(false)

/**
 * The body is fetched here and nowhere else. `watch(() => props.open)` must not
 * call `readMessage`: opening the dialog is not consent. It only clears the
 * state, so a message already read in this session is never carried into the
 * next opening.
 */
watch(() => props.open, (open) => {
  if (!open) return
  confirmed.value = false
  message.value = null
})

/** The office already knows this one: the act is behind the office, not ahead. */
const scienceRecorded = computed(() => !!props.stub.ciencia_em)
/** What the stub says about the deadline — the consent names it before the read. */
const stubDeadlinePassed = computed(() => monitoringDeadlinePassed(props.stub.prazo_limite))
/** What the provider recorded — the detail is read from the message, not the stub. */
const deadlinePassed = computed(() => monitoringDeadlinePassed(message.value?.prazo_limite))

async function confirm() {
  confirmed.value = true
  loading.value = true
  try {
    message.value = await readMessage(props.obligation.slug, props.stub.id)
    emit('read', props.stub.id)
  } catch {
    // Nothing was recorded, so the question stands again. A dialog left in the
    // confirmed state would claim an act that did not happen — the one thing
    // this screen must never do.
    confirmed.value = false
    toast.add({ title: 'Não foi possível abrir a mensagem', color: 'error' })
  } finally {
    loading.value = false
  }
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

        <p class="text-sm font-medium text-highlighted">
          {{ stub.assunto }}
        </p>

        <dl class="grid grid-cols-2 gap-x-3 gap-y-2">
          <div class="min-w-0">
            <dt class="text-xs text-muted">
              Recebida em
            </dt>
            <dd class="truncate text-sm text-default tabular-nums">
              {{ formatMonitoringDate(stub.received_at) }}
            </dd>
          </div>
          <div v-if="scienceRecorded" class="min-w-0">
            <dt class="text-xs text-muted">
              Ciência em
            </dt>
            <dd class="truncate text-sm text-default tabular-nums">
              {{ formatMonitoringDate(stub.ciencia_em) }}
            </dd>
          </div>
        </dl>
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

        <dl class="grid grid-cols-2 gap-x-3 gap-y-2">
          <div class="min-w-0">
            <dt class="text-xs text-muted">
              Ciência em
            </dt>
            <dd class="truncate text-sm text-default tabular-nums">
              {{ formatMonitoringDate(message.ciencia_em) }}
            </dd>
          </div>
          <div class="min-w-0">
            <dt class="text-xs text-muted">
              Prazo
            </dt>
            <dd class="truncate text-sm text-default tabular-nums">
              {{ formatMonitoringDate(message.prazo_limite) }}
            </dd>
          </div>
          <div v-if="message.codigo" class="min-w-0">
            <dt class="text-xs text-muted">
              Código
            </dt>
            <dd class="truncate text-sm text-default tabular-nums">
              {{ message.codigo }}
            </dd>
          </div>
        </dl>

        <!-- `whitespace-pre-wrap`: the provider sends plain text and its own line
             breaks are part of what the office is being shown. -->
        <div
          v-if="message.corpo"
          class="max-h-80 overflow-y-auto rounded-lg bg-elevated/50 p-3 text-sm whitespace-pre-wrap text-default"
        >
          {{ message.corpo }}
        </div>

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
          :label="scienceRecorded ? 'Abrir mensagem' : 'Abrir e registrar ciência'"
          icon="i-lucide-mail-open"
          color="warning"
          variant="solid"
          :loading="loading"
          :disabled="loading"
          @click="confirm"
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
