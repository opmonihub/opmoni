/* eslint-disable @stylistic/max-statements-per-line -- Keep local host operations beside their renderer callbacks. */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import ts from 'typescript'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import * as apiErrors from '../app/composables/useApiError.ts'
import * as fiscalHelpers from '../shared/utils/erpFiscal.ts'
import * as companyPermissions from '../app/utils/erpModulesNav.ts'
import { erpTypedFiscalFieldDescriptors } from '../app/utils/erpTypedFiscalForm.ts'

type RequestOptions = { method?: string, body?: Record<string, unknown> }
type Api = (path: string, options?: RequestOptions) => Promise<unknown>
type State = ReturnType<typeof import('../app/composables/useErpFiscalWorkspace.ts').useErpFiscalWorkspace>
type HostNode = {
  tag?: string
  text?: string
  style?: Record<string, string>
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
  querySelector?: (selector: string) => HostNode | null
  focus?: () => void
}
const envelope = (data: unknown) => ({ data })
const configuration = {
  establishment: { id: 6, name: 'Matriz', cnpj: null, status: 'active' }, revision: 1, revision_id: 3,
  environment: 'homologation', issuer: null,
  models: Object.fromEntries(['55', '65', '57', '58'].map(model => [model, { series: 1, next_number: 1, readiness: { status: 'locally_configured', blockers: [] } }])),
  release_gates: { provider: 'unavailable', production_transmission_enabled: false, production_blocker: 'provider_unavailable' }
}

async function defaultApi(path: string) {
  if (path.startsWith('/erp/establishments?')) return envelope([{ id: 6, name: 'Matriz', cnpj: null, status: 'active' }, { id: 9, name: 'Filial', cnpj: null, status: 'active' }])
  if (path.startsWith('/erp/fiscal/configuration/')) return envelope({ ...configuration, establishment: { ...configuration.establishment, id: Number(path.split('/').at(-1)) } })
  return envelope([])
}
async function settle() { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); await Vue.nextTick() }

function mountLifecycle(api: Api = defaultApi) {
  const previousStorage = Object.getOwnPropertyDescriptor(globalThis, 'sessionStorage')
  const memory = new Map<string, string>()
  Object.defineProperty(globalThis, 'sessionStorage', { configurable: true, value: { getItem: (key: string) => memory.get(key) ?? null, setItem: (key: string, value: string) => memory.set(key, value), removeItem: (key: string) => memory.delete(key) } })
  const company = Vue.ref({ id: 7, status: 'active', access_kind: 'direct', role: 'owner', permissions: ['*'] })
  const session = Vue.ref(0)
  const user = Vue.ref({ id: 91 })
  const useContext = (extra: () => unknown = () => null) => {
    const key = Vue.computed(() => JSON.stringify([user.value.id, company.value, session.value, extra()]))
    const generation = Vue.ref(0)
    Vue.watch(key, () => generation.value++, { flush: 'sync' })
    return { key, capture: () => ({ key: key.value, generation: generation.value }), isCurrent: (token: { key: string, generation: number }) => token.key === key.value && token.generation === generation.value }
  }
  const calls: Array<[string, RequestOptions | undefined]> = []
  const request: Api = async (path, options) => { calls.push([path, options]); return api(path, options) }
  const globals = {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch, onScopeDispose: Vue.onScopeDispose,
    useAuth: () => ({ user, currentCompany: company, currentAccount: Vue.ref(null), refreshWithStatus: async () => ({ committed: false }) }),
    useState: (key: string, initialize: () => unknown) => key === 'access-session-generation' ? session : Vue.ref(initialize())
  }
  const imports: Record<string, unknown> = {
    'vue': Vue, './useApiError': apiErrors, './useAccessApi': { useAccessApi: () => ({ api: request }) },
    './useErpAdministrationContext': { useErpAdministrationContext: useContext },
    '~/utils/erpModulesNav': companyPermissions, '#shared/utils/erpFiscal': fiscalHelpers
  }
  const output = ts.transpileModule(readFileSync(new URL('../app/composables/useErpFiscalWorkspace.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const module = { exports: {} as { useErpFiscalWorkspace: () => State } }
  new Function('require', 'exports', ...Object.keys(globals), output)((name: string) => {
    assert.ok(Object.hasOwn(imports, name), `Expected actual fiscal composable import: ${name}`)
    return imports[name]
  }, module.exports, ...Object.values(globals))
  const scope = Vue.effectScope()
  const state = scope.run(() => module.exports.useErpFiscalWorkspace())!
  state.selectedEstablishmentId.value = 6
  const route = Vue.reactive({ path: '/erp/fiscal', query: {}, hash: '#cte' })
  const bindings = {
    ...globals, nextTick: Vue.nextTick, onMounted: Vue.onMounted, useRoute: () => route,
    useRouter: () => ({
      push: async (location: { hash: string }) => { route.hash = location.hash },
      replace: async (location: { path: string, query: Record<string, unknown>, hash: string }) => {
        assert.equal(location.path, route.path)
        assert.deepEqual(location.query, route.query)
        route.hash = location.hash
      }
    }),
    useErpFiscalWorkspace: () => state, useErpAdministrationContext: useContext
  }
  const workspace = compileVueComponent('components/erp/ErpFiscalWorkspace.vue', bindings)
  const form = compileVueComponent('components/erp/ErpFiscalDocumentForm.vue', bindings)
  let focused: HostNode | null = null
  const walk = (node: HostNode): HostNode[] => [node, ...node.children.flatMap(walk)]
  const createElement = (tag: string): HostNode => {
    const node: HostNode = { tag, style: {}, props: {}, children: [], parent: null }
    node.focus = () => { focused = node }
    node.querySelector = (selector) => {
      const key = /\[data-fiscal-field-key="([^"]+)"\]/.exec(selector)?.[1]
      return key ? walk(node).find(child => child.props['data-fiscal-field-key'] === key) ?? null : null
    }
    return node
  }
  const root = createElement('root')
  const renderer = Vue.createRenderer<HostNode>({
    patchProp(node, key, _old, value) { node.props[key] = value },
    insert(node, parent, anchor) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node); else parent.children.splice(index, 0, node)
    },
    remove(node) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); node.parent = null },
    createElement, createText(text) { return { text, props: {}, children: [], parent: null } }, createComment(text) { return { text, props: {}, children: [], parent: null } },
    setText(node, text) { node.text = text }, setElementText(node, text) { node.text = text; node.children = [] },
    parentNode(node) { return node.parent }, nextSibling(node) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  const app = renderer.createApp(workspace)
  const shell = Vue.defineComponent({ setup: (_props, ctx) => () => Vue.h('div', [...(ctx.slots.header?.() ?? []), ...(ctx.slots.actions?.() ?? []), ...(ctx.slots.default?.() ?? []), ...(ctx.slots.footer?.() ?? [])]) })
  for (const name of ['ErpCatalogPageShell', 'UCard', 'UBadge', 'UIcon', 'UDropdownMenu', 'UNavigationMenu', 'CompanyMenu', 'UserMenu', 'NuxtLink']) app.component(name, shell)
  for (const name of ['ErpFiscalDocumentDetail', 'ErpFiscalConfigurationForm', 'ErpFiscalCertificateForm', 'ErpFiscalEventForm', 'ErpFiscalInvalidationForm']) app.component(name, Vue.defineComponent({ render: () => null }))
  app.component('ErpFiscalDocumentForm', form)
  app.component('UForm', Vue.defineComponent({ emits: ['submit'], setup: (_props, ctx) => () => Vue.h('form', { onSubmit: () => ctx.emit('submit') }, ctx.slots.default?.()) }))
  app.component('UFormField', Vue.defineComponent({ props: { name: String, label: String, error: String }, setup: (props, ctx) => () => Vue.h('label', { 'data-field': props.name }, [props.label, ...(ctx.slots.default?.() ?? []), props.error]) }))
  app.component('UButton', Vue.defineComponent({ props: { label: String, disabled: Boolean, loading: Boolean }, setup: (props, ctx) => () => Vue.h('button', { ...ctx.attrs, disabled: props.disabled || props.loading }, props.label ?? ctx.slots.default?.()) }))
  app.component('UAlert', Vue.defineComponent({ props: { title: String, description: String }, setup: (props, ctx) => () => Vue.h('aside', [props.title, props.description, ...(ctx.slots.default?.() ?? [])]) }))
  for (const name of ['UInput', 'USelect', 'UTextarea']) app.component(name, Vue.defineComponent({
    props: { modelValue: [String, Number, Boolean], disabled: Boolean, items: Array }, emits: ['update:modelValue'],
    setup: (props, ctx) => () => Vue.h(name === 'USelect' ? 'select' : 'input', {
      ...ctx.attrs, value: props.modelValue, disabled: props.disabled,
      onChange: (event: { target: { value: string } }) => {
        const option = (props.items as Array<{ value: unknown }> | undefined)?.find(option => String(option.value) === event.target.value)
        ctx.emit('update:modelValue', option?.value ?? event.target.value)
      }
    })
  }))
  app.component('UModal', Vue.defineComponent({ props: { open: Boolean, title: String, description: String }, emits: ['update:open'], setup: (props, ctx) => () => props.open ? Vue.h('section', { 'role': 'dialog', 'aria-label': props.title }, [props.description, ...(ctx.slots.body?.() ?? []), ...(ctx.slots.footer?.({ close: () => ctx.emit('update:open', false) }) ?? [])]) : null }))
  app.mount(root)
  const text = (node: HostNode = root): string => [node.text ?? '', ...node.children.map(text)].join(' ')
  const find = (predicate: (node: HostNode) => boolean, node: HostNode = root) => walk(node).filter(predicate)
  const control = (name: string) => {
    const field = find(node => node.props['data-field'] === name)[0]
    assert.ok(field, `Actual form field exists: ${name}`)
    const input = find(node => node.tag === 'input' || node.tag === 'select', field)[0]
    assert.ok(input)
    return input
  }
  return {
    state, company, session, route, calls, memory, text, find, control, focused: () => focused,
    async change(name: string, value: string) { (control(name).props.onChange as (event: unknown) => void)({ target: { value } }); await settle() },
    async click(label: string) {
      const button = find(node => node.tag === 'button' && (node.props.label === label || text(node).trim() === label))[0]
      assert.ok(button, `Visible lifecycle action exists: ${label}`)
      ;(button.props.onClick as () => void)()
      await settle()
    },
    async submit() { const form = find(node => node.tag === 'form')[0]; assert.ok(form); (form.props.onSubmit as () => void)(); await settle() },
    dispose() { app.unmount(); scope.stop(); if (previousStorage) Object.defineProperty(globalThis, 'sessionStorage', previousStorage); else Reflect.deleteProperty(globalThis, 'sessionStorage') }
  }
}

const draftBody = () => ({ erp_establishment_id: 6, model: '57', environment: 'homologation', document_kind: 'transport_service', idempotency_key: 'draft-lifecycle-original', source: { transport_service: { operation_nature: 'Payload original da operação' } } })
async function fillCte(page: ReturnType<typeof mountLifecycle>) {
  const values: Record<string, string> = {
    service_type: '0', emission_type: '1', cfop: '5353', rntrc: '12345678', service_value: '250.00', received_value: '250.00',
    tax_cst: '00', tax_base: '250.00', tax_rate: '12.00', tax_value: '30.00', cargo_description: 'Mercadorias diversas', cargo_measure_type: 'PESO BRUTO', cargo_unit: '01', cargo_quantity: '1.0000', cargo_value: '250.00',
    taker_role: '0', origin_uf: 'SP', origin_city_code: '3550308', origin_city: 'São Paulo', destination_uf: 'RJ', destination_city_code: '3304557', destination_city: 'Rio de Janeiro', pickup: '0'
  }
  for (const role of ['sender', 'receiver']) Object.assign(values, {
    [`${role}_cnpj`]: '11222333000181', [`${role}_name`]: 'Empresa sintética', [`${role}_street`]: 'Rua Real', [`${role}_number`]: '10',
    [`${role}_district`]: 'Centro', [`${role}_city_code`]: '3550308', [`${role}_city`]: 'São Paulo', [`${role}_postal_code`]: '01001000', [`${role}_uf`]: 'SP'
  })
  for (const [key, value] of Object.entries(values)) {
    const descriptor = erpTypedFiscalFieldDescriptors('57', 'transport_service').find(field => field.key === key)
    assert.ok(descriptor)
    await page.change(`source.${descriptor.path}`, value)
  }
}

describe('actual fiscal draft lifecycle', { concurrency: 1, timeout: 120_000 }, () => {
  it('preserves each typed model on 57 to 58 to 57 without copying facts or resetting authority', { timeout: 30_000 }, async (t) => {
    const page = mountLifecycle(); t.after(page.dispose); await settle(); await page.click('Novo rascunho')
    const authority = page.state.authorityGeneration.value
    await page.change('source.transport_service.operation_nature', 'Transporte revisado')
    await page.change('source.transport_service.service_type', '0')
    await page.change('model', '58')
    assert.equal(page.control('source.transport_manifest.vehicle.plate').props.value, '')
    await page.change('source.transport_manifest.vehicle.plate', 'ABC1D23')
    await page.change('model', '57')
    assert.equal(page.control('source.transport_service.operation_nature').props.value, 'Transporte revisado')
    assert.equal(page.control('source.transport_service.service_type').props.value, 0)
    await page.change('model', '58')
    assert.equal(page.control('source.transport_manifest.vehicle.plate').props.value, 'ABC1D23')
    assert.equal(page.state.authorityGeneration.value, authority, 'Native model list reads must not remount the context bank')
    assert.equal(page.calls.filter(([, options]) => options?.method === 'POST').length, 0)
  })

  it('preserves independent document kinds and original-document selections when changing kinds and models', { timeout: 30_000 }, async (t) => {
    const original = { id: 61, model: '57', environment: 'homologation', erp_establishment_id: 6, status: 'authorized', access_key: '35261011222333000181570010000001231123456780', artifact_ready: true, claims_provider_authorization: true }
    const page = mountLifecycle(async (path, options) => {
      if (options?.method === 'POST') throw { statusCode: 422, data: { errors: { source: ['Revise a solicitação antes de reenviar.'] } } }
      if (path.startsWith('/erp/fiscal/documents?')) return envelope([original])
      return defaultApi(path)
    })
    t.after(page.dispose); await settle(); await page.click('Novo rascunho')
    await page.change('source.transport_service.operation_nature', 'Serviço normal')
    await page.change('document_kind', 'complement')
    assert.equal(page.control('source.transport_service.operation_nature').props.value, '')
    await page.change('source.transport_service.operation_nature', 'Complemento revisto')
    await page.change('source_document_id', '61')
    await page.change('document_kind', 'substitution')
    await page.change('source.transport_service.operation_nature', 'Substituição revista')
    await page.change('document_kind', 'complement')
    assert.equal(page.control('source.transport_service.operation_nature').props.value, 'Complemento revisto')
    assert.equal(page.control('source_document_id').props.value, 61)
    await page.change('model', '58'); await page.change('model', '57')
    assert.equal(page.control('document_kind').props.value, 'complement')
    assert.equal(page.control('source_document_id').props.value, 61)
    await page.change('document_kind', 'transport_service')
    assert.equal(page.control('source.transport_service.operation_nature').props.value, 'Serviço normal')
    await fillCte(page); await page.submit()
    const first = page.calls.filter(([, options]) => options?.method === 'POST')[0]?.[1]?.body
    assert.ok(first?.idempotency_key, 'The actual form submits a native operation identity')
    await page.change('document_kind', 'complement'); await fillCte(page); await page.submit()
    const second = page.calls.filter(([, options]) => options?.method === 'POST')[1]?.[1]?.body
    assert.ok(second?.idempotency_key)
    assert.notEqual(second.idempotency_key, first.idempotency_key, 'Different document kinds never share an operation identity')
    await page.change('model', '58'); await page.change('model', '57'); await page.submit()
    await page.change('document_kind', 'transport_service'); await page.submit()
    const bodies = page.calls.filter(([, options]) => options?.method === 'POST').map(([, options]) => options?.body)
    assert.equal(bodies.length, 4)
    assert.deepEqual(bodies[2], second, 'The returned complement keeps its exact rejected-but-unsent facts and key')
    assert.deepEqual(bodies[3], first, 'The normal service retains its separate exact facts and key')
    assert.equal(page.state.pendingRecovery.value, null)
  })

  it('starts in browsing and offers clean close plus one dirty continue/discard decision across all local model slots', { timeout: 30_000 }, async (t) => {
    const page = mountLifecycle(); t.after(page.dispose); await settle()
    assert.equal(page.find(node => node.tag === 'form').length, 0)
    await page.click('Novo rascunho'); await page.click('Fechar rascunho')
    assert.equal(page.find(node => node.tag === 'form').length, 0)
    assert.equal(page.find(node => node.props.role === 'dialog').length, 0)
    await page.click('Novo rascunho'); await page.change('model', '55')
    const source = { id: 77, status: 'posted', erp_establishment_id: 6, commercial_snapshot: { accepted: { actor_id: 91 }, lines: [{ id: 31, product_snapshot: { product: { name: 'Fonte carregada' } } }] } }
    await page.change('model', '65')
    page.state.selectedSale.value = source; await settle(); await page.change('model', '55'); await page.click('Fechar rascunho')
    assert.equal(page.find(node => node.props.role === 'dialog').length, 0, 'Initial source hydration is a clean baseline')
    await page.click('Novo rascunho')
    page.state.selectedSale.value = source; await settle(); await page.change('identity.operation_type', '1')
    page.state.selectedSale.value = { ...source, id: 78 }; await settle(); await page.click('Fechar rascunho')
    assert.equal(page.find(node => node.props['aria-label'] === 'Descartar alterações?').length, 1, 'Later source hydration cannot erase a real edit')
    await page.click('Continuar editando')
    assert.equal(page.control('identity.operation_type').props.value, '1')
    await page.click('Fechar rascunho'); await page.click('Descartar alterações')
    await page.click('Novo rascunho'); await page.change('model', '57'); await page.change('source.transport_service.operation_nature', 'Texto não enviado')
    await page.change('model', '58'); await page.click('Fechar rascunho')
    assert.equal(page.find(node => node.props['aria-label'] === 'Descartar alterações?').length, 1)
    await page.click('Continuar editando'); await page.change('model', '57')
    assert.equal(page.control('source.transport_service.operation_nature').props.value, 'Texto não enviado')
    await page.click('Fechar rascunho'); await page.click('Descartar alterações')
    assert.equal(page.find(node => node.tag === 'form').length, 0)
    await page.click('Novo rascunho')
    assert.equal(page.control('source.transport_service.operation_nature').props.value, '')
    assert.equal(page.calls.filter(([, options]) => options?.method === 'POST').length, 0)
  })

  it('does not abandon a pending or unknown native operation even through an already-open discard confirmation', { timeout: 30_000 }, async (t) => {
    let rejectPost: (error: Error) => void = () => { throw new Error('Expected admitted POST') }
    const page = mountLifecycle(async (path, options) => options?.method === 'POST' ? new Promise((_resolve, reject) => { rejectPost = reject }) : defaultApi(path))
    t.after(page.dispose); await settle(); await page.click('Novo rascunho')
    await page.change('source.transport_service.operation_nature', 'Manter durante recuperação'); await page.click('Fechar rascunho')
    const body = draftBody()
    const submission = page.state.createDocumentDraft(body).catch(() => undefined)
    await settle()
    const record = JSON.stringify(page.state.pendingRecovery.value)
    const stored = [...page.memory.entries()]
    assert.equal(page.state.saving.value, true)
    await page.change('model', '58')
    assert.equal(page.control('model').props.value, '57')
    await page.click('Descartar alterações')
    assert.equal(page.control('source.transport_service.operation_nature').props.value, 'Manter durante recuperação')
    assert.equal(JSON.stringify(page.state.pendingRecovery.value), record)
    rejectPost(new Error('Response lost')); await submission; await settle()
    page.route.hash = '#mdfe'; await settle()
    assert.equal(page.route.hash, '#cte', 'Destination navigation keeps the locked editor and route consistent')
    assert.equal(page.state.documentModel.value, '57')
    assert.equal(page.control('model').props.value, '57')
    assert.equal(JSON.stringify(page.state.pendingRecovery.value), record)
    assert.deepEqual([...page.memory.entries()], stored)
    page.route.hash = '#configuration'; await settle()
    assert.equal(page.route.hash, '#cte', 'Another fiscal area cannot unmount an unknown editor')
    assert.equal(page.control('source.transport_service.operation_nature').props.value, 'Manter durante recuperação')
    await page.change('document_kind', 'complement')
    assert.equal(page.control('document_kind').props.value, 'transport_service')
    await page.click('Descartar alterações')
    assert.equal(JSON.stringify(page.state.pendingRecovery.value), record)
    assert.deepEqual([...page.memory.entries()], stored)
    assert.deepEqual(page.state.pendingRecovery.value?.body, body)
    assert.equal(page.calls.filter(([, options]) => options?.method === 'POST').length, 1)
    page.company.value.id = 8; page.route.hash = '#mdfe'; await settle()
    assert.equal(page.route.hash, '#mdfe', 'A new authority never restores the old private destination')
    assert.equal(page.find(node => node.tag === 'form').length, 0)
    assert.equal(page.find(node => node.props.role === 'dialog').length, 0)
    assert.deepEqual([...page.memory.entries()], stored, 'Original durable recovery stays scoped to its owner')
  })

  it('keeps entered text and focuses the actual nested 422 control without posting or refreshing automatically', { timeout: 30_000 }, async (t) => {
    const page = mountLifecycle(async (path, options) => {
      if (options?.method === 'POST') throw { statusCode: 422, data: { errors: { 'source.transport_service.sender.address.city_code': ['Revise o município do remetente.'] } } }
      return defaultApi(path)
    })
    t.after(page.dispose); await settle(); await page.click('Novo rascunho')
    await page.change('source.transport_service.operation_nature', 'Operação mantida')
    await page.change('source.transport_service.sender.address.city_code', '3550308')
    await assert.rejects(page.state.createDocumentDraft(draftBody())); await settle()
    assert.equal(page.control('source.transport_service.operation_nature').props.value, 'Operação mantida')
    assert.equal(page.focused()?.props['data-fiscal-field-key'], 'sender_city_code')
    assert.match(page.text(), /Revise o município do remetente/)
    assert.equal(page.state.pendingRecovery.value, null)
    await page.change('model', '58'); await page.change('model', '57')
    assert.equal(page.control('source.transport_service.operation_nature').props.value, 'Operação mantida')
    assert.equal(page.calls.filter(([, options]) => options?.method === 'POST').length, 1)
  })

  it('drops private form slots and discard decisions on company, session, establishment and grant changes', { timeout: 30_000 }, async (t) => {
    const page = mountLifecycle(); t.after(page.dispose); await settle()
    const boundaries = [
      () => { page.company.value.id = 8 },
      () => { page.session.value++ },
      () => { page.state.selectedEstablishmentId.value = 9 },
      () => { page.company.value.permissions = ['erp.fiscal.documents.index'] }
    ]
    for (const change of boundaries) {
      await page.state.load(6); await settle(); await page.click('Novo rascunho')
      await page.change('source.transport_service.operation_nature', 'Privado no contexto anterior'); await page.click('Fechar rascunho')
      change(); await settle()
      assert.equal(page.find(node => node.props['aria-label'] === 'Descartar alterações?').length, 0)
      assert.equal(page.find(node => node.tag === 'form').length, 0)
      assert.doesNotMatch(page.text(), /Privado no contexto anterior/)
      page.company.value.permissions = ['*']; await page.state.load(6); await settle(); await page.click('Novo rascunho')
      assert.equal(page.control('source.transport_service.operation_nature').props.value, '')
      await page.click('Fechar rascunho')
    }
    assert.equal(page.calls.filter(([, options]) => options?.method === 'POST').length, 0)
  })
})
