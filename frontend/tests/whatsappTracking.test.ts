import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import type { MonitoringAssessmentPeriod } from '../app/types/serpro.ts'
import type { ClientDispatchContact, WhatsAppDispatch, WhatsAppDispatchStatus } from '../app/types/whatsapp.ts'
import {
  whatsappDispatchableObligationSlugs,
  whatsappDispatchDestinationLabel,
  whatsappDispatchTrackingTab,
  whatsappSheetGuideDownloadTarget,
  whatsappSheetSendAvailability,
  whatsappSheetSendUnavailableReason,
  whatsappTrackingCardText,
  whatsappTrackingGroups,
  whatsappTrackingTabLabels
} from '../app/utils/whatsappDispatch.ts'

const period = (over: Partial<MonitoringAssessmentPeriod> = {}): MonitoringAssessmentPeriod => ({
  period: '2026-09',
  declared_at: null,
  rectified: false,
  slip_number: null,
  slip_issued_at: null,
  due_on: null,
  slip_paid: null,
  ...over
})

const contact = (over: Partial<ClientDispatchContact> = {}): ClientDispatchContact => ({
  id: 3,
  client_id: 7,
  label: null,
  channel: 'whatsapp',
  value: '11987654321',
  created_at: null,
  updated_at: null,
  ...over
})

const dispatch = (id: number, over: Partial<WhatsAppDispatch> = {}): WhatsAppDispatch => ({
  id,
  client_id: 7,
  obligation: 'declaracoes/pgdas',
  artifact_type: 'guia',
  competence: '2026-09',
  slip_number: 'DAS-1',
  contact: contact(),
  status: 'delivered',
  message_id: null,
  failure_reason: null,
  dispatched_in_support: false,
  created_at: null,
  updated_at: null,
  ...over
})

describe('whatsappSheetSendAvailability', () => {
  it('libera a célula Enviar só na obrigação que serve Disparo (PGDAS)', () => {
    assert.equal(whatsappSheetSendAvailability({ slug: 'declaracoes/pgdas' }).available, true)
    assert.equal(whatsappSheetSendAvailability({ slug: 'declaracoes/pgdas' }).reason, null)
  })

  it('desabilita as demais servidas com o motivo legível, nunca um código', () => {
    for (const slug of ['simples-nacional/das', 'declaracoes/dctfweb', 'parcelamentos/simples']) {
      const cell = whatsappSheetSendAvailability({ slug })
      assert.equal(cell.available, false)
      assert.equal(cell.reason, whatsappSheetSendUnavailableReason)
      assert.match(cell.reason!, /PGDAS/)
    }
  })

  it('a lista do Disparo é a do PGDAS hoje — um slug só', () => {
    assert.deepEqual([...whatsappDispatchableObligationSlugs], ['declaracoes/pgdas'])
  })
})

describe('whatsappSheetGuideDownloadTarget', () => {
  it('devolve a competência da guia corrente quando a linha tem guia baixável', () => {
    const periods = [
      period({ period: '2026-08', slip_number: 'DAS-8' }),
      period({ period: '2026-09', slip_number: 'DAS-9' })
    ]
    assert.equal(whatsappSheetGuideDownloadTarget(periods), '2026-09')
  })

  it('aceita a guia por declaração transmitida sem slip_number', () => {
    const periods = [period({ period: '2026-09', declared_at: '2026-10-05T00:00:00Z' })]
    assert.equal(whatsappSheetGuideDownloadTarget(periods), '2026-09')
  })

  it('devolve null sem guia corrente — a seta fica desabilitada, não some', () => {
    assert.equal(whatsappSheetGuideDownloadTarget([period({ period: '2026-09' })]), null)
    assert.equal(whatsappSheetGuideDownloadTarget(null), null)
    assert.equal(whatsappSheetGuideDownloadTarget(undefined), null)
  })
})

describe('whatsappDispatchTrackingTab', () => {
  it('Envio agrupa os estados em trânsito (queued, sent, delivered)', () => {
    assert.equal(whatsappDispatchTrackingTab({ status: 'queued' }), 'envio')
    assert.equal(whatsappDispatchTrackingTab({ status: 'sent' }), 'envio')
    assert.equal(whatsappDispatchTrackingTab({ status: 'delivered' }), 'envio')
  })

  it('Visualização e Não enviados são desfechos com aba própria', () => {
    assert.equal(whatsappDispatchTrackingTab({ status: 'read' }), 'visualizacao')
    assert.equal(whatsappDispatchTrackingTab({ status: 'failed' }), 'nao_enviados')
  })

  it('cobre os cinco status do rastreio, nem mais nem menos', () => {
    const statuses: WhatsAppDispatchStatus[] = ['queued', 'sent', 'delivered', 'read', 'failed']
    for (const status of statuses) {
      assert.ok(['envio', 'visualizacao', 'nao_enviados'].includes(whatsappDispatchTrackingTab({ status })))
    }
  })
})

describe('whatsappTrackingGroups', () => {
  it('separa os disparos nas três abas, preservando a ordem da lista', () => {
    const list = [
      dispatch(9, { status: 'read' }),
      dispatch(8, { status: 'delivered' }),
      dispatch(7, { status: 'failed' }),
      dispatch(6, { status: 'queued' }),
      dispatch(5, { status: 'read' })
    ]
    const groups = whatsappTrackingGroups(list)
    assert.deepEqual(groups.envio.map(item => item.id), [8, 6])
    assert.deepEqual(groups.visualizacao.map(item => item.id), [9, 5])
    assert.deepEqual(groups.nao_enviados.map(item => item.id), [7])
  })

  it('sem disparos as três abas ficam vazias — o painel lê zero', () => {
    const groups = whatsappTrackingGroups([])
    assert.deepEqual(groups.envio, [])
    assert.deepEqual(groups.visualizacao, [])
    assert.deepEqual(groups.nao_enviados, [])
  })
})

describe('whatsappDispatchDestinationLabel', () => {
  it('o cartão mostra o telefone mascarado do Contato de envio', () => {
    assert.equal(whatsappDispatchDestinationLabel(dispatch(1)), '(11) 98765-4321')
  })

  it('contato removido ou disparo legado não mostra número cru', () => {
    assert.equal(whatsappDispatchDestinationLabel(dispatch(1, { contact: null })), 'Contato de envio removido')
  })
})

describe('vocabulário do painel', () => {
  it('as abas são Envio, Visualização e Não enviados', () => {
    assert.equal(whatsappTrackingTabLabels.envio, 'Envio')
    assert.equal(whatsappTrackingTabLabels.visualizacao, 'Visualização')
    assert.equal(whatsappTrackingTabLabels.nao_enviados, 'Não enviados')
  })

  it('o texto fixo do cartão é o do escritório, verbatim da spec', () => {
    assert.equal(whatsappTrackingCardText, 'Envio de guias realizado por WhatsApp.')
  })
})
