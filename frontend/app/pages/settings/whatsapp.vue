<script setup lang="ts">
import QRCode from 'qrcode'
import type { MetaListItem } from '~/components/data-table/MetaList.vue'
import { apiErrorMessage, apiStatus } from '~/composables/useApiError'
import type { WhatsAppChannel, WhatsAppChannelOperationNotice } from '~/types/whatsapp'
import { formatMonitoringDate } from '~/utils/monitoringPresentation'
import { pageScrollClass } from '~/utils/pageShell'
import {
  whatsappChannelStatusNote,
  whatsappChannelStatusPresentation,
  whatsappPairingPollMs,
  whatsappQrScannable
} from '~/utils/whatsappChannelPresentation'

/**
 * O Canal WhatsApp do escritório mora em Configurações, no mesmo espírito do
 * e-CNPJ: um número por Account, e quem pareia/desconecta é o `admin` da
 * Account (ou super_admin em suporte) — `middleware: ['auth',
 * 'account-admin']`, explícito como em `/settings/certificado`.
 *
 * A tela é a superfície dos quatro estados espelhados: desconectado pede o
 * pareamento, pareando desenha o QR que o celular lê, conectado mostra o
 * número e erro mostra o motivo que o gateway gravou. O polling só existe
 * enquanto o estado é `pairing` — a API responde `404` fora dele, que é o
 * sinal para parar o relógio e reler o canal inteiro.
 */
definePageMeta({ middleware: ['auth', 'account-admin'] })

const toast = useToast()
const { channel, startPairing, pairingStatus, disconnect, continueOperation, reconcileOperation } = useWhatsAppChannel()

async function readChannel() {
  try {
    return await channel()
  } catch (e) {
    if (apiStatus(e) === 404) return null
    throw e
  }
}

/**
 * O canal que a Account já tem, e `null` quando ainda não pareou nenhum —
 * resposta normal desta rota (`404`), não falha. Sem o `null` a tela não
 * distingue "nunca pareou" de "pareou e desconectou".
 */
const { data: currentChannel, status: channelStatus, error: channelError, refresh: reloadChannel } = await useAsyncData<WhatsAppChannel | null>(
  'whatsapp-channel',
  readChannel,
  { default: () => null, getCachedData: (key, nuxtApp) => nuxtApp.isHydrating ? nuxtApp.payload.data[key] : undefined }
)

const { isLoading, showError, retry } = useRetryableLoad({
  refresh: refreshChannel,
  error: channelError,
  loading: computed(() => channelStatus.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar o Canal WhatsApp',
  refreshErrorTitle: 'Não foi possível atualizar o Canal WhatsApp',
  ignoreStatus: 404
})

/** O QR que o último pareamento devolveu — sai do payload e vira imagem. */
const qrCode = ref<string | null>(null)
const qrExpiresAt = ref<string | null>(null)
const qrImage = ref<string | null>(null)
const qrModalOpen = ref(false)
const pairing = ref(false)
const disconnecting = ref(false)
const confirmingDisconnect = ref(false)
const operationNotice = ref<WhatsAppChannelOperationNotice | null>(currentChannel.value?.operation ?? null)

function rememberOperation(error: unknown) {
  const data = (error as { data?: Partial<WhatsAppChannelOperationNotice> })?.data
  if (apiStatus(error) !== 409 || typeof data?.operation_id !== 'string' || typeof data.next_step !== 'string'
    || !['operation_continuation_required', 'operation_reconciliation_required', 'operation_manual_review_required', 'operation_superseded'].includes(data.code ?? '')) return false
  operationNotice.value = data as WhatsAppChannelOperationNotice
  stopPolling()
  clearQr()
  qrModalOpen.value = false
  return true
}

async function resumeOperation() {
  const notice = operationNotice.value
  if (!notice || pairing.value || disconnecting.value) return
  if (!['operation_continuation_required', 'operation_reconciliation_required'].includes(notice.code)) return
  const generation = invalidateChannelCycle()
  pairing.value = true
  try {
    const next = await (notice.code === 'operation_reconciliation_required'
      ? reconcileOperation(notice.operation_id)
      : continueOperation(notice.operation_id))
    if (generation !== channelGeneration) return
    operationNotice.value = null
    confirmingDisconnect.value = false
    await rememberQr(next, generation)
    if (generation !== channelGeneration) return
    applyChannel(next)
    if (next.status === 'connected') showConnectedInModal()
  } catch (error) {
    if (generation !== channelGeneration) return
    if (!rememberOperation(error)) toast.add({ title: 'Não foi possível continuar a operação', description: apiErrorMessage(error), color: 'error' })
  } finally {
    if (generation === channelGeneration) pairing.value = false
  }
}

let pollTimer: ReturnType<typeof setInterval> | null = null
let channelGeneration = 0
let pollingGeneration: number | null = null

const status = computed<WhatsAppChannel['status']>(() => currentChannel.value?.status ?? 'disconnected')
const statusPresentation = computed(() => whatsappChannelStatusPresentation[status.value])
const statusNote = computed(() => whatsappChannelStatusNote[status.value])
const isPairing = computed(() => status.value === 'pairing')
const pairedNumber = computed(() => currentChannel.value?.whatsapp_jid?.split('@')[0] ?? null)

/**
 * O modal aberto com o canal já `connected` é o desfecho do pareamento: o
 * QR sai e o corpo vira o visto. Enquanto ainda está `pairing`, o mesmo
 * modal continua sendo o código.
 */
const pairingModalConnected = computed(() => qrModalOpen.value && status.value === 'connected')
const pairingModalTitle = computed(() => pairingModalConnected.value ? 'Canal WhatsApp conectado' : 'Parear o Canal WhatsApp')
const pairingModalDescription = computed(() => {
  if (!pairingModalConnected.value) {
    return 'Abra o WhatsApp no celular do escritório e leia o código em Aparelhos conectados.'
  }
  return pairedNumber.value
    ? `O aparelho ${pairedNumber.value} está conectado neste canal.`
    : 'O celular do escritório está conectado neste canal.'
})

/**
 * Os fatos do canal conectado, no formato que o `DataTableMetaList` lê. O JID
 * vem como `5511…@s.whatsapp.net` e a tela corta o sufixo do protocolo —
 * o operador quer o número, não o envelope.
 */
const channelFacts = computed<MetaListItem[]>(() => {
  const stored = currentChannel.value
  if (!stored) return []
  const jid = stored.whatsapp_jid?.split('@')[0] ?? null
  return [
    { label: 'Número pareado', value: jid ?? '—', mono: true, when: stored.status === 'connected' },
    { label: 'Conectado em', value: formatMonitoringDate(stored.connected_at), mono: true, when: stored.connected_at != null },
    { label: 'Pareamento iniciado em', value: formatMonitoringDate(stored.pairing_started_at), mono: true, when: stored.pairing_started_at != null },
    { label: 'Último erro', value: stored.last_error ?? '—', truncate: true, tone: 'error' as const, when: stored.last_error != null }
  ]
})

function stopPolling() {
  if (pollTimer !== null) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

function invalidateChannelCycle() {
  channelGeneration += 1
  pollingGeneration = null
  stopPolling()
  return channelGeneration
}

function clearQr() {
  qrCode.value = null
  qrExpiresAt.value = null
  qrImage.value = null
}

/**
 * O pareamento se resolveu com o operador ainda nesta tela: o modal fica
 * aberto e troca sozinho para o visto. O toast não duplica esse aviso.
 */
function showConnectedInModal() {
  clearQr()
  qrModalOpen.value = true
}

async function drawQr(code: string, generation: number) {
  try {
    const image = await QRCode.toDataURL(code, { margin: 2, width: 280 })
    if (generation === channelGeneration && qrCode.value === code) qrImage.value = image
  } catch {
    // Código não desenhável é condição transitória do pareamento — o polling
    // segue e um novo pareamento emite outro código.
    if (generation === channelGeneration && qrCode.value === code) qrImage.value = null
  }
}

/**
 * Guarda o código cru e abre o modal quando ele é desenhável. A leitura do
 * canal e o polling devolvem o QR corrente; sem isto um reload no meio do
 * pareamento ficaria sem imagem.
 */
async function rememberQr(next: Pick<WhatsAppChannel, 'qr_code' | 'qr_expires_at'> | null, generation = channelGeneration) {
  if (generation !== channelGeneration || !whatsappQrScannable(next?.qr_code)) return
  const changed = qrCode.value !== next.qr_code
  qrCode.value = next.qr_code
  qrExpiresAt.value = next.qr_expires_at
  if (changed || !qrImage.value) {
    await drawQr(next.qr_code, generation)
  }
  // Só abre sozinho quando o código é novo. O polling repete o mesmo
  // código a cada poucos segundos; reabrir aí impediria o Fechar de valer.
  // Um código novo (rotação do gateway) abre de novo. "Ver código" reabre
  // o modal que o operador fechou.
  if (generation === channelGeneration && qrCode.value === next.qr_code && qrImage.value && changed) {
    qrModalOpen.value = true
  }
}

function applyChannel(next: WhatsAppChannel | null) {
  currentChannel.value = next
  if (next?.operation) operationNotice.value = next.operation
  if (operationNotice.value) {
    stopPolling()
    clearQr()
    return
  }
  if (next?.status === 'pairing') {
    startPolling()
  } else {
    stopPolling()
    clearQr()
    if (next?.status !== 'connected') qrModalOpen.value = false
  }
}

async function refreshChannel() {
  const generation = invalidateChannelCycle()
  await reloadChannel()
  if (generation !== channelGeneration || channelError.value) return
  if (currentChannel.value?.status === 'pairing') await rememberQr(currentChannel.value, generation)
  if (generation !== channelGeneration) return
  applyChannel(currentChannel.value)
}

async function poll() {
  const generation = channelGeneration
  if (pollingGeneration === generation || status.value !== 'pairing') return
  pollingGeneration = generation
  try {
    const next = await pairingStatus()
    if (generation !== channelGeneration) return
    if (next.status === 'pairing') await rememberQr(next, generation)
    if (generation !== channelGeneration) return
    applyChannel(next)
    if (next.status === 'connected') {
      showConnectedInModal()
    } else if (next.status === 'error') {
      toast.add({ title: 'Falha no pareamento', description: next.last_error ?? undefined, color: 'error' })
    }
  } catch (e) {
    if (generation !== channelGeneration) return
    // `404` = não há pareamento em curso: o relógio morre e o canal inteiro é
    // relido, porque o estado dele pode ter andado (outro admin desconectou,
    // o gateway encerrou a sessão).
    // `502` = o gateway respondeu falha no polling e o backend já espelhou
    // `error` no canal — parar o relógio e reler o canal mostra o motivo
    // gravado em vez de continuar batendo na mesma falha.
    // Falha transitória não derruba o polling — o QR na tela continua valendo
    // até `qr_expires_at`.
    // O QR expirado já matou o relógio: não reler o canal aqui — isso
    // dispararia um novo ciclo de SSR/`useAsyncData` sob a mesma sessão e o
    // `status` continuaria `pairing`. O operador reinicia pela tela.
    if (apiStatus(e) === 404 || apiStatus(e) === 502) {
      stopPolling()
      if (!qrExpired.value) {
        // A releitura fica local até confirmar o ciclo. `useAsyncData.refresh`
        // escreveria uma resposta antiga diretamente no canal corrente.
        try {
          const next = await readChannel()
          if (generation !== channelGeneration) return
          if (next?.status === 'pairing') await rememberQr(next, generation)
          if (generation !== channelGeneration) return
          applyChannel(next)
          if (next?.status === 'connected') showConnectedInModal()
        } catch (readError) {
          if (generation !== channelGeneration) return
          channelError.value = createError({
            statusCode: apiStatus(readError) ?? 500,
            message: apiErrorMessage(readError) ?? 'Não foi possível atualizar o Canal WhatsApp'
          })
        }
      }
    }
  } finally {
    if (pollingGeneration === generation) pollingGeneration = null
  }
}

function startPolling() {
  stopPolling()
  // QR já expirado não abre relógio — o primeiro tick só colheria o `404` de
  // "sem pareamento em curso" e o operador já vê o aviso na tela.
  if (qrExpired.value) return
  pollTimer = setInterval(() => {
    now.value = Date.now()
    void poll()
  }, whatsappPairingPollMs)
}

async function submitPairing() {
  if (pairing.value || disconnecting.value || operationNotice.value) return
  const generation = invalidateChannelCycle()
  pairing.value = true
  try {
    const next = await startPairing()
    if (generation !== channelGeneration) return
    if (next.status === 'connected' || !whatsappQrScannable(next.qr_code)) {
      // Instância já conectada responde `connected` sem QR — o pareamento se
      // resolveu sozinho e não há código para desenhar. O modal abre direto
      // no visto.
      clearQr()
      applyChannel(next)
      if (next.status === 'connected') {
        showConnectedInModal()
      }
      return
    }
    // O QR novo tem que valer antes do `applyChannel`: com a expiração do
    // pareamento anterior ainda marcada, o `startPolling` leria um
    // `qrExpired` obsoleto e não armaria o relógio do pareamento recém-
    // iniciado — o QR fresco apareceria sem polling atrás dele.
    await rememberQr(next, generation)
    if (generation !== channelGeneration) return
    applyChannel(next)
  } catch (err) {
    if (generation !== channelGeneration) return
    if (!rememberOperation(err)) toast.add({ title: 'Não foi possível iniciar o pareamento', description: apiErrorMessage(err), color: 'error' })
  } finally {
    if (generation === channelGeneration) pairing.value = false
  }
}

async function confirmDisconnect() {
  if (pairing.value || disconnecting.value || operationNotice.value) return
  const generation = invalidateChannelCycle()
  disconnecting.value = true
  try {
    const next = await disconnect()
    if (generation !== channelGeneration) return
    confirmingDisconnect.value = false
    clearQr()
    applyChannel(next)
    toast.add({ title: 'Canal WhatsApp desconectado', color: 'success' })
  } catch (err) {
    if (generation !== channelGeneration) return
    if (!rememberOperation(err)) toast.add({ title: 'Não foi possível desconectar o canal', description: apiErrorMessage(err), color: 'error' })
  } finally {
    if (generation === channelGeneration) disconnecting.value = false
  }
}

/** O relógio do template, para a comparação de expiração ser reativa. */
const now = ref(Date.now())

/** O QR expirou em tela: um novo pareamento emite outro código. */
const qrExpired = computed(() => {
  if (!qrExpiresAt.value) return false
  const expiresMs = new Date(qrExpiresAt.value).getTime()
  return !Number.isNaN(expiresMs) && expiresMs <= now.value
})

// O polling morre com a tela: sair de Configurações no meio do pareamento não
// pode deixar um relógio batendo na API em nome de uma página que já não
// existe.
onBeforeUnmount(invalidateChannelCycle)

// QR expirado em tela não justifica mais o relógio — o gateway também expirou
// o código, então o próximo tick só colheria o `404` de "sem pareamento em
// curso". O operador reinicia pelo botão "Novo pareamento".
watch(qrExpired, (expired) => {
  if (expired) stopPolling()
})

// Um canal que chega ao `useAsyncData` já em `pairing` (SSR leu o estado em
// curso) abre o polling — o operador voltou para a tela para ler o código.
// `import.meta.client`: no servidor o `onBeforeUnmount` nunca roda e um
// `setInterval` do Nitro continuaria batendo na API depois da resposta.
if (import.meta.client && !operationNotice.value && currentChannel.value?.status === 'pairing') {
  startPolling()
  void rememberQr(currentChannel.value)
  void poll()
}
</script>

<template>
  <div :class="pageScrollClass">
    <ErrorRetryAlert
      v-if="showError"
      title="Não foi possível carregar o Canal WhatsApp"
      @retry="retry"
    />

    <USkeleton v-else-if="isLoading" class="h-64 w-full rounded-xl" />

    <UPageCard
      v-else
      title="Canal WhatsApp"
      description="O número do escritório que envia os documentos do monitoramento. Um canal por conta, pareado pelo celular do escritório."
      variant="subtle"
    >
      <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-2">
          <UBadge
            :label="statusPresentation.label"
            :color="statusPresentation.color"
            variant="subtle"
          />
          <p
            v-if="statusNote"
            class="text-sm text-muted"
          >
            {{ statusNote }}
          </p>
        </div>

        <template v-if="isPairing">
          <UButton
            v-if="qrImage"
            label="Ver código"
            icon="i-lucide-qr-code"
            color="neutral"
            variant="subtle"
            type="button"
            @click="qrModalOpen = true"
          />
          <p
            v-else
            class="text-sm text-muted"
          >
            Buscando o código do pareamento. Ele abre sozinho quando o gateway responder.
          </p>
        </template>

        <DataTableMetaList
          v-if="channelFacts.length"
          :items="channelFacts"
        />

        <UAlert
          v-if="operationNotice"
          color="warning"
          variant="subtle"
          title="Operação WhatsApp preservada"
          :description="operationNotice.message ?? 'Consulte o resultado antes de tentar novamente. Nenhuma ação será reenviada automaticamente.'"
          role="status"
        />
        <UButton
          v-if="operationNotice && ['operation_continuation_required', 'operation_reconciliation_required'].includes(operationNotice.code)"
          :label="operationNotice.code === 'operation_reconciliation_required' ? 'Consultar resultado' : 'Continuar pareamento'"
          :loading="pairing"
          :disabled="disconnecting"
          type="button"
          @click="resumeOperation"
        />

        <div v-if="!operationNotice" class="flex flex-wrap items-center justify-between gap-2">
          <UButton
            v-if="status === 'disconnected' || status === 'error' || (isPairing && qrExpired)"
            :label="status === 'disconnected' ? 'Conectar' : 'Novo pareamento'"
            icon="i-lucide-qr-code"
            type="button"
            :loading="pairing"
            @click="submitPairing"
          />

          <UButton
            v-if="!confirmingDisconnect && status === 'connected'"
            label="Desconectar"
            icon="i-lucide-unplug"
            color="error"
            variant="ghost"
            type="button"
            @click="confirmingDisconnect = true"
          />
        </div>

        <UAlert
          v-if="confirmingDisconnect && !operationNotice"
          color="error"
          variant="subtle"
          icon="i-lucide-unplug"
          title="Desconectar o Canal WhatsApp?"
          description="A sessão do número pareado é encerrada no gateway e o Disparo de documentos para de funcionar até um novo pareamento."
        />
        <div
          v-if="confirmingDisconnect && !operationNotice"
          class="flex justify-end gap-2"
        >
          <UButton
            label="Manter conectado"
            color="neutral"
            variant="subtle"
            type="button"
            @click="confirmingDisconnect = false"
          />
          <UButton
            label="Confirmar desconexão"
            color="error"
            variant="solid"
            type="button"
            :loading="disconnecting"
            @click="confirmDisconnect"
          />
        </div>
      </div>
    </UPageCard>

    <UModal
      v-model:open="qrModalOpen"
      :title="pairingModalTitle"
      :description="pairingModalDescription"
    >
      <template #body>
        <div
          v-if="pairingModalConnected"
          class="flex flex-col items-center gap-3 py-4"
        >
          <span class="flex size-16 items-center justify-center rounded-full bg-success/10 text-success ring ring-success/25">
            <UIcon
              name="i-lucide-circle-check"
              class="size-9"
            />
          </span>
          <p class="text-center text-sm text-muted">
            Pode fechar esta janela. O canal permanece conectado no celular do escritório.
          </p>
        </div>
        <div
          v-else
          class="flex flex-col items-center gap-3"
        >
          <img
            v-if="qrImage"
            :src="qrImage"
            alt="Código QR do pareamento do Canal WhatsApp"
            class="size-64 rounded-lg bg-default p-3 ring ring-default"
          >
          <p
            v-if="qrExpired"
            class="text-sm text-warning"
          >
            Este código expirou. Inicie o pareamento de novo para gerar outro.
          </p>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end">
          <UButton
            label="Fechar"
            color="neutral"
            variant="subtle"
            type="button"
            @click="qrModalOpen = false"
          />
        </div>
      </template>
    </UModal>
  </div>
</template>
