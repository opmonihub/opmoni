/* eslint-disable @stylistic/max-statements-per-line -- Local renderer callbacks keep their host operations together. */
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import type { ErpFiscalDocument, ErpFiscalSale } from '../shared/types/erpFiscal.ts'

function frozenSale() {
  return {
    id: 37, status: 'posted', erp_establishment_id: 3, fiscal_model: '55' as '55' | '65' | null,
    commercial_snapshot: {
      version: 'commercial-v2', accepted: { actor_id: 91, at: '2026-10-24T12:00:00Z', draft_revision: 2 },
      fiscal_context: { version: 'sale-source-prerequisites-v1', model: '55', readiness: 'source_prerequisites_checked' },
      party: { tax_id: '12345678000195', name: 'Destinatário congelado' },
      lines: [{
        id: 31, erp_product_variant_id: 8, quantity: '2.0000', unit_price: '5.0000', gross_amount: '10.0000',
        product_snapshot: {
          version: 'commercial-v2', product: { id: 7, name: 'Produto congelado', ncm: '12345678', origin_code: '0', icms_cst: '00', pis_cst: '07', cofins_cst: '07' },
          variant: { sku: 'SKU-FROZEN' }, unit: { short_name: 'UN' },
          operation_nature: { id: 9, cfop: '5101', default_cfop: '5102', configuration_revision: 4 },
          cfop_resolution: { effective_cfop: '5101', default_cfop: '5102', source: 'product_exception', nature_id: 9, configuration_revision: 4, exception_id: 12 },
          product_tax_resolution: { version: 'product-tax-v1', profile: { id: 6, revision: 2 }, sources: { icms_cst: { source: 'profile', profile_id: 6, revision: 2, intent: 'inherit' } } }
        }
      }]
    }
  }
}

function formProps(sale: ErpFiscalSale | null = frozenSale()) {
  return {
    establishmentId: 3, model: '55', selectedSale: sale, sales: sale ? [sale] : [], returns: [], selectedReturn: null,
    originalDocuments: [], canCreate: true, busy: false, fieldErrors: {},
    configuration: {
      environment: 'homologation', models: { 55: { series: 1, next_number: 7, readiness: { status: 'locally_configured', blockers: [] } } },
      release_gates: { provider: 'unavailable', production_transmission_enabled: false }
    }
  }
}

type HostNode = { tag?: string, text?: string, style?: Record<string, string>, props: Record<string, unknown>, children: HostNode[], parent: HostNode | null }
function mountSurface(path: string, initial: Record<string, unknown>) {
  const input = Vue.reactive(initial)
  const events: Array<[string, unknown]> = []
  let requests = 0
  const component = compileVueComponent(path, { nextTick: Vue.nextTick, useAccessApi: () => { requests++; throw new Error('A frozen-source summary must not fetch current records') } })
  const root: HostNode = { tag: 'root', props: {}, children: [], parent: null }
  const renderer = Vue.createRenderer<HostNode>({
    patchProp(node, key, _old, value) { node.props[key] = value },
    insert(node, parent, anchor) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node); else parent.children.splice(index, 0, node)
    },
    remove(node) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); node.parent = null },
    createElement(tag) { return { tag, style: {}, props: {}, children: [], parent: null } },
    createText(text) { return { text, props: {}, children: [], parent: null } }, createComment(text) { return { text, props: {}, children: [], parent: null } },
    setText(node, text) { node.text = text }, setElementText(node, text) { node.text = text; node.children = [] },
    parentNode(node) { return node.parent }, nextSibling(node) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  const app = renderer.createApp(Vue.defineComponent({ setup: () => () => Vue.h(component, {
    ...input, 'onCreate': (body: unknown) => events.push(['create', body]), 'onSelectSale': (id: number) => events.push(['select-sale', id]),
    'onSelectReturn': (id: number) => events.push(['select-return', id]), 'onUpdate:model': (model: string) => events.push(['model', model])
  }) }))
  app.component('UForm', Vue.defineComponent({ emits: ['submit'], setup: (_props, ctx) => () => Vue.h('form', { onSubmit: () => ctx.emit('submit') }, ctx.slots.default?.()) }))
  app.component('UFormField', Vue.defineComponent({ props: { label: String, name: String, error: String }, setup: (props, ctx) => () => Vue.h('label', { 'data-field': props.name }, [props.label, ...(ctx.slots.default?.() ?? []), props.error]) }))
  app.component('UAlert', Vue.defineComponent({ props: { title: String, description: String }, setup: (props, ctx) => () => Vue.h('aside', [props.title, props.description, ...(ctx.slots.default?.() ?? [])]) }))
  app.component('UButton', Vue.defineComponent({ props: { disabled: Boolean, loading: Boolean }, setup: (props, ctx) => () => Vue.h('button', { ...ctx.attrs, disabled: props.disabled || props.loading }, ctx.slots.default?.()) }))
  app.component('UBadge', Vue.defineComponent({ setup: (_props, ctx) => () => Vue.h('span', ctx.slots.default?.()) }))
  for (const name of ['UInput', 'USelect', 'UTextarea']) app.component(name, Vue.defineComponent({
    props: { modelValue: [String, Number, Boolean], disabled: Boolean, readonly: Boolean }, emits: ['update:modelValue'],
    setup: (props, ctx) => () => Vue.h(name === 'USelect' ? 'select' : 'input', {
      value: props.modelValue, disabled: props.disabled, readonly: props.readonly,
      onChange: (event: { target: { value: string } }) => ctx.emit('update:modelValue', event.target.value)
    })
  }))
  app.mount(root)
  const walk = (node: HostNode): HostNode[] => [node, ...node.children.flatMap(walk)]
  const text = (node: HostNode = root): string => [node.text ?? '', ...node.children.map(text)].join(' ')
  const find = (predicate: (node: HostNode) => boolean, node: HostNode = root) => walk(node).filter(predicate)
  return {
    input, events, find, text, requests: () => requests, dispose: () => app.unmount(),
    async change(field: string, value: string) {
      const label = find(node => node.props['data-field'] === field)[0]
      assert.ok(label, `Expected actual fiscal field ${field}`)
      const control = find(node => node.tag === 'input' || node.tag === 'select', label)[0]
      assert.ok(control)
      ;(control.props.onChange as (event: unknown) => void)({ target: { value } })
      await Vue.nextTick()
    },
    async submit() {
      const form = find(node => node.tag === 'form')[0]
      assert.ok(form)
      ;(form.props.onSubmit as () => void)()
      await Vue.nextTick()
    }
  }
}
type Mounted = ReturnType<typeof mountSurface>
function sourceSummary(page: Mounted) {
  const node = page.find(node => node.props['aria-label'] === 'Fonte comercial da venda')[0]
  assert.ok(node, 'The actual fiscal form must expose the supplied commercial source summary')
  return node
}
function detailProps(document: ErpFiscalDocument) {
  return { document, canTransmit: false, canConsult: false, canQueue: false, canEnableContingency: false, canReconcile: false, canDownloadXml: false, canDownloadPdf: false }
}

// Individual cases remain bounded while serial SFC compilation has a separate total budget.
describe('fiscal surfaces show only supplied frozen commercial facts', { concurrency: 1, timeout: 120_000 }, () => {
  it('distinguishes frozen effective CFOP, saved default, NCM and resolution origins in the actual form', { timeout: 30_000 }, async (t) => {
    const sale = frozenSale()
    const original = JSON.stringify(sale)
    const page = mountSurface('components/erp/ErpFiscalDocumentForm.vue', formProps(sale)); t.after(page.dispose)
    await Vue.nextTick()
    const text = page.text(sourceSummary(page))
    assert.match(text, /Fonte comercial congelada/)
    assert.match(text, /Venda #37/)
    assert.match(text, /CFOP efetivo[^\d]*5101/)
    assert.match(text, /Padrão da natureza salvo[^\d]*5102/)
    assert.match(text, /NCM[^\d]*12345678/)
    assert.match(text, /Exceção por produto/)
    assert.match(text, /Herdado do perfil #6.*revisão 2/)
    assert.equal(JSON.stringify(sale), original)
    assert.deepEqual(page.events, [])
    assert.equal(page.requests(), 0)
  })

  it('labels checked commercial prerequisites without claiming readiness for authorization or filling issuance facts', { timeout: 30_000 }, async (t) => {
    const page = mountSurface('components/erp/ErpFiscalDocumentForm.vue', formProps()); t.after(page.dispose)
    await Vue.nextTick()
    const text = page.text(sourceSummary(page))
    assert.match(text, /Pré-requisitos da fonte comercial verificados no lançamento/)
    assert.match(text, /identidade fiscal.*inscrição do destinatário.*certificado.*valores tributários/)
    assert.match(text, /não representa autorização/)
    assert.doesNotMatch(text, /pront[ao] para (autoriza|transmi|emi)|todos os dados fiscais válidos/i)
    const indicator = page.find(node => node.props['data-field'] === 'recipient.state_registration_indicator')[0]
    assert.ok(indicator)
    assert.ok([undefined, ''].includes(page.find(node => node.tag === 'input', indicator)[0]?.props.value as string | undefined))
    assert.deepEqual(page.events, [])
  })

  it('retains complete single-CFOP legacy facts without inventing provenance or rejecting all v1 sources', { timeout: 30_000 }, async (t) => {
    const sale = frozenSale()
    sale.fiscal_model = null
    sale.commercial_snapshot.version = 'commercial-v1'
    const line = sale.commercial_snapshot.lines[0]!
    const { cfop_resolution: _cfop, product_tax_resolution: _tax, ...facts } = line.product_snapshot
    const historical = { ...sale, commercial_snapshot: { ...sale.commercial_snapshot, fiscal_context: { model: null, readiness: 'unverified' }, lines: [{ ...line, product_snapshot: { ...facts, version: 'commercial-v1', operation_nature: { id: 9, cfop: '5101' } } }] } }
    const page = mountSurface('components/erp/ErpFiscalDocumentForm.vue', formProps(historical)); t.after(page.dispose)
    await Vue.nextTick()
    const text = page.text(sourceSummary(page))
    assert.match(text, /5101/)
    assert.match(text, /12345678/)
    assert.match(text, /Metadados de resolução não disponíveis neste histórico/)
    assert.doesNotMatch(text, /Herdado do perfil|Exceção por produto|legado inválido|não pode ser emitid[ao]/i)
    assert.equal(page.find(node => node.tag === 'button' && page.text(node).includes('Preparar rascunho fiscal'))[0]?.props.disabled, false)
    assert.deepEqual(page.events, [])
    assert.equal(page.requests(), 0)
  })

  it('exposes absent acceptance and missing historical classification as unknown without repairing the source', { timeout: 30_000 }, async (t) => {
    const sale = frozenSale()
    const { accepted: _accepted, ...snapshot } = sale.commercial_snapshot
    const incomplete = { ...sale, commercial_snapshot: { ...snapshot, lines: [] } }
    const page = mountSurface('components/erp/ErpFiscalDocumentForm.vue', formProps(incomplete)); t.after(page.dispose)
    await Vue.nextTick()
    const text = page.text(sourceSummary(page))
    assert.match(text, /sem confirmação de snapshot aceito/i)
    assert.doesNotMatch(text, /Fonte comercial congelada|Pré-requisitos da fonte comercial verificados/)
    const accepted = frozenSale()
    const line = accepted.commercial_snapshot.lines[0]!
    const { ncm: _ncm, ...product } = line.product_snapshot.product
    page.input.selectedSale = { ...accepted, commercial_snapshot: { ...accepted.commercial_snapshot, lines: [{ ...line, product_snapshot: { ...line.product_snapshot, product } }] } }
    await Vue.nextTick()
    assert.match(page.text(sourceSummary(page)), /NCM[^\d]*Não informado/)
    assert.doesNotMatch(page.text(sourceSummary(page)), /12345678/)
    assert.equal(page.requests(), 0)
    assert.deepEqual(page.events, [])
  })

  it('keeps explicit null, absent intent and a model mismatch visibly distinct without changing saved intent', { timeout: 30_000 }, async (t) => {
    const sale = frozenSale(); sale.fiscal_model = null
    const page = mountSurface('components/erp/ErpFiscalDocumentForm.vue', formProps(sale)); t.after(page.dispose)
    await Vue.nextTick()
    assert.match(page.text(sourceSummary(page)), /Sem intenção fiscal declarada/)
    const { fiscal_model: _intent, ...unknownIntent } = frozenSale()
    page.input.selectedSale = unknownIntent; await Vue.nextTick()
    assert.match(page.text(sourceSummary(page)), /Intenção fiscal não informada neste histórico/)
    page.input.selectedSale = frozenSale(); page.input.model = '65'; await Vue.nextTick()
    assert.match(page.text(sourceSummary(page)), /Modelo selecionado para emissão difere da intenção fiscal salva/)
    assert.equal((page.input.selectedSale as ReturnType<typeof frozenSale>).fiscal_model, '55')
    assert.deepEqual(page.events, [])
  })

  it('clears the previous source facts on selected-sale replacement and null without emitting an operation', { timeout: 30_000 }, async (t) => {
    const page = mountSurface('components/erp/ErpFiscalDocumentForm.vue', formProps()); t.after(page.dispose)
    await Vue.nextTick()
    assert.match(page.text(sourceSummary(page)), /12345678/)
    const next = frozenSale(); next.id = 44; next.commercial_snapshot.lines[0]!.product_snapshot.product.ncm = '87654321'
    page.input.selectedSale = next; await Vue.nextTick()
    assert.match(page.text(sourceSummary(page)), /Venda #44/)
    assert.doesNotMatch(page.text(sourceSummary(page)), /12345678|Venda #37/)
    page.input.selectedSale = null; await Vue.nextTick()
    assert.equal(page.find(node => node.props['aria-label'] === 'Fonte comercial da venda').length, 0)
    assert.doesNotMatch(page.text(), /87654321/)
    assert.deepEqual(page.events, [])
    assert.equal(page.requests(), 0)
  })

  it('does not attribute a selected sale summary to returns or typed transport models', { timeout: 30_000 }, async (t) => {
    const page = mountSurface('components/erp/ErpFiscalDocumentForm.vue', formProps()); t.after(page.dispose)
    await Vue.nextTick()
    sourceSummary(page)
    await page.change('document_kind', 'return')
    assert.equal(page.find(node => node.props['aria-label'] === 'Fonte comercial da venda').length, 0)
    for (const model of ['57', '58']) {
      page.input.model = model; await Vue.nextTick()
      assert.equal(page.find(node => node.props['aria-label'] === 'Fonte comercial da venda').length, 0)
    }
    assert.equal(page.events.filter(([event]) => event === 'create').length, 0)
  })

  it('leaves fiscal creation, frozen amounts and the existing idempotency identity under explicit operator submission', { timeout: 30_000 }, async (t) => {
    const sale = frozenSale()
    const original = JSON.stringify(sale)
    const page = mountSurface('components/erp/ErpFiscalDocumentForm.vue', formProps(sale)); t.after(page.dispose)
    await Vue.nextTick()
    assert.deepEqual(page.events, [])
    const facts = {
      'identity.operation_type': '1', 'identity.destination_type': '1', 'identity.final_consumer': '1', 'identity.presence_indicator': '1', 'identity.print_type': '1',
      'recipient.state_registration_indicator': '9', 'recipient.address.street': 'Rua Real', 'recipient.address.number': '10', 'recipient.address.district': 'Centro',
      'recipient.address.city_code': '3550308', 'recipient.address.city': 'São Paulo', 'recipient.address.postal_code': '01001000', 'recipient.address.uf': 'SP',
      'freight.modality': '9', 'payment.0.method': '01', 'payment.0.amount': '10.00', 'items.0.ind_total': '1',
      'items.0.tax.icms_base': '10.00', 'items.0.tax.icms_rate': '18.0000', 'items.0.tax.icms_value': '1.80', 'items.0.tax.pis_value': '0.00', 'items.0.tax.cofins_value': '0.00'
    }
    for (const [field, value] of Object.entries(facts)) await page.change(field, value)
    for (const field of page.find(node => typeof node.props['data-field'] === 'string' && node.props['data-field'].startsWith('source.totals.'))) {
      const name = String(field.props['data-field'])
      await page.change(name, ['source.totals.products', 'source.totals.invoice'].includes(name) ? '10.00' : '0.00')
    }
    await page.submit(); await page.submit()
    const bodies = page.events.filter(([event]) => event === 'create').map(([, body]) => body as Record<string, unknown>)
    assert.equal(bodies.length, 2)
    assert.deepEqual(bodies[1], bodies[0])
    assert.equal(bodies[0]?.erp_sale_id, 37)
    assert.ok(typeof bodies[0]?.idempotency_key === 'string' && bodies[0].idempotency_key)
    const source = bodies[0]?.source as { items: Array<Record<string, unknown>> }
    assert.deepEqual(Object.fromEntries(['source_line_id', 'cfop', 'ncm', 'quantity', 'unit_price', 'gross_total', 'unit'].map(key => [key, source.items[0]?.[key]])), {
      source_line_id: 31, cfop: '5101', ncm: '12345678', quantity: '2.0000', unit_price: '5.0000', gross_total: '10.00', unit: 'UN'
    })
    assert.equal(JSON.stringify(sale), original)
    assert.equal(page.requests(), 0)
  })

  it('shows only the linked-sale identity in fiscal detail and preserves uncertain and local-contingency status labels', { timeout: 30_000 }, async (t) => {
    const document: ErpFiscalDocument = { id: 61, erp_sale_id: 37, model: '65', environment: 'homologation', status: 'indeterminate', artifact_ready: false, claims_provider_authorization: false }
    const page = mountSurface('components/erp/ErpFiscalDocumentDetail.vue', detailProps(document)); t.after(page.dispose)
    await Vue.nextTick()
    assert.match(page.text(), /Origem comercial: venda #37/)
    assert.match(page.text(), /Os fatos comerciais da venda não são exibidos neste detalhe/)
    assert.match(page.text(), /Resultado incerto/)
    assert.doesNotMatch(page.text(), /Fonte comercial congelada|NCM|Herdado do perfil|Pré-requisitos da fonte comercial verificados/)
    page.input.document = { ...document, status: 'contingency_local', contingency: { mode: 'offline' } }; await Vue.nextTick()
    assert.match(page.text(), /Contingência local/)
    assert.match(page.text(), /não representa autorização do provedor/)
    assert.deepEqual(page.events, [])
    assert.equal(page.requests(), 0)
  })

  it('keeps actual authorization separate from unknown source provenance and removes an absent linked-sale identity', { timeout: 30_000 }, async (t) => {
    const document: ErpFiscalDocument = { id: 61, erp_sale_id: 37, model: '55', environment: 'homologation', status: 'authorized', artifact_ready: true, claims_provider_authorization: true }
    const page = mountSurface('components/erp/ErpFiscalDocumentDetail.vue', detailProps(document)); t.after(page.dispose)
    await Vue.nextTick()
    assert.match(page.text(), /Origem comercial: venda #37/)
    assert.match(page.text(), /Autorizado/)
    assert.match(page.text(), /O vínculo não confirma autorização fiscal/)
    assert.doesNotMatch(page.text(), /12345678|5101|Exceção por produto|Pré-requisitos da fonte comercial verificados/)
    page.input.document = { ...document, erp_sale_id: null }; await Vue.nextTick()
    assert.doesNotMatch(page.text(), /Origem comercial: venda #37/)
    assert.match(page.text(), /Autorizado/)
    assert.deepEqual(page.events, [])
    assert.equal(page.requests(), 0)
  })
})
