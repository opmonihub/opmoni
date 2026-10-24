import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

type Editor = ReturnType<typeof import('../app/composables/useErpCatalogEditor.ts').useErpCatalogEditor>
type Boundary = 'Company' | 'delegated Account' | 'session' | 'write grant'
type Account = { id: number, status: string, role: string }
type Options = { method?: string, body?: Record<string, unknown>, signal?: AbortSignal }
type Request = { path: string, options: Options, resolve(value: unknown): void, reject(error: unknown): void }
type HostNode = { tag: string, text: string, props: Record<string, unknown>, style: Record<string, string>, children: HostNode[], parent: HostNode | null }

const intents = {
  icms_cst: 'inherit', icms_csosn: 'unset', icms_rate: 'value', simple_national_credit_rate: 'unset',
  pis_cst: 'unset', pis_rate: 'unset', cofins_cst: 'unset', cofins_rate: 'unset',
  ipi_cst: 'unset', ipi_rate: 'unset', ibs_cbs_cst: 'unset', ibs_cbs_classification_code: 'unset'
}
const original = {
  id: 77, name: 'Produto da empresa anterior', sku: 'OLD-77', kind: 'goods', track_stock: true,
  erp_unit_id: 17, erp_brand_id: null, erp_category_id: null, sale_price: '10.0000',
  warranty_months: null, warranty_description: null, barcode: null, barcode_type: 'C128',
  ncm: '61091000', cest: null, origin_code: '0', icms_cst: '01', icms_csosn: null, icms_rate: '0.0000',
  pis_cst: null, erp_product_tax_profile_id: 12, tax_field_intents: intents,
  variants: [{ id: 91, name: 'Padrão', sku: 'OLD-77', sale_price: '0.0000', barcode: null, barcode_type: null, status: 'active' }],
  status: 'active'
}
const replacement = { ...original, id: 88, name: 'Produto do contexto atual', sku: 'NEW-88', erp_product_tax_profile_id: 24 }
const previewResponse = {
  data: {
    version: 'product-tax-v1', product_id: 77,
    values: { icms_cst: '20', icms_rate: '0.0000' },
    sources: {
      icms_cst: { source: 'profile', intent: 'inherit', profile_id: 13, revision: 2, revision_id: 22 },
      icms_rate: { source: 'product', intent: 'value' }, pis_cst: { source: 'unresolved', intent: 'unset' }
    },
    unresolved_fields: ['pis_cst'], profile: { profile_id: 13, revision: 2, revision_id: 22 },
    company_default: { revision: 4, profile_id: 12, profile_revision: 3, profile_revision_id: 31 },
    context: { tax_regime: '1', kind: 'goods', movement: null }, applicability: { profile: null, company_profile: null },
    company_settings_fingerprint: 'settings-4', fingerprint: 'reviewed-candidate-13',
    previous: {
      version: 'product-tax-v1', product_id: 77, values: { icms_cst: '01', icms_rate: '0.0000' },
      sources: { icms_cst: { source: 'profile', intent: 'inherit' }, icms_rate: { source: 'product', intent: 'value' } },
      unresolved_fields: ['pis_cst'], profile: { profile_id: 12, revision: 3, revision_id: 31 },
      company_default: { revision: 4, profile_id: 12, profile_revision: 3, profile_revision_id: 31 },
      context: { tax_regime: '1', kind: 'goods', movement: null }, applicability: { profile: null, company_profile: null },
      company_settings_fingerprint: 'settings-4', fingerprint: 'before-adoption-12'
    }
  }
}

async function settle() {
  await Vue.nextTick()
  await new Promise(resolve => setImmediate(resolve))
  await Vue.nextTick()
}

function mountAdoption(boundary: Boundary) {
  const user = Vue.ref({ id: 5 })
  const company = Vue.ref({
    id: 41, status: 'active', access_kind: boundary === 'delegated Account' ? 'delegated' : 'direct',
    role: boundary === 'delegated Account' ? 'member' : 'owner', delegated_office_id: boundary === 'delegated Account' ? 30 : null,
    permissions: ['erp.products.index', 'erp.products.show', 'erp.products.update', 'erp.product-tax-profiles.index', 'erp.units.index', 'erp.units.show']
  })
  const account = Vue.ref<Account | null>(boundary === 'delegated Account' ? { id: 30, status: 'active', role: 'operador' } : null)
  const cache = new Map<string, Vue.Ref>()
  const session = Vue.ref(1)
  cache.set('access-session-generation', session)
  const requests: Request[] = []
  const submitted: Record<string, unknown>[] = []
  const operations: Promise<void>[] = []
  let refreshes = 0
  let csrfCalls = 0
  const api = (path: string, options: Options = {}): Promise<unknown> => {
    if (options.method === 'PATCH' || path === '/erp/products/tax-preview')
      return new Promise((resolve, reject) => requests.push({ path, options, resolve, reject }))
    const url = new URL(path, 'http://test')
    if (url.pathname === '/erp/products/77') return Promise.resolve({ data: original })
    if (url.pathname === '/erp/products/88') return Promise.resolve({ data: replacement })
    const profiles = [12, 13, 24].map(id => ({ id, name: `Perfil ${id}`, status: 'active' }))
    const rows = url.pathname === '/erp/tax-settings/product-tax-profiles' ? profiles : url.pathname === '/erp/units' ? [{ id: 17, name: 'Unidade', status: 'active' }] : []
    const profile = /^\/erp\/tax-settings\/product-tax-profiles\/(12|13|24)$/.exec(url.pathname)
    if (profile) return Promise.resolve({ data: profiles.find(row => row.id === Number(profile[1])) })
    if (url.pathname === '/erp/units/17') return Promise.resolve({ data: { id: 17, name: 'Unidade', status: 'active' } })
    assert.ok(['/erp/units', '/erp/brands', '/erp/categories', '/erp/tax-settings/product-tax-profiles'].includes(url.pathname), `Unexpected native adoption request: ${path}`)
    return Promise.resolve({ data: rows, meta: { current_page: 1, last_page: 1, per_page: 15, total: rows.length } })
  }
  const bindings = {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch,
    onMounted: Vue.onMounted, onScopeDispose: Vue.onScopeDispose, nextTick: Vue.nextTick,
    useAuth: () => ({ user, currentCompany: company, currentAccount: account, refresh: async () => user.value }),
    useState: (key: string, initialize: () => unknown) => {
      if (!cache.has(key)) cache.set(key, Vue.ref(initialize()))
      return cache.get(key)!
    },
    useAccessApi: () => ({ api, refreshCsrf: async () => { csrfCalls++ } })
  }
  // Both the Form's preview and this editor import the real administration context.
  // Only Nuxt auth/state and the HTTP/CSRF/list-refresh boundaries are injected.
  const module = loadAppComposable<{ useErpCatalogEditor(path: string, refresh: () => Promise<void>): Editor }>('composables/useErpCatalogEditor.ts', bindings)
  const Form = compileVueComponent('components/erp/catalogs/ErpProductCatalogForm.vue', bindings)
  let editor!: Editor
  const Parent = Vue.defineComponent({
    setup() {
      editor = module.useErpCatalogEditor('/erp/products', async () => {
        refreshes++
      })
      return () => Vue.h('main', [
        Vue.h('p', { role: 'alert' }, editor.error.value),
        editor.open.value && editor.record.value && !editor.loadingDetail.value
          ? Vue.h(Form, {
              record: editor.record.value, mode: editor.mode.value, canSave: editor.canSave.value,
              busy: editor.mutationPending.value, fieldErrors: editor.fieldErrors.value,
              onSave: (payload: Record<string, unknown>) => {
                submitted.push(payload)
                operations.push(editor.save(payload))
              }
            })
          : null
      ])
    }
  })
  const element = (tag: string, text = ''): HostNode => ({ tag, text, props: {}, style: {}, children: [], parent: null })
  const root = element('root')
  const renderer = Vue.createRenderer<HostNode, HostNode>({
    patchProp(node, key, _previous, value) { node.props[key] = value },
    insert(node, parent, anchor = null) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
    },
    remove(node) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = null
    },
    createElement: tag => element(tag), createText: text => element('#text', text), createComment: text => element('#comment', text),
    setText(node, text) { node.text = text },
    setElementText(node, text) {
      node.text = text
      node.children = []
    },
    parentNode: node => node.parent, nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insertStaticContent(content, parent, anchor) {
      const node = element('#static', content.replace(/<[^>]*>/g, ''))
      this.insert(node, parent, anchor)
      return [node, node]
    }
  })
  const app = renderer.createApp(Parent)
  // Native UI component boundaries only: all app SFCs and composables stay real.
  app.component('UForm', Vue.defineComponent({
    props: ['disabled'], emits: ['submit'],
    setup: (props, ctx) => () => Vue.h('form', { disabled: props.disabled, onSubmit: () => ctx.emit('submit') }, ctx.slots.default?.())
  }))
  app.component('UFormField', Vue.defineComponent({
    props: ['name', 'label', 'error'],
    setup: (props, ctx) => () => Vue.h('label', { 'data-field': props.name }, [props.label, ...(ctx.slots.default?.() ?? []), props.error])
  }))
  app.component('UAccordion', Vue.defineComponent({
    props: ['items', 'modelValue'], emits: ['update:modelValue'],
    setup: (props, ctx) => () => Vue.h('div', (props.items as Array<{ label: string, value: string, slot: string }>).flatMap(item => [
      Vue.h('button', { type: 'button', onClick: () => ctx.emit('update:modelValue', [item.value]) }, item.label),
      ...(props.modelValue.includes(item.value) ? ctx.slots[item.slot]?.({ item }) ?? [] : [])
    ]))
  }))
  for (const name of ['UInput', 'USelect', 'USelectMenu']) app.component(name, Vue.defineComponent({
    props: ['modelValue', 'items', 'valueKey', 'labelKey', 'disabled'], emits: ['update:modelValue'],
    setup: (props, ctx) => () => Vue.h(name === 'UInput' ? 'input' : 'select', {
      ...ctx.attrs, value: props.modelValue, disabled: props.disabled,
      onChange: (event: { target: { value: string } }) => {
        const item = props.items?.find((row: Record<string, unknown>) => String(row[props.valueKey ?? 'value']) === event.target.value)
        ctx.emit('update:modelValue', item ? item[props.valueKey ?? 'value'] : event.target.value)
      }
    })
  }))
  app.component('UButton', Vue.defineComponent({
    props: ['label', 'disabled', 'loading'],
    setup: (props, ctx) => () => Vue.h('button', { ...ctx.attrs, disabled: props.disabled || props.loading }, props.label ?? ctx.slots.default?.())
  }))
  for (const name of ['UCheckbox', 'UBadge', 'UIcon', 'UAlert', 'UModal']) app.component(name, Vue.defineComponent({
    props: ['label', 'title', 'description'], setup: (props, ctx) => () => Vue.h('div', [props.label, props.title, props.description, ...(ctx.slots.default?.() ?? [])])
  }))
  app.mount(root)
  const walk = (node: HostNode): HostNode[] => [node, ...node.children.flatMap(walk)]
  const text = () => walk(root).map(node => node.text).join(' ')
  const find = (predicate: (node: HostNode) => boolean) => walk(root).find(predicate)
  const control = (name: string) => {
    const node = find(node => ['input', 'select'].includes(node.tag) && node.props.name === name)
    assert.ok(node, `Actual product control exists: ${name}`)
    return node
  }
  return {
    editor, requests, submitted, operations, company, account, session, text, control,
    refreshes: () => refreshes, csrfCalls: () => csrfCalls,
    async click(label: string) {
      const button = find(node => node.tag === 'button' && (node.props.label === label || node.text === label))
      assert.ok(button, `Actual product action exists: ${label}`)
      assert.notEqual(button.props.disabled, true)
      assert.equal(typeof button.props.onClick, 'function')
      ;(button.props.onClick as () => void)()
      await settle()
    },
    async change(name: string, value: string) {
      const node = control(name)
      assert.notEqual(node.props.disabled, true)
      ;(node.props.onChange as (event: unknown) => void)({ target: { value } })
      await settle()
    },
    async submit() {
      const form = find(node => node.tag === 'form')
      assert.ok(form, 'The real product form is mounted')
      assert.notEqual(form.props.disabled, true)
      ;(form.props.onSubmit as () => void)()
      await settle()
    },
    close() {
      app.unmount()
      for (const request of requests) request.resolve({ data: original })
    }
  }
}

describe('persisted product tax adoption keeps current authority', { concurrency: 1, timeout: 120_000 }, () => {
  for (const boundary of ['Company', 'delegated Account', 'session', 'write grant'] as const) {
    for (const outcome of ['success', '403'] as const) {
      it(`ignores a late adoption PATCH ${outcome} after ${boundary} changes without stale retry`, { timeout: 30_000 }, async (t) => {
        const page = mountAdoption(boundary)
        t.after(page.close)
        await page.editor.edit(77)
        await settle()
        await page.click('Fiscal')
        await page.change('erp_product_tax_profile_id', '13')
        await page.click('Pré-visualizar tributos')
        const preview = page.requests.find(request => request.path === '/erp/products/tax-preview')
        assert.ok(preview)
        assert.equal(preview.options.method, 'POST')
        assert.equal(preview.options.body?.product_id, 77)
        assert.equal(preview.options.body?.erp_product_tax_profile_id, 13)
        assert.deepEqual(preview.options.body?.tax_field_intents, intents)
        assert.equal(preview.options.body?.icms_rate, '0.0000')
        assert.equal(preview.options.body?.pis_cst, null)
        assert.equal(Object.hasOwn(preview.options.body!, 'icms_cst'), false)
        preview.resolve(previewResponse)
        await settle()
        assert.match(page.text(), /Perfil #13/)
        await page.click('Adotar configuração revisada')
        await page.submit()
        assert.equal(page.submitted.length, 1, 'The public Form save event reaches the actual editor')
        const adopted = page.submitted[0]!
        assert.equal(adopted.erp_product_tax_profile_id, 13)
        assert.deepEqual(adopted.tax_field_intents, intents)
        assert.equal(adopted.sale_price, '10.0000')
        assert.equal(adopted.icms_rate, '0.0000')
        assert.equal(adopted.pis_cst, null)
        assert.equal(Object.hasOwn(adopted, 'icms_cst'), false)
        assert.equal((adopted.variants as Array<Record<string, unknown>>)[0]?.sale_price, '0.0000')
        const mutation = page.requests.find(request => request.options.method === 'PATCH')
        assert.ok(mutation)
        assert.equal(mutation.path, '/erp/products/77')
        assert.deepEqual(mutation.options.body, adopted)
        assert.equal(page.csrfCalls(), 1)
        const previousContext = page.editor.contextKey.value
        if (boundary === 'Company') page.company.value.id = 42
        else if (boundary === 'delegated Account') page.account.value = { id: 31, status: 'active', role: 'operador' }
        else if (boundary === 'session') page.session.value++
        else page.company.value.permissions = page.company.value.permissions.filter(permission => permission !== 'erp.products.update')
        assert.notEqual(page.editor.contextKey.value, previousContext, 'The actual administration key includes this boundary')
        assert.equal(page.editor.record.value, null)
        assert.equal(page.editor.open.value, false)
        assert.equal(mutation.options.signal?.aborted, true)
        await settle()
        assert.doesNotMatch(page.text(), /Produto da empresa anterior|Perfil #13|Adotar configuração revisada/)
        const count = page.requests.length
        await page.editor.save(adopted)
        assert.equal(page.requests.length, count, 'The old edit cannot be retried before a fresh authorized record is opened')
        if (page.editor.permissions.edit.value) {
          await page.editor.edit(88)
          await settle()
          await page.change('name', 'Rascunho atual preservado')
          await page.submit()
        } else if (page.editor.permissions.show.value) {
          await page.editor.view(88)
          await settle()
        }
        const currentRecord = page.editor.record.value
        const currentError = page.editor.error.value
        const currentSaving = page.editor.saving.value
        const currentText = page.text()
        const currentRequests = page.requests.length
        assert.equal(page.refreshes(), 0)
        if (outcome === 'success') mutation.resolve({ data: { ...original, erp_product_tax_profile_id: 13 } })
        else mutation.reject({ status: 403, data: { message: 'Erro obsoleto de outra autorização.' } })
        await page.operations[0]
        await settle()
        assert.equal(page.editor.record.value, currentRecord)
        assert.equal(page.editor.error.value, currentError)
        assert.equal(page.editor.saving.value, currentSaving, 'The old finally cannot release a newer save owner')
        assert.equal(page.editor.authorityBlocked.value, false, 'An old 403 cannot block current authority')
        assert.equal(page.text(), currentText)
        assert.doesNotMatch(page.text(), /Produto da empresa anterior|Perfil #13|Adotar configuração revisada|Erro obsoleto/)
        assert.equal(page.requests.length, currentRequests, 'Obsolete completion neither retries nor refreshes')
        assert.equal(page.refreshes(), 0)
        const mutations = page.requests.filter(request => request.options.method === 'PATCH')
        assert.equal(mutations.length, boundary === 'Company' || boundary === 'session' ? 2 : 1)
        if (mutations.length === 2) {
          assert.equal(page.editor.open.value, true)
          assert.equal(page.editor.record.value?.id, 88)
          assert.equal(page.control('name').props.value, 'Rascunho atual preservado')
          assert.equal(mutations[1]!.options.body?.erp_product_tax_profile_id, 24)
          mutations[1]!.resolve({ data: replacement })
          await page.operations[1]
          await settle()
          assert.equal(page.refreshes(), 1, 'Only the explicitly submitted current product can confirm success')
        } else {
          assert.equal(page.editor.canSave.value, false)
          assert.equal(page.csrfCalls(), 1)
        }
        assert.equal(page.requests.filter(request => request.path === '/erp/products/tax-preview').length, 1)
      })
    }
  }
})
