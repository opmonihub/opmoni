import type { WhatsAppChannel } from '~/types/whatsapp'

/**
 * O Canal WhatsApp da conta corrente. Um por Account, como o certificado do
 * escritório: qualquer Membro lê o estado (`channel`), e parear/desconectar
 * transportam a resposta da API — o `admin` é exigido no backend, a tela
 * decide esconder os botões.
 */
export function useWhatsAppChannel() {
  const { $api } = useNuxtApp()

  /** `404` quando a conta ainda não tem canal — estado inicial normal, não falha. */
  async function channel() {
    const res = await $api<{ data: WhatsAppChannel }>('/whatsapp/channel')
    return res.data
  }

  /**
   * Inicia (ou retoma) o pareamento. A resposta traz `qr_code` cru e
   * `qr_expires_at` enquanto a instância aguarda a leitura; instância já
   * conectada responde `connected` sem QR.
   */
  async function startPairing() {
    const res = await $api<{ data: WhatsAppChannel }>('/whatsapp/channel/pairing', { method: 'POST' })
    return res.data
  }

  /**
   * O polling do pareamento: `404` quando não há pareamento em curso — a tela
   * para o relógio nesse caso, porque um `disconnected` aqui faria esperar
   * por um QR que ninguém pediu.
   */
  async function pairingStatus() {
    const res = await $api<{ data: WhatsAppChannel }>('/whatsapp/channel/pairing')
    return res.data
  }

  /** Encerra a sessão no gateway e volta o canal para `disconnected`. */
  async function disconnect() {
    const res = await $api<{ data: WhatsAppChannel }>('/whatsapp/channel', { method: 'DELETE' })
    return res.data
  }

  async function continueOperation(operationId: string) {
    const res = await $api<{ data: WhatsAppChannel }>(`/whatsapp/channel/operations/${encodeURIComponent(operationId)}/continue`, { method: 'POST' })
    return res.data
  }

  async function reconcileOperation(operationId: string) {
    const res = await $api<{ data: WhatsAppChannel }>(`/whatsapp/channel/operations/${encodeURIComponent(operationId)}/reconcile`, { method: 'POST' })
    return res.data
  }

  return { channel, startPairing, pairingStatus, disconnect, continueOperation, reconcileOperation }
}
