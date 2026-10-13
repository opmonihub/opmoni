import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import type { WhatsAppChannel, WhatsAppChannelStatus } from '../app/types/whatsapp.ts'
import {
  whatsappChannelStatusNote,
  whatsappChannelStatusPresentation,
  whatsappPairingPollMs,
  whatsappQrScannable
} from '../app/utils/whatsappChannelPresentation.ts'

const statuses: WhatsAppChannelStatus[] = ['disconnected', 'pairing', 'connected', 'error']

describe('useWhatsAppChannel (contrato do canal)', () => {
  it('cobre os quatro estados espelhados do canal, nem mais nem menos', () => {
    assert.deepEqual(
      Object.keys(whatsappChannelStatusPresentation).sort(),
      [...statuses].sort()
    )
  })

  it('dá cor semântica e rótulo em português para cada estado', () => {
    assert.deepEqual(whatsappChannelStatusPresentation.connected, { label: 'Conectado', color: 'success' })
    assert.deepEqual(whatsappChannelStatusPresentation.pairing, { label: 'Pareando', color: 'warning' })
    assert.deepEqual(whatsappChannelStatusPresentation.error, { label: 'Erro', color: 'error' })
    assert.deepEqual(whatsappChannelStatusPresentation.disconnected, { label: 'Desconectado', color: 'neutral' })
  })

  it('não rotula estados em inglês nem fora do vocabulário do produto', () => {
    for (const status of statuses) {
      const { label } = whatsappChannelStatusPresentation[status]
      assert.notEqual(label, status, `o estado ${status} virou o próprio rótulo`)
      assert.match(label, /^[A-ZÀ-Ú]/, `o rótulo de ${status} não começa maiúsculo`)
    }
  })

  it('só explica o que pede ação: pareando e erro, nunca o estado feliz', () => {
    assert.equal(whatsappChannelStatusNote.connected, null)
    assert.equal(whatsappChannelStatusNote.disconnected, null)
    assert.ok(whatsappChannelStatusNote.pairing?.includes('WhatsApp'))
    assert.ok(whatsappChannelStatusNote.error?.includes('pareamento'))
  })

  it('mantém o intervalo de polling do pareamento dentro de 3 a 5 segundos', () => {
    assert.ok(whatsappPairingPollMs >= 3000)
    assert.ok(whatsappPairingPollMs <= 5000)
  })

  it('reconhece o código cru do whatsmeow, no prefixo 2@ ou na URL de aparelhos', () => {
    assert.equal(whatsappQrScannable('2@abc123'), true)
    assert.equal(whatsappQrScannable('https://wa.me/settings/linked_devices#2@abc123,ref,noise'), true)
    assert.equal(whatsappQrScannable(''), false)
    assert.equal(whatsappQrScannable('   '), false)
    assert.equal(whatsappQrScannable(null), false)
    assert.equal(whatsappQrScannable('data:image/png;base64,xxx'), false)
    assert.equal(whatsappQrScannable('data:image/png;base64,#2@nao-e-codigo'), false)
  })

  it('declara o payload do canal com os campos que a API devolve', () => {
    const channel: WhatsAppChannel = {
      status: 'pairing',
      qr_code: '2@qr-do-teste',
      qr_expires_at: '2026-10-04T12:00:00Z',
      whatsapp_jid: null,
      last_error: null,
      connected_at: null,
      pairing_started_at: '2026-10-04T11:59:00Z'
    }
    assert.equal(channel.status, 'pairing')
  })
})
