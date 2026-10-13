import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { readFile } from 'node:fs/promises'
import type { MonitoringAssessmentPeriod } from '../app/types/serpro.ts'
import type {
  ClientDispatchContact,
  WhatsAppDispatch
} from '../app/types/whatsapp.ts'
import {
  whatsappContactPhoneLabel,
  whatsappDispatchContactName,
  whatsappDispatchContactValueLabel,
  whatsappGuideAlreadySent,
  whatsappGuideTarget,
  whatsappSendGuidesPayload,
  whatsappSendGuidesPlan,
  whatsappSendGuidesReasons
} from '../app/utils/whatsappDispatch.ts'

const period = (over: Partial<MonitoringAssessmentPeriod>): MonitoringAssessmentPeriod => ({
  period: '2026-09',
  declared_at: null,
  rectified: false,
  slip_number: null,
  slip_issued_at: null,
  due_on: null,
  slip_paid: null,
  ...over
})

const contact = (id: number, over: Partial<ClientDispatchContact> = {}): ClientDispatchContact => ({
  id,
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
  contact: null,
  status: 'delivered',
  message_id: null,
  failure_reason: null,
  dispatched_in_support: false,
  created_at: null,
  updated_at: null,
  ...over
})

describe('whatsappGuideTarget', () => {
  it('devolve o período mais recente com slip_number, com o número do DAS', () => {
    const target = whatsappGuideTarget([
      period({ period: '2026-08', slip_number: 'DAS-AGO' }),
      period({ period: '2026-09', slip_number: 'DAS-SET' })
    ])
    assert.deepEqual(target, { period: '2026-09', slipNumber: 'DAS-SET' })
  })

  it('aceita período declarado sem slip_number — a guia é gerada na hora (slipNumber null)', () => {
    const target = whatsappGuideTarget([
      period({ period: '2026-09', declared_at: '2026-10-04T12:00:00Z' })
    ])
    assert.deepEqual(target, { period: '2026-09', slipNumber: null })
  })

  it('pula o período mais recente sem guia e escolhe o anterior que tem', () => {
    const target = whatsappGuideTarget([
      period({ period: '2026-10' }),
      period({ period: '2026-09', slip_number: 'DAS-SET' })
    ])
    assert.deepEqual(target, { period: '2026-09', slipNumber: 'DAS-SET' })
  })

  it('devolve null quando nenhum período tem guia, e para lista ausente', () => {
    assert.equal(whatsappGuideTarget([period({ period: '2026-10' })]), null)
    assert.equal(whatsappGuideTarget([]), null)
    assert.equal(whatsappGuideTarget(null), null)
    assert.equal(whatsappGuideTarget(undefined), null)
  })
})

describe('whatsappGuideAlreadySent', () => {
  const target = { period: '2026-09', slipNumber: 'DAS-1' }
  const enviado = (over: Partial<WhatsAppDispatch> = {}) =>
    dispatch(1, { contact: contact(10), ...over })

  it('reconhece a guia já enviada ao contato (mesma competência e slip)', () => {
    assert.equal(whatsappGuideAlreadySent([enviado()], 10, target), true)
  })

  it('não conta disparo de outro contato', () => {
    assert.equal(whatsappGuideAlreadySent([enviado()], 11, target), false)
  })

  it('falha permanente não segura o contato — a nova tentativa é legítima', () => {
    assert.equal(whatsappGuideAlreadySent([enviado({ status: 'failed' })], 10, target), false)
  })

  it('recálculo libera o reenvio: slip_number novo não é a mesma guia', () => {
    assert.equal(whatsappGuideAlreadySent([enviado()], 10, { period: '2026-09', slipNumber: 'DAS-2' }), false)
  })

  it('outra competência não bloqueia', () => {
    assert.equal(whatsappGuideAlreadySent([enviado({ competence: '2026-08' })], 10, target), false)
  })

  it('a guia por declared_at casa slip null com slip null', () => {
    const semSlip = { period: '2026-09', slipNumber: null }
    assert.equal(whatsappGuideAlreadySent([enviado({ slip_number: null })], 10, semSlip), true)
    assert.equal(whatsappGuideAlreadySent([enviado()], 10, semSlip), false)
  })

  it('disparo legado sem contato (telefone da carteira) não bloqueia nenhum contato', () => {
    assert.equal(whatsappGuideAlreadySent([dispatch(1)], 10, target), false)
  })

  it('recibo e declaração não são guia — não bloqueiam o reenvio', () => {
    assert.equal(whatsappGuideAlreadySent([enviado({ artifact_type: 'recibo' })], 10, target), false)
    assert.equal(whatsappGuideAlreadySent([enviado({ artifact_type: 'declaracao' })], 10, target), false)
  })
})

describe('whatsappSendGuidesPlan', () => {
  const target = { period: '2026-09', slipNumber: 'DAS-1' }

  it('pré-seleciona o contato WhatsApp que ainda não recebeu a guia corrente', () => {
    const plan = whatsappSendGuidesPlan({ contacts: [contact(10)], dispatches: [], target })
    assert.equal(plan.length, 1)
    assert.deepEqual({ sendable: plan[0]!.sendable, checked: plan[0]!.checked, alreadySent: plan[0]!.alreadySent },
      { sendable: true, checked: true, alreadySent: false })
  })

  it('desmarca quem já recebeu a mesma guia e expõe a Ciência de reenvio', () => {
    const plan = whatsappSendGuidesPlan({
      contacts: [contact(10)],
      dispatches: [dispatch(1, { contact: contact(10) })],
      target
    })
    assert.equal(plan[0]!.checked, false)
    assert.equal(plan[0]!.alreadySent, true)
    assert.equal(plan[0]!.sendable, true)
  })

  it('contato de e-mail é listado mas nunca selecionável nesta versão', () => {
    const plan = whatsappSendGuidesPlan({
      contacts: [contact(11, { channel: 'email', value: 'socio@empresa.com' })],
      dispatches: [],
      target
    })
    assert.equal(plan[0]!.sendable, false)
    assert.equal(plan[0]!.checked, false)
    assert.ok(plan[0]!.blockedReason?.includes('e-mail'))
  })

  it('telefone fora do padrão não é selecionável e nomeia o motivo', () => {
    const plan = whatsappSendGuidesPlan({
      contacts: [contact(12, { value: '123' })],
      dispatches: [],
      target
    })
    assert.equal(plan[0]!.sendable, false)
    assert.equal(plan[0]!.checked, false)
    assert.ok(plan[0]!.blockedReason?.includes('telefone'))
  })

  it('sem guia corrente ninguém é marcado como já enviado', () => {
    const plan = whatsappSendGuidesPlan({
      contacts: [contact(10)],
      dispatches: [dispatch(1, { contact: contact(10) })],
      target: null
    })
    assert.equal(plan[0]!.alreadySent, false)
    assert.equal(plan[0]!.checked, true)
  })

  it('recálculo pré-seleciona de novo: slip novo não dispara o bloqueio', () => {
    const plan = whatsappSendGuidesPlan({
      contacts: [contact(10)],
      dispatches: [dispatch(1, { contact: contact(10), slip_number: 'DAS-VELHO' })],
      target
    })
    assert.equal(plan[0]!.alreadySent, false)
    assert.equal(plan[0]!.checked, true)
  })
})

describe('whatsappSendGuidesPayload', () => {
  const target = { period: '2026-09', slipNumber: 'DAS-1' }

  it('monta contact_ids dos marcados e resend_acknowledgements dos que têm ciência', () => {
    const plan = whatsappSendGuidesPlan({
      contacts: [contact(10), contact(11, { value: '11911112222' })],
      dispatches: [dispatch(1, { contact: contact(10) })],
      target
    })
    // 10 já recebeu (desmarcado), 11 pré-marcado. O operador marca o 10 e dá ciência.
    plan[0]!.checked = true
    const payload = whatsappSendGuidesPayload(plan, new Set([10]))
    assert.deepEqual(payload, { contact_ids: [10, 11], resend_acknowledgements: [10] })
  })

  it('ciência num contato desmarcado não viaja no request', () => {
    const plan = whatsappSendGuidesPlan({
      contacts: [contact(10), contact(11, { value: '11911112222' })],
      dispatches: [dispatch(1, { contact: contact(10) })],
      target
    })
    const payload = whatsappSendGuidesPayload(plan, new Set([10]))
    assert.deepEqual(payload, { contact_ids: [11], resend_acknowledgements: [] })
  })

  it('sem nenhum WhatsApp marcado devolve null — a validação é da tela, não da API', () => {
    const plan = whatsappSendGuidesPlan({
      contacts: [contact(10)],
      dispatches: [dispatch(1, { contact: contact(10) })],
      target
    })
    assert.equal(whatsappSendGuidesPayload(plan, new Set()), null)
    assert.equal(whatsappSendGuidesPayload([], new Set()), null)
  })

  it('o null do payload é o que dispara a frase de validação do modal — "zero marcados" é alcançável', () => {
    // O confirm() do SendGuidesModal mapeia `null` para a mensagem
    // `whatsappSendGuidesReasons.noneChecked` — se o botão estivesse
    // desabilitado nesse caso (checkedCount === 0), a frase seria
    // inalcançável e o operador veria só um botão cinza. Este teste fixa o
    // contrato: null = "mostre a validação", nunca "não deixe clicar".
    const plan = whatsappSendGuidesPlan({
      contacts: [contact(10)],
      dispatches: [dispatch(1, { contact: contact(10) })],
      target
    })
    // Todos desmarcados (o contato já recebeu a guia e o operador não deu ciência).
    assert.equal(whatsappSendGuidesPayload(plan, new Set()), null)
    assert.equal(whatsappSendGuidesReasons.noneChecked, 'Marque ao menos um Contato de envio WhatsApp para disparar.')
  })

  it('nunca envia contato bloqueado, mesmo que a entrada venha marcada à força', () => {
    const plan = whatsappSendGuidesPlan({
      contacts: [contact(11, { channel: 'email', value: 'socio@empresa.com' })],
      dispatches: [],
      target
    })
    plan[0]!.checked = true
    assert.equal(whatsappSendGuidesPayload(plan, new Set()), null)
  })
})

describe('whatsappContactPhoneLabel', () => {
  it('formata celular com DDD e fixo com DDD', () => {
    assert.equal(whatsappContactPhoneLabel('11987654321'), '(11) 98765-4321')
    assert.equal(whatsappContactPhoneLabel('1133445566'), '(11) 3344-5566')
  })

  it('mantém o DDI visível quando o telefone já vem em E.164 brasileiro', () => {
    assert.equal(whatsappContactPhoneLabel('5511987654321'), '+55 (11) 98765-4321')
    assert.equal(whatsappContactPhoneLabel('551133445566'), '+55 (11) 3344-5566')
  })

  it('devolve o valor cru quando não reconhece o padrão — não inventa máscara', () => {
    assert.equal(whatsappContactPhoneLabel('0800'), '0800')
  })
})

describe('apresentação do Contato de envio', () => {
  it('whatsappDispatchContactValueLabel formata WhatsApp e devolve e-mail cru', () => {
    assert.equal(whatsappDispatchContactValueLabel({ channel: 'whatsapp', value: '11987654321' }), '(11) 98765-4321')
    assert.equal(whatsappDispatchContactValueLabel({ channel: 'email', value: 'socio@empresa.com' }), 'socio@empresa.com')
  })

  it('whatsappDispatchContactName prefere o rótulo e cai no valor formatado', () => {
    assert.equal(whatsappDispatchContactName(contact(1, { label: 'Sócio 1' })), 'Sócio 1')
    assert.equal(whatsappDispatchContactName(contact(1)), '(11) 98765-4321')
    assert.equal(whatsappDispatchContactName(contact(1, { label: '   ' })), '(11) 98765-4321')
    assert.equal(whatsappDispatchContactName(contact(1, { channel: 'email', value: 'socio@empresa.com' })), 'socio@empresa.com')
  })
})

describe('contrato wire do Enviar guias e dos Contatos de envio', () => {
  const ler = (path: string) => readFile(new URL(path, import.meta.url), 'utf8')

  it('useSerpro endereça o send-guides batch com contact_ids + resend_acknowledgements', async () => {
    const code = await ler('../app/composables/useSerpro.ts')
    assert.match(code, /\/whatsapp\/send-guides/)
    assert.match(code, /contact_ids/)
    assert.match(code, /resend_acknowledgements/)
  })

  it('useDispatchContacts endereça o CRUD sob /clients/{id}/dispatch-contacts', async () => {
    const code = await ler('../app/composables/useDispatchContacts.ts')
    assert.match(code, /dispatch-contacts/)
    assert.match(code, /clients\/\$\{/)
    assert.match(code, /method: 'POST'/)
    assert.match(code, /method: 'PUT'/)
    assert.match(code, /method: 'DELETE'/)
  })
})
