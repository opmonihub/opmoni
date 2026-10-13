<script setup lang="ts">
import type { MonitoringAssessmentPeriod } from '~/types/serpro'
import type {
  ClientDispatchContact,
  WhatsAppChannelStatus
} from '~/types/whatsapp'
import { apiErrorMessage, apiStatus } from '~/composables/useApiError'
import {
  whatsappDispatchContactName,
  whatsappDispatchContactValueLabel,
  whatsappGuideTarget,
  whatsappSendGuidesPayload,
  whatsappSendGuidesPlan,
  whatsappSendGuidesReasons,
  type WhatsAppSendGuidesPlanItem
} from '~/utils/whatsappDispatch'
import DispatchContactFormModal from '~/components/monitoring/DispatchContactFormModal.vue'

/**
 * O modal Enviar guias: lista os Contatos de envio do cliente e dispara a
 * guia corrente — a que o backend resolve (período mais recente com
 * `slip_number` ou declaração transmitida) — para os WhatsApp marcados.
 * A pré-seleção desmarca quem já recebeu essa mesma guia e oferece a
 * Ciência de reenvio; contatos de e-mail são listados e nunca enviáveis.
 * Nada dispara sem o clique em Enviar guias.
 */
const props = defineProps<{
  open: boolean
  obligation: string
  clientId: number
  clientName?: string
  /**
   * Os períodos da linha — o alvo da pré-seleção é calculado aqui com a
   * mesma regra do backend (`whatsappGuideTarget`). Quando a página não os
   * tem, o modal abre sem guia resolvida e o envio fica bloqueado com a
   * frase do próprio modal.
   */
  periods?: readonly MonitoringAssessmentPeriod[] | null
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  /** Disparos confirmados — a folha quer reagir (selo, rastreio). */
  'sent': []
}>()

const isOpen = computed({
  get: () => props.open,
  set: value => emit('update:open', value)
})

const toast = useToast()
const { sendGuides, whatsappDispatches } = useSerpro()
const dispatchContacts = useDispatchContacts()
const { channel } = useWhatsAppChannel()

const contacts = ref<ClientDispatchContact[]>([])
const plan = ref<WhatsAppSendGuidesPlanItem[]>([])
const resendAcknowledged = ref<Set<number>>(new Set())
const loading = ref(false)
const sending = ref(false)
const loadFailed = ref(false)
const channelStatus = ref<WhatsAppChannelStatus | null | undefined>(undefined)
const contactFormOpen = ref(false)
const editingContact = ref<ClientDispatchContact | null>(null)

const target = computed(() => whatsappGuideTarget(props.periods ?? null))

async function loadContacts() {
  loading.value = true
  loadFailed.value = false
  channelStatus.value = undefined
  try {
    const [list, dispatches] = await Promise.all([
      dispatchContacts.list(props.clientId),
      whatsappDispatches(props.obligation, props.clientId, undefined, 'guia')
    ])
    contacts.value = list
    plan.value = whatsappSendGuidesPlan({ contacts: list, dispatches, target: target.value })
    resendAcknowledged.value = new Set()
  } catch {
    contacts.value = []
    plan.value = []
    loadFailed.value = true
  } finally {
    loading.value = false
  }

  // O canal é leitura própria: qualquer membro lê o estado; a falha dele
  // bloqueia o botão com a frase do estado, não o carregamento da lista.
  try {
    channelStatus.value = (await channel()).status
  } catch (e) {
    channelStatus.value = apiStatus(e) === 404 ? null : 'error'
  }
}

// `immediate`: o modal nasce já aberto (`v-if` + `open` no mesmo gesto).
// Sem isto o watch não dispara na montagem e a lista fica vazia mesmo
// com telefone no cadastro e Contato de envio gravado.
watch(() => props.open, (open) => {
  if (!open) return
  void loadContacts()
}, { immediate: true })

const channelBlockedReason = computed(() => {
  if (channelStatus.value === undefined || channelStatus.value === 'connected') return null
  if (channelStatus.value === null) return 'O escritório não tem Canal WhatsApp pareado.'
  if (channelStatus.value === 'pairing') return 'O Canal WhatsApp ainda está pareando — aguarde a conexão.'
  if (channelStatus.value === 'error') return 'O Canal WhatsApp está em erro — reinicie o pareamento em Configurações.'
  return 'O Canal WhatsApp está desconectado — pareie em Configurações.'
})

const checkedCount = computed(() => plan.value.filter(item => item.checked && item.sendable).length)
const noGuidesReason = computed(() =>
  target.value === null ? 'Não há guia disponível para envio nesta obrigação.' : null
)

const submitBlockedReason = computed(() =>
  channelBlockedReason.value ?? noGuidesReason.value
)

/**
 * Zero marcados NÃO entra no disabled: o clique é o que produz a frase de
 * validação ("Marque ao menos um Contato de envio WhatsApp para disparar"),
 * e um botão cinza com tooltip "Enviar para 0 contato(s)" esconderia a
 * única mensagem que diz o que fazer. O disabled fica para os bloqueios
 * externos (enviando, canal, sem guia).
 */
const confirmDisabled = computed(() =>
  sending.value || submitBlockedReason.value !== null
)

const validationError = ref<string | null>(null)

function toggleOne(item: WhatsAppSendGuidesPlanItem, checked: boolean) {
  if (!item.sendable) return
  item.checked = checked
  validationError.value = null
  if (!checked) resendAcknowledged.value.delete(item.contact.id)
}

function toggleResend(item: WhatsAppSendGuidesPlanItem, acknowledged: boolean) {
  if (acknowledged) {
    resendAcknowledged.value.add(item.contact.id)
    // Ciência sem a marca não envia — marcar junto é o gesto que o
    // checkbox da linha promete.
    item.checked = true
  } else {
    resendAcknowledged.value.delete(item.contact.id)
  }
}

function openContactForm(contact: ClientDispatchContact | null) {
  editingContact.value = contact
  contactFormOpen.value = true
}

async function afterContactSaved() {
  // O cadastro dentro do modal recarrega a lista: o contato novo entra
  // pré-selecionado se ainda não recebeu a guia — e a Ciência de reenvio
  // escolhida para os demais se preserva pelo id.
  const kept = new Set(resendAcknowledged.value)
  const checked = new Set(plan.value.filter(item => item.checked).map(item => item.contact.id))
  await loadContacts()
  for (const item of plan.value) {
    if (checked.has(item.contact.id) && item.sendable) item.checked = true
  }
  resendAcknowledged.value = new Set([...kept].filter(id => plan.value.some(item => item.contact.id === id)))
}

async function confirm() {
  const payload = whatsappSendGuidesPayload(plan.value, resendAcknowledged.value)
  if (payload === null) {
    validationError.value = whatsappSendGuidesReasons.noneChecked
    return
  }
  if (sending.value || submitBlockedReason.value !== null) return

  sending.value = true
  validationError.value = null
  try {
    const result = await sendGuides(props.obligation, props.clientId, payload.contact_ids, payload.resend_acknowledgements)
    const sent = result.dispatches.length
    const skippedNames = result.skipped
      .map(skip => contacts.value.find(contact => contact.id === skip.contact_id))
      .map(contact => contact ? whatsappDispatchContactName(contact) : null)
      .filter((name): name is string => name !== null)
    if (sent > 0 && skippedNames.length === 0) {
      toast.add({
        title: sent === 1 ? 'Guia enfileirada para 1 contato' : `Guia enfileirada para ${sent} contatos`,
        description: `Competência ${result.competence}.`,
        color: 'success'
      })
    } else if (sent > 0) {
      toast.add({
        title: `Guia enfileirada para ${sent} contatos`,
        description: `Sem envio para: ${skippedNames.join(', ')}.`,
        color: 'warning'
      })
    } else {
      toast.add({
        title: 'Nenhum envio foi criado',
        description: `Sem envio para: ${skippedNames.join(', ')}.`,
        color: 'warning'
      })
    }
    emit('sent')
    isOpen.value = false
  } catch (e) {
    toast.add({
      title: 'Não foi possível enviar as guias',
      description: apiErrorMessage(e) ?? 'Tente novamente.',
      color: 'error'
    })
    await loadContacts()
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <UModal
    v-model:open="isOpen"
    title="Enviar guias"
    :description="clientName ? `${clientName} — a guia corrente vai para os Contatos de envio WhatsApp marcados.` : 'A guia corrente vai para os Contatos de envio WhatsApp marcados.'"
  >
    <template #body>
      <div class="flex flex-col gap-3">
        <ErrorRetryAlert
          v-if="loadFailed"
          title="Não foi possível carregar os Contatos de envio"
          @retry="loadContacts"
        />

        <template v-else>
          <p v-if="target" class="text-xs text-muted">
            Guia da competência <span class="tabular-nums text-default">{{ target.period }}</span><span v-if="target.slipNumber"> · DAS {{ target.slipNumber }}</span>
          </p>
          <UAlert
            v-else
            color="warning"
            variant="subtle"
            icon="i-lucide-file-x"
            title="Nenhuma guia disponível"
            description="O cliente não tem guia sincronizada nesta obrigação — o envio fica bloqueado até uma guia existir."
          />

          <UAlert
            v-if="channelBlockedReason"
            color="warning"
            variant="subtle"
            icon="i-lucide-message-circle-off"
            :title="channelBlockedReason"
          />

          <div v-if="loading" class="flex flex-col gap-2">
            <USkeleton v-for="index in 3" :key="index" class="h-12 w-full rounded-lg" />
          </div>

          <div v-else-if="plan.length === 0" class="flex flex-col items-center gap-2 py-4">
            <UIcon name="i-lucide-contact-round" class="size-8 text-dimmed" />
            <p class="text-sm text-muted">
              Este cliente não tem Contatos de envio cadastrados.
            </p>
            <UButton
              label="Cadastrar Contato de envio"
              icon="i-lucide-plus"
              color="neutral"
              variant="outline"
              size="sm"
              @click="openContactForm(null)"
            />
          </div>

          <ul v-else class="flex flex-col divide-y divide-default rounded-lg ring ring-default">
            <li
              v-for="item in plan"
              :key="item.contact.id"
              class="flex flex-col gap-1.5 px-3 py-2.5"
            >
              <div class="flex items-center gap-3">
                <UCheckbox
                  :model-value="item.checked"
                  :disabled="!item.sendable"
                  :aria-label="`Enviar para ${whatsappDispatchContactName(item.contact)}`"
                  @update:model-value="toggleOne(item, $event === true)"
                />
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm text-default">
                    {{ whatsappDispatchContactName(item.contact) }}
                  </p>
                  <p class="truncate text-xs tabular-nums text-muted">
                    {{ whatsappDispatchContactValueLabel(item.contact) }}
                  </p>
                </div>
                <UBadge
                  :color="item.contact.channel === 'whatsapp' ? 'success' : 'neutral'"
                  variant="subtle"
                  size="sm"
                  :icon="item.contact.channel === 'whatsapp' ? 'i-lucide-message-circle' : 'i-lucide-mail'"
                  :label="item.contact.channel === 'whatsapp' ? 'WhatsApp' : 'E-mail'"
                />
              </div>
              <p v-if="item.blockedReason" class="pl-8 text-xs text-muted">
                {{ item.blockedReason }}
              </p>
              <UCheckbox
                v-else-if="item.alreadySent"
                :model-value="resendAcknowledged.has(item.contact.id)"
                label="Ciência de reenvio — este contato já recebeu esta guia"
                size="sm"
                class="pl-8"
                :ui="{ label: 'text-xs text-muted' }"
                @update:model-value="toggleResend(item, $event === true)"
              />
            </li>
          </ul>

          <p v-if="validationError" class="text-xs text-error">
            {{ validationError }}
          </p>
        </template>
      </div>
    </template>

    <template #footer="{ close }">
      <div class="flex w-full items-center justify-between gap-2">
        <UButton
          label="Cadastrar contato"
          icon="i-lucide-plus"
          color="neutral"
          variant="ghost"
          size="sm"
          :disabled="loadFailed || loading"
          @click="openContactForm(null)"
        />
        <div class="flex items-center gap-1.5">
          <UButton
            label="Cancelar"
            color="neutral"
            variant="outline"
            @click="close"
          />
          <UTooltip :text="submitBlockedReason ?? `Enviar para ${checkedCount} contato(s)`">
            <UButton
              label="Enviar guias"
              icon="i-lucide-send"
              :disabled="confirmDisabled"
              :loading="sending"
              @click="confirm"
            />
          </UTooltip>
        </div>
      </div>
    </template>
  </UModal>

  <DispatchContactFormModal
    v-model:open="contactFormOpen"
    :client-id="clientId"
    :client-name="clientName"
    :contact="editingContact"
    @saved="afterContactSaved"
  />
</template>
