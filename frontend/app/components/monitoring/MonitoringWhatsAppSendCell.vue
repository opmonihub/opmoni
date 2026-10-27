<script setup lang="ts">
import type { MonitoringClient } from '~/types/serpro'
import { whatsappSheetSendAvailability } from '~/utils/whatsappDispatch'
import type { MonitoringObligation } from '~/utils/monitoringNav'

/**
 * A célula Enviar da folha servida: cliente com WhatsApp abre o Enviar
 * guias; cliente sem abre o cadastro rápido (avatar + plus da referência
 * Simples Nacional); obrigação servida sem Disparo (hoje, tudo que não é
 * PGDAS) mostra o controle desabilitado com o motivo no tooltip — a
 * coluna fica, porque a spec a exige em toda folha servida.
 *
 * "Tem contato WhatsApp" é leitura por linha, cacheada: um GET por
 * cliente por folha, nunca por clique. `admin|operador` gravam; `user`
 * lê o rastreio e não dispara nem cadastra — a célula inteira vira
 * desabilitada com o motivo do papel.
 */
const props = defineProps<{
  obligation: MonitoringObligation
  row: MonitoringClient
  /** Papel que escreve (admin|operador|suporte); `user` só lê rastreio. */
  canSend: boolean
}>()

const emit = defineEmits<{
  /** Tem contato WhatsApp — abre o Enviar guias. */
  'send': [row: MonitoringClient]
  /** Sem contato — abre o cadastro de Contato de envio. */
  'register-contact': [row: MonitoringClient]
}>()

const { list } = useDispatchContacts()

const availability = computed(() => whatsappSheetSendAvailability(props.obligation))
const roleReason = 'Só administradores e operadores disparam guias — seu papel permite ver o rastreio.'

/** `undefined` = lendo; `null` = sem contato; objeto = tem WhatsApp. */
const whatsappContactCount = ref<number | null | undefined>(undefined)
let requestedClient = 0

async function loadContactCount() {
  const clientId = props.row.client_id
  requestedClient = clientId
  whatsappContactCount.value = undefined
  try {
    const contacts = await list(clientId)
    if (requestedClient !== clientId) return
    whatsappContactCount.value = contacts.filter(contact => contact.channel === 'whatsapp').length
  } catch {
    if (requestedClient !== clientId) return
    // Falha na leitura não é "sem contato": deixa o avatar + plus oferecer
    // o cadastro — quem decide o que segue é o modal, com a leitura refeita.
    whatsappContactCount.value = null
  }
}

onMounted(loadContactCount)

const blockedReason = computed(() =>
  availability.value.reason ?? (props.canSend ? null : roleReason)
)
</script>

<template>
  <UTooltip :text="blockedReason ?? undefined" :disabled="blockedReason === null">
    <UButton
      v-if="whatsappContactCount === null"
      icon="i-lucide-user-round-plus"
      color="neutral"
      variant="outline"
      size="xs"
      :disabled="!availability.available || !canSend"
      :aria-label="`Cadastrar Contato de envio para ${row.name}`"
      @click="emit('register-contact', row)"
    >
      Enviar
    </UButton>
    <UButton
      v-else
      icon="i-lucide-send"
      color="neutral"
      variant="soft"
      size="xs"
      :disabled="!availability.available || !canSend || whatsappContactCount === undefined"
      :loading="whatsappContactCount === undefined"
      :aria-label="`Enviar guias para ${row.name}`"
      @click="emit('send', row)"
    >
      Enviar
    </UButton>
  </UTooltip>
</template>
