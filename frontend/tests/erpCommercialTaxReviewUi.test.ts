/* eslint-disable @stylistic/max-statements-per-line -- Local Vue renderer host callbacks stay together. */
import assert from 'node:assert/strict'
import { existsSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import type { ErpCommercialDocument } from '../shared/types/erpBusiness.ts'

type RequestOptions = { method?: string, body?: Record<string, unknown>, headers?: Record<string, string> }
type RequestApi = (path: string, options?: RequestOptions) => Promise<unknown>
type ReviewOptions = { tax_context?: Record<string, unknown>, lines?: Array<{ id: number, cfop?: string | null }> }
type Workspace = ReturnType<typeof import('../app/composables/useErpSalesWorkspace.ts').useErpSalesWorkspace> & {
  taxReview: Vue.Ref<Record<string, unknown> | null>
  previewTaxReview: (kind: 'sale', document: ErpCommercialDocument, options?: ReviewOptions) => Promise<unknown>
  applyTaxReview: (kind: 'sale', document: ErpCommercialDocument, options?: ReviewOptions) => Promise<unknown>
  closeTaxReview: () => void
}
const permissions = ['erp.sales.index', 'erp.sales.show', 'erp.sales.update', 'erp.sales.post']
const taxContext = { origin_uf: 'SP', destination_uf: 'SP', model: '55', purpose: 'normal', final_consumer: true, presence: '1' }
const oldFacts = {
  version: 'commercial-v2', description: 'Produto salvo', product: { id: 10, name: 'Produto salvo', kind: 'goods', track_stock: false, ncm: '12345678', origin_code: '0', icms_cst: '00', pis_cst: '07', cofins_cst: '07' },
  variant: { id: 11, sku: 'SKU-SAVED' }, unit: { id: 4, short_name: 'UN' },
  product_tax_resolution: { profile: { id: 8, revision: 1 }, values: { icms_cst: '00', pis_cst: '07' }, sources: { icms_cst: { source: 'profile', profile_id: 8, revision: 1 }, pis_cst: { source: 'product', intent: 'value' } }, unresolved_fields: [] },
  cfop_resolution: { effective_cfop: '5101', default_cfop: '5102', source: 'product_exception', nature_id: 9, exception_id: 12, configuration_revision: 3, context: taxContext, explicit_choice: null, applicability: { status: 'supported' }, unresolved_fields: [] },
  operation_nature: { id: 9, cfop: '5101', default_cfop: '5102', configuration_revision: 3 }
}
const candidateFacts = { ...oldFacts, product: { ...oldFacts.product, ncm: '87654321' }, product_tax_resolution: { ...oldFacts.product_tax_resolution, profile: { id: 8, revision: 2 } } }
const sale = {
  id: 5, erp_establishment_id: 3, erp_contact_id: 6, erp_price_group_id: null, erp_operation_nature_id: 9, invoice_no: '0005', status: 'draft', fiscal_status: 'none', fiscal_model: '55', currency: 'BRL', draft_revision: 1,
  subtotal_amount: '100.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '100.0000', stock_reserved: false, needs_reconciliation: false,
  lines: [{ id: 20, erp_product_variant_id: 11, erp_unit_id: 4, quantity: '1.0000', unit_price: '100.0000', line_total: '100.0000', product_snapshot: oldFacts }],
  commercial_snapshot: { version: 'commercial-v2', fiscal_context: { version: 'sale-source-prerequisites-v1', model: '55', readiness: 'incomplete', errors: { 'party.address': ['Revise o endereço do destinatário.'] } }, lines: [] }
} as unknown as ErpCommercialDocument
const preview = { reviewed_fingerprint: 'signed-preview-v1', draft_revision: 1, lines: [{ id: 20, previous_product_snapshot: oldFacts, product_snapshot: candidateFacts }], fiscal_source_review: { model: '55', configuration_revision_id: 15, operation_nature_id: 9 } }
const envelope = (data: unknown) => ({ data, meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 1, per_page: 15 } })
function defaultApi(path: string, options?: RequestOptions): Promise<unknown> {
  if (path.endsWith('/tax-refresh-preview')) return Promise.resolve(envelope(preview))
  if (/^\/erp\/sales\/\d+$/.test(path)) return Promise.resolve(envelope({ ...sale, id: Number(path.split('/').at(-1)), ...(options?.method === 'PATCH' ? { draft_revision: 2 } : {}) }))
  if (path.startsWith('/erp/sales?')) return Promise.resolve(envelope([sale]))
  return Promise.resolve(envelope([]))
}
function runtime(api: RequestApi = defaultApi, grants = permissions) {
  const storage = new Map<string, string>()
  const previous = Object.getOwnPropertyDescriptor(globalThis, 'sessionStorage')
  Object.defineProperty(globalThis, 'sessionStorage', { configurable: true, value: { getItem: (key: string) => storage.get(key) ?? null, setItem: (key: string, value: string) => storage.set(key, value), removeItem: (key: string) => storage.delete(key) } })
  const user = Vue.ref({ id: 91 })
  const currentCompany = Vue.ref({ id: 7, permissions: grants, role: 'operator' })
  const session = Vue.ref(0)
  const key = Vue.computed(() => `${user.value.id}:${currentCompany.value.id}:${currentCompany.value.role}:${currentCompany.value.permissions.join(',')}`)
  const context = { key, capture: () => ({ key: key.value }), isCurrent: (token: { key: string }) => token.key === key.value }
  const scope = Vue.effectScope()
  const module = loadAppComposable<{ useErpSalesWorkspace: (area: string) => Workspace }>('composables/useErpSalesWorkspace.ts', {
    ref: Vue.ref, computed: Vue.computed, reactive: Vue.reactive, watch: Vue.watch, onMounted: () => {}, onScopeDispose: Vue.onScopeDispose,
    useState: (name: string, initialize: () => unknown) => name === 'access-session-generation' ? session : Vue.ref(initialize()),
    useAuth: () => ({ user, currentCompany, refreshWithStatus: async () => ({ committed: false }) }),
    useAccessApi: () => ({ api, refreshCsrf: async () => {} }), useErpAdministrationContext: () => context
  })
  const state = scope.run(() => module.useErpSalesWorkspace('sales'))!
  return { state, currentCompany, session, context, storage, stop() { scope.stop(); if (previous) Object.defineProperty(globalThis, 'sessionStorage', previous); else Reflect.deleteProperty(globalThis, 'sessionStorage') } }
}
async function settle() { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); await Vue.nextTick() }
type HostNode = { style?: Record<string, string>, tag?: string, text?: string, props: Record<string, unknown>, children: HostNode[], parent: HostNode | null }
function mountWorkspace(api: RequestApi = defaultApi, grants = permissions, realForm = false, area: 'sales' | 'quotes' | 'orders' = 'sales') {
  const current = runtime(api, grants)
  const route = Vue.reactive({ path: '/erp/vendas', hash: `#${area}`, query: {} })
  const globals = { onMounted: Vue.onMounted, useRoute: () => route, useRouter: () => ({ replace: async () => {} }), useErpAdministrationContext: () => current.context }
  const workspace = compileVueComponent('components/erp/ErpSalesWorkspace.vue', globals, { '~/composables/useErpSalesWorkspace': { useErpSalesWorkspace: () => current.state } })
  const root: HostNode = { tag: 'root', props: {}, children: [], parent: null }
  const renderer = Vue.createRenderer<HostNode>({
    patchProp(node, name, _old, value) { node.props[name] = value },
    insert(node, parent, anchor) { node.parent = parent; const index = anchor ? parent.children.indexOf(anchor) : -1; if (index < 0) parent.children.push(node); else parent.children.splice(index, 0, node) },
    remove(node) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); node.parent = null },
    createElement(tag) { return { tag, style: {}, props: {}, children: [], parent: null } },
    createText(text) { return { text, props: {}, children: [], parent: null } }, createComment(text) { return { text, props: {}, children: [], parent: null } },
    setText(node, text) { node.text = text }, setElementText(node, text) { node.text = text; node.children = [] }, parentNode(node) { return node.parent }, nextSibling(node) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  const app = renderer.createApp(workspace)
  const shell = Vue.defineComponent({ setup: (_props, ctx) => () => Vue.h('div', [...(ctx.slots.actions?.() ?? []), ...(ctx.slots.toolbar?.() ?? []), ...(ctx.slots.default?.() ?? [])]) })
  for (const name of ['ErpCatalogPageShell', 'UDropdownMenu', 'UBadge', 'UIcon', 'NuxtLink', 'CompanyMenu', 'UserMenu', 'UCheckbox', 'ErpSaleReturnForm', 'ErpPendingOperationAlert', 'ErpCatalogReferenceSelect']) app.component(name, shell)
  app.component('UForm', Vue.defineComponent({ emits: ['submit'], setup: (_props, ctx) => () => Vue.h('form', { onSubmit: () => ctx.emit('submit') }, ctx.slots.default?.()) }))
  app.component('UFormField', Vue.defineComponent({ props: { name: String, label: String, error: String }, setup: (props, ctx) => () => Vue.h('label', { 'data-field': props.name }, [props.label, ...(ctx.slots.default?.() ?? []), props.error]) }))
  app.component('UButton', Vue.defineComponent({ props: { label: String, disabled: Boolean, loading: Boolean }, setup: (props, ctx) => () => Vue.h('button', { ...ctx.attrs, disabled: props.disabled || props.loading }, props.label ?? ctx.slots.default?.()) }))
  for (const name of ['UInput', 'USelect']) app.component(name, Vue.defineComponent({ props: { modelValue: [String, Number, Boolean], items: Array, disabled: Boolean }, emits: ['update:modelValue'], setup: (props, ctx) => () => Vue.h(name === 'UInput' ? 'input' : 'select', { value: props.modelValue, disabled: props.disabled, onChange: (event: { target: { value: string } }) => ctx.emit('update:modelValue', event.target.value) }, ctx.slots.default?.()) }))
  app.component('UAlert', Vue.defineComponent({ props: { title: String, description: String }, setup: (props, ctx) => () => Vue.h('div', [props.title, props.description, ...(ctx.slots.description?.() ?? []), ...(ctx.slots.actions?.() ?? [])]) }))
  app.component('UModal', Vue.defineComponent({ props: { open: Boolean }, emits: ['update:open'], setup: (props, ctx) => () => props.open ? Vue.h('section', [Vue.h('button', { onClick: () => ctx.emit('update:open', false) }, 'Fechar modal'), ...(ctx.slots.body?.() ?? []), ...(ctx.slots.footer?.({ close: () => ctx.emit('update:open', false) }) ?? [])]) : null }))
  app.component('ErpSaleDetail', compileVueComponent('components/erp/ErpSaleDetail.vue', globals))
  for (const name of ['ErpCommercialTaxReview', 'ErpCommercialDraftForm']) {
    const path = `components/erp/${name}.vue`
    if (name === 'ErpCommercialTaxReview' && !existsSync(new URL(`../app/${path}`, import.meta.url))) continue
    app.component(name, name === 'ErpCommercialDraftForm' && !realForm ? shell : compileVueComponent(path, globals))
  }
  app.mount(root)
  const text = (node: HostNode): string => `${node.text ?? ''}${node.children.map(text).join('')}`
  const find = (predicate: (node: HostNode) => boolean, node = root): HostNode[] => [...(predicate(node) ? [node] : []), ...node.children.flatMap(child => find(predicate, child))]
  async function click(label: string) { const button = find(node => node.tag === 'button' && text(node).includes(label))[0]; assert.ok(button, `Expected visible action: ${label}`); assert.ok(!button.props.disabled, `Expected enabled action: ${label}`); (button.props.onClick as () => unknown)(); await settle() }
  return { ...current, text: () => text(root), find, click, async submit() { const form = find(node => node.tag === 'form')[0]; assert.ok(form); (form.props.onSubmit as () => void)(); await settle() }, dispose() { app.unmount(); current.stop() } }
}

// Eleven serial SFC cases need an aggregate budget separate from each bounded case.
describe('commercial tax review actual Sales consumer', { concurrency: 1, timeout: 120_000 }, () => {
  it('opens a reviewable preview from detail, shows frozen/current facts and applies separately from posting', { timeout: 30_000 }, async (t) => {
    const calls: Array<[string, RequestOptions | undefined]> = []
    const page = mountWorkspace(async (path, options) => { calls.push([path, options]); return defaultApi(path, options) })
    t.after(() => page.dispose())
    await settle(); await page.click('0005'); await page.click('Revisar classificação'); await page.click('Gerar prévia')
    assert.ok(page.text().includes('12345678') && page.text().includes('87654321'))
    assert.ok(page.text().includes('5101') && page.text().includes('5102'), 'effective CFOP and catalog default remain distinct')
    assert.ok(/perfil/i.test(page.text()) && /exceção/i.test(page.text()), 'actual resolution sources remain visible')
    assert.equal(calls.filter(([, options]) => options?.method === 'PATCH').length, 0)
    assert.equal(calls.filter(([path]) => path.endsWith('/post')).length, 0)
    await page.click('Aplicar revisão')
    const apply = calls.find(([, options]) => options?.method === 'PATCH')
    assert.equal(apply?.[0], '/erp/sales/5')
    assert.deepEqual(apply?.[1]?.body, { draft_revision: 1, tax_refresh: { reviewed_fingerprint: 'signed-preview-v1' } })
    assert.ok(apply?.[1]?.headers?.['Idempotency-Key'])
    assert.equal(calls.filter(([path]) => path.endsWith('/post')).length, 0)
  })

  it('shows source prerequisite feedback without claiming authorization or complete fiscal readiness', { timeout: 30_000 }, async (t) => {
    const page = mountWorkspace(); t.after(() => page.dispose())
    await settle(); await page.click('0005')
    assert.ok(page.text().includes('NF-e') && page.text().includes('endereço do destinatário'))
    assert.ok(!/pronta para (emissão|transmissão)/i.test(page.text()))
    assert.ok(!/fiscal[^.]*autorizad[ao]/i.test(page.text()))
  })

  it('does not offer refresh for accepted history and explains the frozen-source limitation without current lookup', { timeout: 30_000 }, async (t) => {
    const historical = { ...sale, status: 'posted', commercial_snapshot: { version: 'commercial-v1', accepted: { draft_revision: 1 }, lines: [] } }
    let previews = 0
    const page = mountWorkspace(async (path, options) => { if (path.endsWith('/tax-refresh-preview')) previews++; return /^\/erp\/sales\/5$/.test(path) ? envelope(historical) : defaultApi(path, options) })
    t.after(() => page.dispose()); await settle(); await page.click('0005')
    assert.equal(page.find(node => node.tag === 'button' && String(node.props.label ?? '').includes('Revisar classificação')).length, 0)
    assert.match(page.text(), /históric|congelad/i)
    assert.equal(previews, 0)
  })

  it('keeps an invalid context editable and previews the explicitly corrected value before apply', { timeout: 30_000 }, async (t) => {
    const bodies: Array<Record<string, unknown> | undefined> = []
    const page = mountWorkspace(async (path, options) => {
      if (path.endsWith('/tax-refresh-preview')) {
        bodies.push(options?.body)
        if (bodies.length === 1) throw { statusCode: 422, data: { errors: { 'tax_context.destination_uf': ['Informe a UF do destinatário.'] } } }
      }
      return defaultApi(path, options)
    })
    t.after(() => page.dispose()); await settle(); await page.click('0005'); await page.click('Revisar classificação'); await page.click('Gerar prévia')
    assert.ok(page.text().includes('Informe a UF do destinatário.'))
    const field = page.find(node => node.props['data-field'] === 'tax_context.destination_uf')[0]
    assert.ok(field, 'The rejected context has a reachable real field')
    const control = page.find(node => node.tag === 'input' || node.tag === 'select', field)[0]
    assert.ok(control)
    ;(control.props.onChange as (event: unknown) => void)({ target: { value: 'RJ' } })
    await settle(); await page.click('Gerar prévia')
    assert.equal((bodies[1]?.tax_context as Record<string, unknown>)?.destination_uf, 'RJ')
    assert.ok(page.text().includes('87654321'))
  })

  it('attaches malformed third-line CFOP feedback to its actual control and preserves reviewed options through correction and apply', { timeout: 30_000 }, async (t) => {
    const calls: Array<[string, RequestOptions | undefined]> = []
    const document = { ...sale, lines: [20, 41, 33].map(id => ({ ...sale.lines[0]!, id })) }
    const previews: Array<Record<string, unknown>> = []
    const page = mountWorkspace(async (path, options) => {
      calls.push([path, options])
      if (path === '/erp/sales/5' && !options?.method) return envelope(document)
      if (path.endsWith('/tax-refresh-preview')) {
        const body = options?.body ?? {}
        previews.push(body)
        const lines = body.lines as Array<{ id: number, cfop?: string | null }>
        // Native options() validates the submitted list before sorting; errors use its index.
        const invalid = lines.findIndex(line => Object.hasOwn(line, 'cfop') && line.cfop !== null && (typeof line.cfop !== 'string' || !/^[123567][0-9]{3}$/.test(line.cfop)))
        if (invalid >= 0) throw { statusCode: 422, data: { errors: { [`lines.${invalid}.cfop`]: ['CFOP inválido na terceira linha.'] } } }
        return envelope({ ...preview, lines: document.lines.map(line => ({ id: line.id, previous_product_snapshot: oldFacts, product_snapshot: candidateFacts })) })
      }
      return defaultApi(path, options)
    })
    t.after(() => page.dispose()); await settle(); await page.click('0005'); await page.click('Revisar classificação')
    const field = (index: number) => {
      const node = page.find(node => node.props['data-field'] === `lines.${index}.cfop`)[0]
      assert.ok(node, `Expected editable CFOP field for owned line index ${index}`)
      return node
    }
    const control = (index: number, tag: string) => {
      const node = page.find(node => node.tag === tag, field(index))[0]
      assert.ok(node)
      return node
    }
    ;(control(2, 'select').props.onChange as (event: unknown) => void)({ target: { value: 'explicit' } }); await settle()
    ;(control(2, 'input').props.onChange as (event: unknown) => void)({ target: { value: 'ABCD' } }); await settle()
    await page.click('Gerar prévia')
    const feedback = page.find(node => node.text?.includes('CFOP inválido na terceira linha.') === true, field(2))
    assert.ok(feedback.length, 'The native submitted-index error must identify the rejected third control')
    assert.equal(page.find(node => node.text?.includes('CFOP inválido na terceira linha.') === true, field(0)).length, 0)
    assert.equal(page.find(node => node.text?.includes('CFOP inválido na terceira linha.') === true, field(1)).length, 0)
    assert.equal(control(2, 'input').props.value, 'ABCD')
    assert.equal(calls.some(([, options]) => options?.method === 'PATCH'), false)
    ;(control(2, 'input').props.onChange as (event: unknown) => void)({ target: { value: '5102' } }); await settle()
    await page.click('Gerar prévia')
    assert.deepEqual(previews[1]?.lines, [{ id: 20 }, { id: 41 }, { id: 33, cfop: '5102' }])
    await page.click('Aplicar revisão')
    const apply = calls.find(([, options]) => options?.method === 'PATCH')
    assert.deepEqual((apply?.[1]?.body?.tax_refresh as Record<string, unknown>)?.lines, previews[1]?.lines)
    assert.equal((apply?.[1]?.body?.tax_refresh as Record<string, unknown>)?.reviewed_fingerprint, 'signed-preview-v1')
    assert.ok(apply?.[1]?.headers?.['Idempotency-Key'])
    assert.equal(calls.some(([path]) => path.endsWith('/post')), false)
  })

  it('invalidates the visible proof when context changes and requires another preview', { timeout: 30_000 }, async (t) => {
    const calls: Array<[string, RequestOptions | undefined]> = []
    const page = mountWorkspace(async (path, options) => { calls.push([path, options]); return defaultApi(path, options) })
    t.after(() => page.dispose()); await settle(); await page.click('0005'); await page.click('Revisar classificação'); await page.click('Gerar prévia')
    const field = page.find(node => node.props['data-field'] === 'tax_context.destination_uf')[0]
    assert.ok(field)
    const control = page.find(node => node.tag === 'input' || node.tag === 'select', field)[0]
    assert.ok(control)
    ;(control.props.onChange as (event: unknown) => void)({ target: { value: 'RJ' } })
    await settle()
    const apply = page.find(node => node.tag === 'button' && String(node.props.label ?? '').includes('Aplicar revisão'))[0]
    assert.ok(!apply || apply.props.disabled, 'Changed options cannot apply an unseen candidate')
    assert.equal(calls.filter(([, options]) => options?.method === 'PATCH').length, 0)
  })

  for (const intent of ['55', null] as const) {
    it(`roundtrips explicit fiscal intent ${intent ?? 'null'} through the real editor without changing line or money authority`, { timeout: 30_000 }, async (t) => {
      const calls: Array<[string, RequestOptions | undefined]> = []
      const page = mountWorkspace(async (path, options) => { calls.push([path, options]); return defaultApi(path, options) }, permissions, true)
      t.after(() => page.dispose()); await settle(); await page.click('0005'); await page.click('Editar rascunho')
      const field = page.find(node => node.props['data-field'] === 'fiscal_model')[0]
      assert.ok(field)
      const select = page.find(node => node.tag === 'select', field)[0]
      assert.ok(select)
      if (intent === null) (select.props.onChange as (event: unknown) => void)({ target: { value: 'none' } })
      await settle(); await page.submit()
      const body = calls.find(([, options]) => options?.method === 'PATCH')?.[1]?.body
      assert.ok(body)
      assert.equal(body.fiscal_model, intent)
      assert.equal((body.lines as Array<Record<string, unknown>>)[0]?.id, 20)
      assert.equal((body.lines as Array<Record<string, unknown>>)[0]?.unit_price, '100.0000')
      assert.deepEqual(body.tax_context, taxContext)
    })
  }

  it('exposes post422 context feedback and a real edit correction without silently reposting', { timeout: 30_000 }, async (t) => {
    const calls: Array<[string, RequestOptions | undefined]> = []
    const page = mountWorkspace(async (path, options) => {
      calls.push([path, options])
      if (path.endsWith('/post')) throw { statusCode: 422, data: { errors: { 'tax_context.destination_uf': ['UF de destino inválida para lançar.'] } } }
      return defaultApi(path, options)
    }, permissions, true)
    t.after(() => page.dispose()); await settle(); await page.click('0005'); await page.click('Lançar venda'); await page.click('Confirmar lançamento da venda')
    assert.ok(page.text().includes('UF de destino inválida para lançar.'))
    await page.click('Corrigir rascunho')
    const field = page.find(node => node.props['data-field'] === 'tax_context.destination_uf')[0]
    assert.ok(field)
    const input = page.find(node => node.tag === 'input', field)[0]
    assert.ok(input)
    ;(input.props.onChange as (event: unknown) => void)({ target: { value: 'RJ' } })
    await settle(); await page.submit()
    const body = calls.find(([, options]) => options?.method === 'PATCH')?.[1]?.body
    assert.equal((body?.tax_context as Record<string, unknown>)?.destination_uf, 'RJ')
    assert.equal((body?.lines as Array<Record<string, unknown>>)?.[0]?.id, 20)
    assert.equal(calls.filter(([path]) => path.endsWith('/post')).length, 1)
  })

  it('previews and explicitly applies quotes and orders under each exact source update grant', { timeout: 30_000 }, async () => {
    for (const area of ['quotes', 'orders'] as const) {
      const calls: Array<[string, RequestOptions | undefined]> = []
      const document = { ...sale, fiscal_model: undefined, status: area === 'quotes' ? 'draft' : 'confirmed', invoice_no: undefined, ...(area === 'quotes' ? { quote_no: 'Q-0005' } : { order_no: 'O-0005' }) }
      const page = mountWorkspace(async (path, options) => {
        calls.push([path, options])
        if (path.startsWith(`/erp/${area}?`)) return envelope([document])
        if (path === `/erp/${area}/5`) return envelope({ ...document, ...(options?.method === 'PATCH' ? { draft_revision: 2 } : {}) })
        if (path === `/erp/${area}/5/tax-refresh-preview`) return envelope({ ...preview, fiscal_source_review: undefined })
        return defaultApi(path, options)
      }, [`erp.${area}.index`, `erp.${area}.show`, `erp.${area}.update`], false, area)
      try {
        await settle(); await page.click(area === 'quotes' ? 'Q-0005' : 'O-0005'); await page.click('Revisar classificação'); await page.click('Gerar prévia'); await page.click('Aplicar revisão')
        const apply = calls.find(([, options]) => options?.method === 'PATCH')
        assert.equal(apply?.[0], `/erp/${area}/5`)
        assert.deepEqual(apply?.[1]?.body, { draft_revision: 1, tax_refresh: { reviewed_fingerprint: 'signed-preview-v1' } })
        assert.equal(calls.some(([path]) => path.endsWith('/post')), false)
        assert.equal(page.state.resolvedOperation.value?.operation, `erp.${area}.update`)
      } finally { page.dispose() }
    }
  })

  it('requires the actual source update grant and cannot borrow a show or post grant', { timeout: 30_000 }, async (t) => {
    const page = mountWorkspace(defaultApi, ['erp.sales.index', 'erp.sales.show', 'erp.sales.post'])
    t.after(() => page.dispose()); await settle(); await page.click('0005')
    assert.equal(page.find(node => node.tag === 'button' && String(node.props.label ?? '').includes('Revisar classificação')).length, 0)
  })
})

describe('commercial tax review proof and recovery boundaries', { concurrency: 1, timeout: 30_000 }, () => {
  it('preserves exact options/proof/key after a lost apply response and retries only the original update', async (t) => {
    const calls: Array<[string, RequestOptions | undefined]> = []
    let fail = true
    const current = runtime(async (path, options) => {
      calls.push([path, options])
      if (path.startsWith('/erp/operations/lookup')) throw { response: { status: 404 } }
      if (options?.method === 'PATCH' && fail) throw new Error('response lost')
      return defaultApi(path, options)
    })
    t.after(() => current.stop()); await current.state.inspect('sales', 5)
    const options = { tax_context: taxContext, lines: [{ id: 20, cfop: null }] }
    await current.state.previewTaxReview('sale', sale, options)
    await current.state.applyTaxReview('sale', sale, options)
    assert.equal(current.state.needsReconciliation.value, true)
    const original = current.state.selectedOperation.value!
    assert.equal(original.operation, 'erp.sales.update')
    assert.equal(original.method, 'PATCH')
    assert.deepEqual(original.payload, { draft_revision: 1, tax_refresh: { reviewed_fingerprint: 'signed-preview-v1', ...options } })
    await current.state.reconcile(); fail = false; await current.state.retryExact()
    const patches = calls.filter(([, request]) => request?.method === 'PATCH')
    assert.equal(patches.length, 2)
    assert.equal(patches[0]![1]!.headers!['Idempotency-Key'], patches[1]![1]!.headers!['Idempotency-Key'])
    assert.deepEqual(patches[0]![1]!.body, patches[1]![1]!.body)
    assert.equal(calls.filter(([path]) => path.endsWith('/post')).length, 0)
  })

  for (const code of ['commercial_tax_review_changed', 'sale_fiscal_review_changed'] as const) {
    it(`treats only the exact rollback response ${code} as rejected and preserves input for a fresh review`, async (t) => {
      let reject = true
      const current = runtime(async (path, options) => {
        if ((options?.method === 'PATCH' || path.endsWith('/post')) && reject) throw { response: { status: 409, _data: { code, message: 'Revise novamente.' } } }
        return defaultApi(path, options)
      })
      t.after(() => current.stop()); await current.state.inspect('sales', 5)
      await current.state.previewTaxReview('sale', sale)
      if (code === 'sale_fiscal_review_changed') await current.state.postSale(sale)
      else await current.state.applyTaxReview('sale', sale)
      assert.equal(current.state.selectedOperation.value, null)
      assert.equal(current.state.needsReconciliation.value, false)
      assert.equal(current.state.selected.value?.id, 5)
      assert.equal(current.state.taxReview.value, null)
      reject = false
      await current.state.previewTaxReview('sale', sale)
      await current.state.applyTaxReview('sale', sale)
      assert.equal(current.state.resolvedOperation.value?.operation, 'erp.sales.update')
    })
  }

  it('retains the durable exact operation for an unknown conflict', async (t) => {
    const current = runtime(async (path, options) => {
      if (options?.method === 'PATCH') throw { response: { status: 409, _data: { code: 'unconfirmed_conflict' } } }
      return defaultApi(path, options)
    })
    t.after(() => current.stop()); await current.state.inspect('sales', 5)
    await current.state.previewTaxReview('sale', sale); await current.state.applyTaxReview('sale', sale)
    assert.equal(current.state.needsReconciliation.value, true)
    assert.equal(current.state.selectedOperation.value?.operation, 'erp.sales.update')
    assert.deepEqual(current.state.selectedOperation.value?.payload, { draft_revision: 1, tax_refresh: { reviewed_fingerprint: 'signed-preview-v1' } })
  })

  it('exact retry after a lost committed response reuses recorded original despite newer preview facts', async (t) => {
    const patches: RequestOptions[] = []
    let original: unknown
    const committed = { ...sale, draft_revision: 2 }
    const current = runtime(async (path, options) => {
      if (path.startsWith('/erp/operations/lookup')) throw { response: { status: 404 } }
      if (options?.method === 'PATCH') {
        patches.push(options)
        if (patches.length === 1) { original = options.body; throw new Error('committed response lost') }
        assert.deepEqual(options.body, original, 'The original journal payload is reused before considering newer server facts')
        return envelope(committed)
      }
      if (path.endsWith('/tax-refresh-preview') && patches.length) return envelope({ ...preview, reviewed_fingerprint: 'newer-unreviewed-proof' })
      return defaultApi(path, options)
    })
    t.after(() => current.stop()); await current.state.inspect('sales', 5)
    await current.state.previewTaxReview('sale', sale); await current.state.applyTaxReview('sale', sale)
    const key = current.state.selectedOperation.value!.key
    await current.state.reconcile(); await current.state.retryExact()
    assert.equal(patches.length, 2)
    assert.equal(patches[1]?.headers?.['Idempotency-Key'], key)
    assert.equal(current.state.resolvedOperation.value?.key, key)
    assert.equal(current.state.selectedOperation.value, null)
  })

  it('shows exact manual-authority field rejection without retrying or changing money', async (t) => {
    const current = runtime(async (path, options) => {
      if (options?.method === 'PATCH') throw { statusCode: 422, data: { errors: { 'lines.0.unit_price': ['Permissão explícita de preço necessária.'] } } }
      return defaultApi(path, options)
    })
    t.after(() => current.stop()); await current.state.inspect('sales', 5)
    await current.state.previewTaxReview('sale', sale); await current.state.applyTaxReview('sale', sale)
    assert.equal(current.state.fieldErrors.value['lines.0.unit_price'], 'Permissão explícita de preço necessária.')
    assert.equal(current.state.selectedOperation.value, null)
    assert.equal((current.state.selected.value as ErpCommercialDocument).total_amount, '100.0000')
  })

  for (const boundary of ['company', 'session', 'permission', 'selection', 'close'] as const) {
    it(`discards a late preview after ${boundary} changes`, async (t) => {
      let release!: (value: unknown) => void
      const current = runtime((path, options) => path.endsWith('/tax-refresh-preview') ? new Promise((resolve) => { release = resolve }) : defaultApi(path, options))
      t.after(() => current.stop()); await current.state.inspect('sales', 5)
      const pending = current.state.previewTaxReview('sale', sale)
      await settle()
      if (boundary === 'company') current.currentCompany.value = { ...current.currentCompany.value, id: 8 }
      if (boundary === 'session') current.session.value++
      if (boundary === 'permission') current.currentCompany.value = { ...current.currentCompany.value, permissions: ['erp.sales.show'] }
      if (boundary === 'selection') await current.state.inspect('sales', 6)
      if (boundary === 'close') current.state.closeTaxReview()
      release(envelope(preview)); await pending
      assert.equal(current.state.taxReview.value, null)
      await current.state.applyTaxReview('sale', sale)
      assert.equal(current.state.selectedOperation.value, null)
    })
  }
})
