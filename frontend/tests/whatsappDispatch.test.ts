import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { readFile } from 'node:fs/promises'
import type { WhatsAppChannelStatus, WhatsAppDispatch, WhatsAppDispatchStatus } from '../app/types/whatsapp.ts'
import {
  whatsappArtifactLabels,
  whatsappDispatchEligibility,
  whatsappDispatchStatusPresentation,
  whatsappLatestDispatch,
  whatsappRowSummaryPresentation
} from '../app/utils/whatsappDispatch.ts'

const statuses: WhatsAppDispatchStatus[] = ['queued', 'sent', 'delivered', 'read', 'failed']
const channelStatuses: WhatsAppChannelStatus[] = ['disconnected', 'pairing', 'connected', 'error']

const base = {
  phone: '11987654321',
  channelStatus: 'connected' as const,
  artifactAvailable: true,
  role: 'admin' as const
}

describe('whatsappDispatchEligibility', () => {
  it('libera o disparo quando telefone, canal e artefato estão ok', () => {
    assert.deepEqual(whatsappDispatchEligibility(base), { eligible: true, pending: false, reason: null })
    assert.deepEqual(whatsappDispatchEligibility({ ...base, role: 'operador' }), { eligible: true, pending: false, reason: null })
  })

  it('nega o disparo para o papel user, que só lê o rastreio', () => {
    const result = whatsappDispatchEligibility({ ...base, role: 'user' })
    assert.equal(result.eligible, false)
    assert.ok(result.reason?.includes('papel') || result.reason?.includes('administrador') || result.reason?.includes('operador'))
  })

  it('nega o disparo sem telefone cadastrado, com o motivo em pt-BR', () => {
    // `undefined` não entra: é "ainda lendo", coberto pelo teste do pending.
    for (const phone of [null, '', '   ']) {
      const result = whatsappDispatchEligibility({ ...base, phone })
      assert.equal(result.eligible, false, `phone=${String(phone)}`)
      assert.equal(result.pending, false)
      assert.ok(result.reason?.includes('telefone'), `motivo sem telefone: ${result.reason}`)
    }
  })

  it('nega o disparo para telefone fora do padrão BR-plausível (10 a 13 dígitos)', () => {
    for (const phone of ['123', '1234567890123456', 'abc']) {
      const result = whatsappDispatchEligibility({ ...base, phone })
      assert.equal(result.eligible, false, `phone=${phone}`)
      assert.ok(result.reason?.includes('telefone'))
    }
    // Aceita fixo com DDD, celular com nono dígito e formato E.164.
    assert.equal(whatsappDispatchEligibility({ ...base, phone: '1133445566' }).eligible, true)
    assert.equal(whatsappDispatchEligibility({ ...base, phone: '(11) 98765-4321' }).eligible, true)
    assert.equal(whatsappDispatchEligibility({ ...base, phone: '5511987654321' }).eligible, true)
    assert.equal(whatsappDispatchEligibility({ ...base, phone: '+55 11 98765-4321' }).eligible, true)
  })

  it('nega o disparo com o canal em qualquer estado que não connected', () => {
    for (const channelStatus of ['disconnected', 'pairing', 'error', null] as const) {
      const result = whatsappDispatchEligibility({ ...base, channelStatus })
      assert.equal(result.eligible, false, `channelStatus=${String(channelStatus)}`)
      assert.ok(result.reason !== null)
    }
  })

  it('nega o disparo quando o artefato não existe para a competência', () => {
    const result = whatsappDispatchEligibility({ ...base, artifactAvailable: false })
    assert.equal(result.eligible, false)
    assert.ok(result.reason?.includes('artefato') || result.reason?.includes('documento'))
  })

  it('nomeia o primeiro bloqueio na ordem canal → papel → telefone → artefato', () => {
    const result = whatsappDispatchEligibility({
      phone: null,
      channelStatus: 'disconnected',
      artifactAvailable: false,
      role: 'user'
    })
    assert.equal(result.eligible, false)
    // Canal vem primeiro: é o estado do escritório, não o dado do cliente.
    assert.ok(result.reason?.toLowerCase().includes('canal'), `motivo deveria ser do canal: ${result.reason}`)
  })

  it('aceita channelStatus null como "sem canal pareado"', () => {
    const result = whatsappDispatchEligibility({ ...base, channelStatus: null })
    assert.equal(result.eligible, false)
    assert.ok(result.reason !== null)
  })

  it('distingue "carregando" de "sem canal": channelStatus undefined não é bloqueio concreto', () => {
    const result = whatsappDispatchEligibility({ ...base, channelStatus: undefined })
    assert.equal(result.eligible, false)
    assert.equal(result.pending, true)
    // Motivo neutro: a leitura ainda não respondeu, não há conclusão a nomear.
    assert.equal(result.reason, null)
  })

  it('telefone undefined é "ainda lendo", não "sem telefone"', () => {
    const result = whatsappDispatchEligibility({ ...base, phone: undefined })
    assert.equal(result.eligible, false)
    assert.equal(result.pending, true)
    assert.equal(result.reason, null)
    // O `null` depois do GET é que nomeia o bloqueio concreto.
    const concluido = whatsappDispatchEligibility({ ...base, phone: null })
    assert.equal(concluido.pending, false)
    assert.ok(concluido.reason !== null)
  })

  it('canal confirmado ausente (null) resolve o motivo "sem canal", não o pendente', () => {
    const result = whatsappDispatchEligibility({ ...base, channelStatus: null, phone: undefined })
    assert.equal(result.eligible, false)
    assert.equal(result.pending, false)
    assert.ok(result.reason?.includes('Canal'))
  })

  it('pending prevalece sobre telefone carregando mas não sobre bloqueio concreto do canal', () => {
    // Os dois pendentes: nenhum motivo concreto ainda.
    const ambos = whatsappDispatchEligibility({ ...base, channelStatus: undefined, phone: undefined })
    assert.equal(ambos.pending, true)
    // Canal ok + telefone lendo: pendente (o papel e o canal já provaram-se).
    const telefoneLendo = whatsappDispatchEligibility({ ...base, channelStatus: 'connected', phone: undefined })
    assert.equal(telefoneLendo.pending, true)
    // Canal lendo + papel user: o bloqueio do papel ainda depende do canal?
    // Não — o papel é verificável sem o canal, mas a ordem do bloqueio é a do
    // operador: canal pendente primeiro.
    const papelComCanalPendente = whatsappDispatchEligibility({ ...base, channelStatus: undefined, role: 'user' })
    assert.equal(papelComCanalPendente.pending, true)
  })

  it('super_admin em suporte dispara como admin', () => {
    assert.equal(whatsappDispatchEligibility({ ...base, role: 'super_admin' }).eligible, true)
  })
})

describe('whatsappDispatchStatusPresentation', () => {
  it('cobre os cinco estados do rastreio, nem mais nem menos', () => {
    assert.deepEqual(Object.keys(whatsappDispatchStatusPresentation).sort(), [...statuses].sort())
  })

  it('dá rótulo em português e cor semântica para cada estado', () => {
    assert.deepEqual(whatsappDispatchStatusPresentation.queued, { label: 'Enfileirado', color: 'neutral', icon: 'i-lucide-clock' })
    assert.equal(whatsappDispatchStatusPresentation.sent.label, 'Enviado')
    assert.equal(whatsappDispatchStatusPresentation.delivered.label, 'Entregue')
    assert.equal(whatsappDispatchStatusPresentation.read.label, 'Lido')
    assert.equal(whatsappDispatchStatusPresentation.failed.label, 'Falhou')
    assert.equal(whatsappDispatchStatusPresentation.failed.color, 'error')
    assert.equal(whatsappDispatchStatusPresentation.read.color, 'success')
  })

  it('não rotula estados em inglês', () => {
    for (const status of statuses) {
      const { label } = whatsappDispatchStatusPresentation[status]
      assert.notEqual(label, status)
      assert.match(label, /^[A-ZÀ-Ú]/)
    }
  })
})

describe('whatsappLatestDispatch', () => {
  const d = (id: number, artifact: WhatsAppDispatch['artifact_type'], status: WhatsAppDispatchStatus): WhatsAppDispatch => ({
    id,
    client_id: 7,
    obligation: 'declaracoes/pgdas',
    artifact_type: artifact,
    competence: '2026-08',
    status,
    message_id: null,
    failure_reason: null,
    dispatched_in_support: false,
    created_at: '2026-10-04T12:00:00Z',
    updated_at: '2026-10-04T12:00:00Z'
  })

  it('devolve o disparo mais recente do artefato pedido', () => {
    const dispatches = [d(1, 'guia', 'sent'), d(3, 'guia', 'read'), d(2, 'recibo', 'delivered')]
    const latest = whatsappLatestDispatch(dispatches, 'guia')
    assert.equal(latest?.id, 3)
    assert.equal(whatsappLatestDispatch(dispatches, 'recibo')?.id, 2)
  })

  it('devolve null quando o artefato nunca foi disparado', () => {
    assert.equal(whatsappLatestDispatch([d(1, 'guia', 'sent')], 'declaracao'), null)
    assert.equal(whatsappLatestDispatch([], 'guia'), null)
  })
})

describe('whatsappArtifactLabels', () => {
  it('nomeia guia, recibo e declaração em português', () => {
    assert.equal(whatsappArtifactLabels.guia, 'guia (DAS)')
    assert.equal(whatsappArtifactLabels.recibo, 'recibo')
    assert.equal(whatsappArtifactLabels.declaracao, 'declaração')
  })
})

describe('whatsappRowSummaryPresentation', () => {
  it('devolve a apresentação do último status quando o resumo existe', () => {
    const summary = { latest_status: 'delivered' as const, latest_at: '2026-10-04T12:00:00Z', count: 3 }
    const presentation = whatsappRowSummaryPresentation(summary)
    assert.equal(presentation.label, 'Entregue')
    assert.equal(presentation.color, 'info')
  })

  it('um envio não diz "3 disparos" — o selo nomeia o estado, o rastreio detalha', () => {
    const summary = { latest_status: 'failed' as const, latest_at: null, count: 1 }
    const presentation = whatsappRowSummaryPresentation(summary)
    assert.equal(presentation.label, 'Falhou')
    assert.equal(presentation.color, 'error')
    assert.equal(presentation.label.includes('1'), false)
  })

  it('cobre os cinco estados sem inventar um sexto', () => {
    for (const status of statuses) {
      const summary = { latest_status: status, latest_at: null, count: 1 }
      assert.equal(whatsappRowSummaryPresentation(summary).label, whatsappDispatchStatusPresentation[status].label)
    }
  })
})

describe('contrato wire do useSerpro (Disparo e Rastreio)', () => {
  /**
   * O composable não roda no `node --test` sem runtime Nuxt, então o contrato
   * é lido do próprio arquivo: as rotas e o corpo do POST precisam bater com
   * `WhatsAppDispatchController` — qualquer drift aqui quebra a integração.
   */
  async function source() {
    return readFile(new URL('../app/composables/useSerpro.ts', import.meta.url), 'utf8')
  }

  it('POST de Disparo endereça a rota por linha e envia competence + artifact', async () => {
    const code = await source()
    assert.match(code, /\/whatsapp\/dispatch/)
    assert.match(code, /method: 'POST'/)
    assert.match(code, /body: \{ competence, artifact \}/)
  })

  it('GET de Rastreio manda competence e aceita artefato opcional', async () => {
    const code = await source()
    assert.match(code, /\/whatsapp\/dispatches/)
    assert.match(code, /queryOf\(\{ competence, artifact \}\)/)
  })

  it('o payload do dispatch carrega os campos que a UI lê, sem telefone', () => {
    const dispatch: WhatsAppDispatch = {
      id: 1,
      client_id: 7,
      obligation: 'declaracoes/pgdas',
      artifact_type: 'guia',
      competence: '2026-08',
      status: 'queued',
      message_id: null,
      failure_reason: null,
      dispatched_in_support: false,
      created_at: null,
      updated_at: null
    }
    // O contrato de leitura: a UI não tem campo `phone` para renderizar.
    assert.equal('phone' in dispatch, false)
    assert.equal(dispatch.status, 'queued')
  })

  it('os estados do rastreio são os cinco do backend', () => {
    const valid: WhatsAppDispatchStatus[] = ['queued', 'sent', 'delivered', 'read', 'failed']
    assert.equal(statuses.length, 5)
    assert.deepEqual([...valid].sort(), [...statuses].sort())
  })

  it('os estados do canal são os quatro espelhados', () => {
    assert.equal(channelStatuses.length, 4)
  })
})
