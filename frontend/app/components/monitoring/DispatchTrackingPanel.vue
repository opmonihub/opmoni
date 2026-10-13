<script setup lang="ts">
import type { WhatsAppDispatch } from '~/types/whatsapp'
import type { MonitoringObligation } from '~/utils/monitoringNav'
import {
  whatsappDispatchDestinationLabel,
  whatsappDispatchStatusPresentation,
  whatsappTrackingCardText,
  whatsappTrackingGroups,
  whatsappTrackingTabLabels,
  type WhatsAppTrackingTab
} from '~/utils/whatsappDispatch'
import { formatMonitoringConsultedAt } from '~/utils/monitoringPresentation'

/**
 * O painel Rastreio de Envio da linha: o histórico completo do cliente
 * nesta obrigação (`GET …/whatsapp/dispatches`, sem competência — o filtro
 * por competência é do modal Enviar guias), em três abas — Envio (em
 * trânsito), Visualização (lido) e Não enviados (falhou). Cada cartão traz
 * o telefone do Contato de envio, o texto fixo que o escritório mandou e
 * a data do disparo. Qualquer papel lê; quem dispara ou cadastra faz isso
 * na coluna Enviar.
 */
const props = defineProps<{
  open: boolean
  obligation: MonitoringObligation
  clientId: number
  clientName: string
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

const isOpen = computed({
  get: () => props.open,
  set: value => emit('update:open', value)
})

const { whatsappDispatches } = useSerpro()

const dispatches = ref<WhatsAppDispatch[]>([])
const loading = ref(false)
const loadFailed = ref(false)

const groups = computed(() => whatsappTrackingGroups(dispatches.value))

const tabOrder: readonly WhatsAppTrackingTab[] = ['envio', 'visualizacao', 'nao_enviados']
const activeTab = ref<WhatsAppTrackingTab>('envio')

/**
 * O poço tonal do ícone do cartão, estático para o Tailwind ver — uma
 * classe interpolada por status (`text-${color}`) não existe no build.
 * As cores são as da `whatsappDispatchStatusPresentation`, em `bg` + `text`.
 */
const whatsappTrackingIconClass: Record<WhatsAppDispatch['status'], string> = {
  queued: 'bg-elevated text-muted',
  sent: 'bg-info/10 text-info',
  delivered: 'bg-info/10 text-info',
  read: 'bg-success/10 text-success',
  failed: 'bg-error/10 text-error'
}

const tabs = computed(() => tabOrder.map(tab => ({
  label: `${whatsappTrackingTabLabels[tab]} (${groups.value[tab].length})`,
  value: tab,
  slot: 'cards' as const
})))

async function load() {
  loading.value = true
  loadFailed.value = false
  dispatches.value = []
  try {
    dispatches.value = await whatsappDispatches(props.obligation.slug, props.clientId)
  } catch {
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}

watch(() => props.open, (open) => {
  if (!open) return
  activeTab.value = 'envio'
  void load()
})
</script>

<template>
  <UModal
    v-model:open="isOpen"
    title="Rastreio de Envio"
    :description="`${obligation.label} · ${clientName}`"
  >
    <template #body>
      <ErrorRetryAlert
        v-if="loadFailed"
        title="Não foi possível carregar o rastreio de envio"
        @retry="load"
      />

      <div v-else-if="loading" class="flex flex-col gap-2">
        <USkeleton v-for="index in 3" :key="index" class="h-16 w-full rounded-lg" />
      </div>

      <UEmpty
        v-else-if="dispatches.length === 0"
        icon="i-lucide-message-circle-off"
        title="Nenhum disparo"
        description="Este cliente ainda não recebeu guia por WhatsApp nesta obrigação."
        variant="naked"
      />

      <UTabs
        v-else
        v-model="activeTab"
        :items="tabs"
        variant="link"
        :ui="{ list: 'w-full' }"
      >
        <template #cards="{ item }">
          <div class="flex flex-col gap-2 pt-3">
            <UEmpty
              v-if="groups[item.value as WhatsAppTrackingTab].length === 0"
              icon="i-lucide-inbox"
              :title="`Nada em ${whatsappTrackingTabLabels[item.value as WhatsAppTrackingTab]}`"
              variant="naked"
              class="py-4"
            />
            <div
              v-for="dispatch in groups[item.value as WhatsAppTrackingTab]"
              :key="dispatch.id"
              class="flex items-center gap-3 rounded-lg bg-default p-3 ring ring-default"
            >
              <div
                class="flex size-7 shrink-0 items-center justify-center rounded-md"
                :class="whatsappTrackingIconClass[dispatch.status]"
              >
                <UIcon :name="whatsappDispatchStatusPresentation[dispatch.status].icon" class="size-4" />
              </div>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm tabular-nums text-default">
                  {{ whatsappDispatchDestinationLabel(dispatch) }}
                </p>
                <p class="truncate text-xs text-muted">
                  {{ whatsappTrackingCardText }}
                </p>
                <p
                  v-if="dispatch.status === 'failed' && dispatch.failure_reason"
                  class="mt-0.5 truncate text-xs text-error"
                >
                  {{ dispatch.failure_reason }}
                </p>
              </div>
              <div class="flex shrink-0 flex-col items-end gap-0.5">
                <UBadge
                  variant="subtle"
                  size="sm"
                  :color="whatsappDispatchStatusPresentation[dispatch.status].color"
                  :label="whatsappDispatchStatusPresentation[dispatch.status].label"
                />
                <span class="text-xs tabular-nums text-muted">
                  {{ formatMonitoringConsultedAt(dispatch.created_at) }}
                </span>
              </div>
            </div>
          </div>
        </template>
      </UTabs>
    </template>

    <template #footer>
      <div class="flex justify-end">
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
