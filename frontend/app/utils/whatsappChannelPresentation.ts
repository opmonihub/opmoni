import type { WhatsAppChannelStatus } from '~/types/whatsapp'

/**
 * As apresentações dos quatro estados do Canal WhatsApp. O rótulo é a mesma
 * palavra em qualquer lugar da tela (badge, descrição, toast), e a cor segue
 * os papéis semânticos do Nuxt: conectado = success, pareando = warning,
 * erro = error, desconectado = neutral.
 */
export const whatsappChannelStatusPresentation: Record<WhatsAppChannelStatus, { label: string, color: 'neutral' | 'warning' | 'success' | 'error' }> = {
  disconnected: { label: 'Desconectado', color: 'neutral' },
  pairing: { label: 'Pareando', color: 'warning' },
  connected: { label: 'Conectado', color: 'success' },
  error: { label: 'Erro', color: 'error' }
}

/**
 * O que cada estado pede do operador, na frase do card. `null` nos estados que
 * dispensam explicação — a tela não inventa texto para o estado feliz.
 */
export const whatsappChannelStatusNote: Record<WhatsAppChannelStatus, string | null> = {
  disconnected: null,
  pairing: 'Abra o WhatsApp no celular do escritório e leia o código em Aparelhos conectados.',
  connected: null,
  error: 'O gateway informou uma falha. Inicie um novo pareamento para tentar de novo.'
}

/** O intervalo do polling de pareamento — decisão de UX do design. */
export const whatsappPairingPollMs = 4000

/**
 * O `qr_code` do whatsmeow é desenhável quando vem como código cru de
 * pareamento. O formato antigo começa com `2@`; o atual é a URL
 * `https://wa.me/settings/linked_devices#2@…`. Data-URL e string vazia não
 * são código — a tela não abre o modal com imagem pronta nem com resto de
 * sessão.
 */
export function whatsappQrScannable(code: string | null | undefined): code is string {
  if (typeof code !== 'string') return false
  const trimmed = code.trim()
  if (trimmed === '' || trimmed.startsWith('data:')) return false
  return trimmed.startsWith('2@') || trimmed.includes('#2@')
}
