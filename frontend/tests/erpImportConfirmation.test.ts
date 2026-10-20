import assert from 'node:assert/strict'
import { it } from 'node:test'
import * as Vue from 'vue'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import type { useErpCatalogImport as CreateImport } from '../app/composables/useErpCatalogImport.ts'
import type { ErpImportPreview } from '../shared/types/erpCatalogImport.ts'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

type HostNode = {
  tag: string
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
  textContent: string
}
type Pending = {
  url: string
  options: {
    method?: string
    body?: unknown
    headers?: Record<string, string>
    signal?: AbortSignal
  }
  resolve(value: unknown): void
  reject(reason: unknown): void
}
function mountImport(realSelector = false) {
  const requests: Pending[] = []
  const user = Vue.ref<{
    id: number
  } | null>({ id: 5 })
  const company = Vue.ref({ id: 41, status: 'active', access_kind: 'direct', role: 'owner', permissions: ['*'] })
  const generation = Vue.ref(1)
  let refreshHook = () => {}
  const bindings = {
    computed: Vue.computed, ref: Vue.ref, watch: Vue.watch, onScopeDispose: Vue.onScopeDispose,
    useState: () => generation,
    useAuth: () => ({ user, currentCompany: company, currentAccount: Vue.ref(null), refresh: async () => {
      refreshHook()
      return user.value
    } }),
    useAccessApi: () => ({ refreshCsrf: async () => undefined, api: (url: string, options = {}) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })) })
  }
  const module = loadAppComposable<{
    useErpCatalogImport: typeof CreateImport
  }>('composables/useErpCatalogImport.ts', bindings)
  let workspace!: ReturnType<typeof CreateImport>
  const node = (tag: string, textContent = ''): HostNode => ({ tag, textContent, props: {}, children: [], parent: null })
  const renderer = Vue.createRenderer<HostNode, HostNode>({
    patchProp(node, key, _old, value) {
      node.props[key] = value
    },
    insert(node, parent, anchor = null) {
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0)
        parent.children.push(node)
      else
        parent.children.splice(index, 0, node)
    },
    remove(node) {
      if (node.parent)
        node.parent.children.splice(node.parent.children.indexOf(node), 1)
    },
    createElement: tag => node(tag), createText: text => node('#text', text), createComment: text => node('#comment', text),
    setText(node, text) {
      node.textContent = text
    }, setElementText(node, text) {
      node.textContent = text
      node.children = []
    },
    parentNode: node => node.parent, nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insertStaticContent(content, parent, anchor) {
      const child = node('#static', content)
      this.insert(child, parent, anchor)
      return [child, child]
    }
  })
  const listModule = loadAppComposable('composables/useErpCatalogList.ts', bindings)
  const contextModule = loadAppComposable('composables/useErpAdministrationContext.ts', bindings)
  const moduleMocks: Record<string, unknown> = {
    '~/composables/useErpCatalogImport': { useErpCatalogImport: () => {
      workspace = module.useErpCatalogImport()
      return workspace
    } },
    '~/composables/useErpCatalogList': listModule,
    '~/composables/useErpAdministrationContext': contextModule
  }
  if (!realSelector) {
    moduleMocks['~/components/erp/catalogs/ErpCatalogReferenceSelect.vue'] = Vue.defineComponent({
      props: ['modelValue', 'disabled', 'error'],
      emits: ['selection-validity'],
      setup(props, { emit }) {
        Vue.watch(() => props.modelValue, id => emit('selection-validity', { id, valid: id !== null }), { immediate: true, flush: 'sync' })
        return () => Vue.h('section', { 'data-component': 'ErpCatalogReferenceSelect', 'disabled': props.disabled }, props.error)
      }
    })
  }
  const page = compileVueComponent('pages/erp/cadastros/importacao.vue', { ...bindings, definePageMeta() {} }, moduleMocks)
  const app = renderer.createApp(page)
  for (const name of ['ErpCatalogPageShell', 'UCard', 'UAlert', 'UFormField', 'UFileUpload', 'USelect', 'UButton', 'UTable', 'USelectMenu', 'UBadge']) {
    app.component(name, Vue.defineComponent({ props: ['label', 'title', 'description', 'data', 'disabled', 'modelValue', 'error', 'name'], emits: ['update:modelValue'], setup(props, { attrs, slots, emit }) {
      return () => Vue.h(name === 'UButton' ? 'button' : 'section', { ...attrs, 'data-component': name, 'disabled': props.disabled, 'modelValue': props.modelValue, 'onChange': (value: unknown) => emit('update:modelValue', value) }, [props.label, props.title, props.description, props.error, ...(props.data ? props.data.map((row: object) => Vue.h('div', [JSON.stringify(row), slots['validation-cell']?.({ row: { original: row } })])) : []), slots.header?.(), slots.default?.()])
    } }))
  }
  const root = node('root')
  app.mount(root)
  const find = (component: string): HostNode[] => {
    const result: HostNode[] = []
    const visit = (node: HostNode) => {
      if (node.props['data-component'] === component)
        result.push(node)
      node.children.forEach(visit)
    }
    visit(root)
    return result
  }
  const text = (node: HostNode): string => node.textContent + node.children.map(text).join('')
  return { workspace, requests, company, user, generation, setRefreshHook(hook: () => void) {
    refreshHook = hook
  }, find, root, text: () => text(root), unmount: () => app.unmount() }
}
it('mounts the actual import page with native file upload and explicit preview/cancel/confirm controls', (t) => {
  const storage = memoryStorage()
  t.after(storage.restore)
  const page = mountImport()
  t.after(page.unmount)
  assert.equal(page.find('UFileUpload').length, 1)
  assert.match(page.text(), /Pré-visualizar/)
  assert.match(page.text(), /Confirmar importação/)
  assert.match(page.text(), /Cancelar/)
})
function memoryStorage(initial = new Map<string, string>()) {
  const original = Object.getOwnPropertyDescriptor(globalThis, 'sessionStorage')
  const storage = { getItem: (key: string) => initial.get(key) ?? null, setItem: (key: string, value: string) => {
    initial.set(key, value)
  }, removeItem: (key: string) => {
    initial.delete(key)
  } }
  Object.defineProperty(globalThis, 'sessionStorage', { configurable: true, value: storage })
  return { values: initial, storage, restore() {
    if (original)
      Object.defineProperty(globalThis, 'sessionStorage', original)
    else
      Reflect.deleteProperty(globalThis, 'sessionStorage')
  } }
}
async function settle() {
  await Promise.resolve()
  await Vue.nextTick()
  await Promise.resolve()
  await Vue.nextTick()
}
function preview(kind: 'products' | 'contacts' = 'products', invalid = false): ErpImportPreview {
  return { token: '00000000-0000-4000-8000-000000000001', kind, schema: `${kind}-v1`, expires_at: '2026-10-20T12:00:00Z', state: 'pending', rows: [{ record: 1, line: 2, name: 'Café', sku: '0', sale_price: '1.0000' }, { record: 2, line: 3, name: 'Fornecedor', sku: '0001' }], errors: invalid ? [{ record: 2, line: 3, field: 'name', code: 'invalid_field', message: 'Nome inválido' }] : [], totals: { records: 2, valid: invalid ? 1 : 2, invalid: invalid ? 1 : 0 }, result: null }
}
async function prepare(page: ReturnType<typeof mountImport>, kind: 'products' | 'contacts' = 'products', invalid = false) {
  page.workspace.kind.value = kind
  page.workspace.file.value = new File(['name\nCafé'], 'private.csv', { type: 'text/csv' })
  const pending = page.workspace.createPreview()
  await settle()
  page.requests.at(-1)!.resolve({ data: preview(kind, invalid) })
  await pending
  page.workspace.unitId.value = kind === 'products' ? 7 : null
  await settle()
}
it('renders real totals/errors and disables confirm for invalid product/contact previews', async (t) => {
  const storage = memoryStorage()
  t.after(storage.restore)
  for (const kind of ['products', 'contacts'] as const) {
    const page = mountImport()
    t.after(page.unmount)
    await prepare(page, kind, true)
    assert.match(page.text(), /2 registros · 1 válidos · 1 inválidos/)
    assert.match(page.text(), /confirmação está bloqueada/)
    assert.match(page.text(), /Nome inválido/)
    assert.equal(page.workspace.canConfirm.value, false)
    assert.equal(page.find('UTable').length, 1)
    assert.equal(page.find('ErpCatalogReferenceSelect').length, kind === 'products' ? 1 : 0)
    assert.ok(page.find('UButton').some(button => button.props.disabled === true))
  }
})
it('keeps file/choice/identity after failed confirm and lookup404 reuses exactly the original key', async (t) => {
  const storage = memoryStorage()
  t.after(storage.restore)
  const page = mountImport()
  t.after(page.unmount)
  await prepare(page)
  const file = page.workspace.file.value
  const first = page.workspace.confirm()
  await settle()
  const sent = page.requests.at(-1)!
  const stored = JSON.parse([...storage.values.values()][0]!)
  assert.equal(stored.state, 'submitted')
  assert.equal('rows' in stored, false)
  assert.equal(JSON.stringify(stored).includes('private.csv'), false)
  sent.reject({ statusCode: 503 })
  await first
  assert.equal(page.workspace.file.value, file)
  assert.equal(page.workspace.unitId.value, 7)
  assert.equal(page.workspace.unknownOutcome.value, true)
  const lookup = page.workspace.reconcile()
  await settle()
  page.requests.at(-1)!.reject({ statusCode: 404, data: { code: 'operation_not_recorded' } })
  await lookup
  assert.equal(page.workspace.canConfirm.value, true)
  const retry = page.workspace.confirm()
  await settle()
  const repeated = page.requests.at(-1)!
  assert.deepEqual(repeated.options.headers, sent.options.headers)
  assert.deepEqual(repeated.options.body, sent.options.body)
  repeated.resolve({ data: { created: 2 } })
  await retry
  assert.equal(page.workspace.success.value, 2)
  assert.equal(page.workspace.file.value, null)
  assert.equal(storage.values.size, 0)
})
it('restores durable preview after full reload and reconciles a committed lost response without CSV browser storage', async (t) => {
  const storage = memoryStorage()
  t.after(storage.restore)
  const first = mountImport()
  await prepare(first, 'contacts')
  const sent = first.workspace.confirm()
  await settle()
  first.requests.at(-1)!.reject({ statusCode: 0 })
  await sent
  first.unmount()
  assert.equal(storage.values.size, 1)
  const second = mountImport()
  t.after(second.unmount)
  await settle()
  assert.match(second.requests.at(-1)!.url, /contacts\/preview\/read$/)
  second.requests.at(-1)!.resolve({ data: preview('contacts') })
  await settle()
  assert.match(second.requests.at(-1)!.url, /operations\/lookup/)
  second.requests.at(-1)!.resolve({ data: { state: 'completed', result: { created: 2 } } })
  await settle()
  assert.equal(second.workspace.success.value, 2)
  assert.equal(second.workspace.file.value, null)
  assert.equal(storage.values.size, 0)
})
it('ignores delayed401/error/finally after Company/actor/session changes and retains the newer busy state', async (t) => {
  const storage = memoryStorage()
  t.after(storage.restore)
  const page = mountImport()
  t.after(page.unmount)
  page.workspace.file.value = new File(['name\nA'], 'a.csv')
  const old = page.workspace.createPreview()
  await settle()
  const stale = page.requests.at(-1)!
  page.generation.value++
  page.company.value = { ...page.company.value, id: 42 }
  page.user.value = { id: 6 }
  page.workspace.file.value = new File(['name\nB'], 'b.csv')
  const next = page.workspace.createPreview()
  await settle()
  stale.reject({ statusCode: 401, data: { message: 'Old session' } })
  await old
  assert.equal(stale.options.signal?.aborted, true)
  assert.equal(page.workspace.busy.value, true)
  assert.equal(page.workspace.authorityBlocked.value, false)
  assert.equal(page.workspace.error.value, '')
  page.requests.at(-1)!.resolve({ data: preview() })
  await next
  assert.equal(page.workspace.preview.value?.rows[0]?.name, 'Café')
})
it('blocks an unrecoverable send when browser storage fails and cancels a preview with no confirm request', async (t) => {
  const storage = memoryStorage()
  t.after(storage.restore)
  const page = mountImport()
  t.after(page.unmount)
  await prepare(page)
  storage.storage.setItem = () => {
    throw new Error('Unavailable')
  }
  const before = page.requests.length
  await page.workspace.confirm()
  assert.equal(page.requests.length, before)
  assert.match(page.workspace.error.value, /armazenamento/)
  page.workspace.cancel()
  assert.equal(page.workspace.preview.value, null)
  assert.equal(page.workspace.file.value, null)
  assert.equal(page.requests.length, before)
})

it('retains editable unit and file after a definite422 then denies viewer changes with private state cleared', async (t) => {
  const storage = memoryStorage()
  t.after(storage.restore)
  const page = mountImport()
  t.after(page.unmount)
  await prepare(page)
  const first = page.workspace.confirm()
  await settle()
  const originalKey = page.requests.at(-1)!.options.headers
  page.requests.at(-1)!.reject({ statusCode: 422, data: { errors: { erp_unit_id: ['Unidade inválida'] } } })
  await first
  await settle()
  assert.equal(page.workspace.unknownOutcome.value, false)
  assert.match(page.text(), /Unidade inválida/)
  page.workspace.unitId.value = 8
  const retry = page.workspace.confirm()
  await settle()
  assert.deepEqual(page.requests.at(-1)!.options.headers, originalKey)
  page.requests.at(-1)!.resolve({ data: { created: 2 } })
  await retry
  await prepare(page)
  page.company.value = { ...page.company.value, permissions: ['erp.read'], role: 'viewer' }
  await settle()
  assert.equal(page.workspace.canImport.value, false)
  assert.equal(page.workspace.preview.value, null)
  assert.equal(page.workspace.file.value, null)
  assert.match(page.text(), /não tem permissão/)
})
it('restores an unsent preview for review without an automatic confirm and ignores success after mode changes', async (t) => {
  const storage = memoryStorage()
  t.after(storage.restore)
  const first = mountImport()
  await prepare(first)
  first.unmount()
  const page = mountImport()
  t.after(page.unmount)
  await settle()
  assert.match(page.requests.at(-1)!.url, /preview\/read$/)
  page.requests.at(-1)!.resolve({ data: preview() })
  await settle()
  assert.equal(page.workspace.file.value, null)
  assert.equal(page.workspace.preview.value?.totals.records, 2)
  assert.equal(page.workspace.canConfirm.value, true)
  assert.equal(page.requests.length, 1)
  page.workspace.file.value = new File(['name\nA'], 'another.csv')
  const pending = page.workspace.createPreview()
  await settle()
  const stale = page.requests.at(-1)!
  page.workspace.kind.value = 'contacts'
  stale.resolve({ data: preview() })
  await pending
  assert.equal(page.workspace.preview.value, null)
  assert.equal(page.workspace.busy.value, false)
  assert.equal(stale.options.signal?.aborted, true)
})

it('uses separate preview and confirm action grants for granular operators', async (t) => {
  const storage = memoryStorage()
  t.after(storage.restore)
  const page = mountImport()
  t.after(page.unmount)
  page.company.value = { ...page.company.value, role: 'operator', permissions: ['erp.catalog.import.products.preview'] }
  page.workspace.file.value = new File(['name\nA'], 'a.csv')
  const sent = page.workspace.createPreview()
  await settle()
  assert.equal(page.requests.length, 1)
  page.requests[0]!.resolve({ data: preview() })
  await sent
  assert.equal(page.workspace.canConfirm.value, false)
  page.company.value = { ...page.company.value, permissions: ['erp.catalog.import.products.confirm'] }
  page.workspace.file.value = new File(['name\nA'], 'a.csv')
  const count = page.requests.length
  await page.workspace.createPreview()
  assert.equal(page.requests.length, count)
})
it('keeps original submitted identity through expired/transient read and lookup404, allowing only original retry', async (t) => {
  const storage = memoryStorage()
  t.after(storage.restore)
  for (const status of [422, 503]) {
    const original = mountImport()
    await prepare(original, 'contacts')
    const sent = original.workspace.confirm()
    await settle()
    const firstRequest = original.requests.at(-1)!
    firstRequest.reject({ statusCode: 503 })
    await sent
    original.unmount()
    const page = mountImport()
    await settle()
    page.requests.at(-1)!.reject({ statusCode: status, data: { errors: { token: ['Pré-visualização expirada.'] } } })
    await settle()
    assert.match(page.requests.at(-1)!.url, /operations\/lookup/)
    page.requests.at(-1)!.reject({ statusCode: 404, data: { code: 'operation_not_recorded' } })
    await settle()
    assert.equal(page.workspace.preview.value, null)
    assert.equal(page.workspace.lockedChoices.value, true)
    assert.equal(page.workspace.canConfirm.value, true)
    assert.match(page.text(), /pré-visualização ainda não foi recuperada/)
    if (status === 503) {
      const readRetry = page.workspace.recover()
      await settle()
      assert.match(page.requests.at(-1)!.url, /contacts\/preview\/read$/)
      page.requests.at(-1)!.resolve({ data: preview('contacts') })
      await settle()
      page.requests.at(-1)!.reject({ statusCode: 404, data: { code: 'operation_not_recorded' } })
      await readRetry
      assert.equal(page.workspace.preview.value?.totals.records, 2)
    }
    const retry = page.workspace.confirm()
    await settle()
    assert.deepEqual(page.requests.at(-1)!.options.headers, firstRequest.options.headers)
    assert.deepEqual(page.requests.at(-1)!.options.body, firstRequest.options.body)
    if (status === 422) {
      page.requests.at(-1)!.reject({ statusCode: 422, data: { errors: { token: ['Pré-visualização expirada.'] } } })
      await retry
      assert.equal(page.workspace.lockedChoices.value, false)
      page.workspace.cancel()
    } else {
      page.requests.at(-1)!.resolve({ data: { created: 2 } })
      await retry
      assert.equal(page.workspace.success.value, 2)
    }
    assert.equal(storage.values.size, 0)
    page.unmount()
  }
})
it('preserves a submitted identity when authority refresh actually changes session and grants', async (t) => {
  const storage = memoryStorage()
  t.after(storage.restore)
  const page = mountImport()
  t.after(page.unmount)
  await prepare(page, 'contacts')
  const sent = page.workspace.confirm()
  await settle()
  page.requests.at(-1)!.reject({ statusCode: 401 })
  await sent
  assert.equal(page.workspace.file.value, null)
  assert.equal(storage.values.size, 1)
  const original = [...storage.values.values()][0]
  page.setRefreshHook(() => {
    page.company.value = { ...page.company.value, role: 'operator', permissions: ['erp.catalog.import.contacts.confirm'] }
    page.generation.value++
  })
  const refreshed = page.workspace.refreshAuthority()
  await settle()
  assert.equal([...storage.values.values()][0], original)
  assert.match(page.requests.at(-1)!.url, /contacts\/preview\/read$/)
  page.requests.at(-1)!.resolve({ data: { ...preview('contacts'), state: 'completed', result: { created: 2 } } })
  await refreshed
  await settle()
  assert.equal(page.workspace.success.value, 2)
  assert.equal(storage.values.size, 0)
})
it('mounts the actual unit selector and blocks first confirmation during load, error and archived selection', async (t) => {
  const storage = memoryStorage()
  t.after(storage.restore)
  const page = mountImport(true)
  t.after(page.unmount)
  await prepare(page)
  assert.equal(page.workspace.canConfirm.value, false)
  const details = page.requests.filter(request => request.url === '/erp/units/7')
  assert.ok(details.length)
  page.requests.filter(request => request.url.startsWith('/erp/units?')).forEach(request => request.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }))
  details.at(-1)!.reject({ statusCode: 503 })
  await settle()
  assert.equal(page.workspace.canConfirm.value, false)
  page.workspace.unitId.value = 8
  await settle()
  page.requests.at(-1)!.resolve({ data: { id: 8, status: 'archived', short_name: 'CX' } })
  await settle()
  assert.equal(page.workspace.canConfirm.value, false)
  page.workspace.unitId.value = 9
  await settle()
  page.requests.at(-1)!.resolve({ data: { id: 9, status: 'active', short_name: 'UN' } })
  await settle()
  assert.equal(page.workspace.canConfirm.value, true)
})

it('recovers an original completed product result while the real old unit is archived', async (t) => {
  const storage = memoryStorage()
  t.after(storage.restore)
  const original = mountImport()
  await prepare(original)
  const sent = original.workspace.confirm()
  await settle()
  original.requests.at(-1)!.reject({ statusCode: 503 })
  await sent
  original.unmount()
  const page = mountImport(true)
  t.after(page.unmount)
  await settle()
  page.requests.filter(request => request.url.startsWith('/erp/units?')).forEach(request => request.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }))
  page.requests.find(request => request.url === '/erp/units/7')!.resolve({ data: { id: 7, status: 'archived', short_name: 'CX' } })
  await settle()
  assert.equal(page.workspace.unitReady.value, false)
  page.requests.find(request => request.url.endsWith('/products/preview/read'))!.resolve({ data: { ...preview(), state: 'completed', result: { created: 2 } } })
  await settle()
  assert.equal(page.workspace.success.value, 2)
  assert.equal(storage.values.size, 0)
})
it('does not overwrite submitted recovery when refreshed authority permits only a new preview', async (t) => {
  const storage = memoryStorage()
  t.after(storage.restore)
  const page = mountImport()
  t.after(page.unmount)
  await prepare(page, 'contacts')
  const sent = page.workspace.confirm()
  await settle()
  page.requests.at(-1)!.reject({ statusCode: 401 })
  await sent
  const original = [...storage.values.values()][0]
  page.setRefreshHook(() => {
    page.company.value = { ...page.company.value, role: 'operator', permissions: ['erp.catalog.import.contacts.preview'] }
    page.generation.value++
  })
  await page.workspace.refreshAuthority()
  await settle()
  assert.equal(page.workspace.canPreview.value, true)
  assert.equal(page.workspace.canConfirmOrRecover.value, false)
  page.workspace.file.value = new File(['name\nAnother'], 'another.csv')
  const count = page.requests.length
  const previewRequest = page.workspace.createPreview()
  await settle()
  assert.equal([...storage.values.values()][0], original)
  assert.equal(page.requests.length, count)
  await previewRequest
  assert.equal(page.workspace.lockedChoices.value, true)
  assert.match(page.text(), /confirmação anterior/)
})

it('resolves the nested unit selector through the page import without a global component alias', (t) => {
  const storage = memoryStorage()
  t.after(storage.restore)
  const page = mountImport(true)
  t.after(page.unmount)
  assert.equal(page.find('USelectMenu').length, 1)
  assert.match(page.text(), /Unidade dos produtos/)
  assert.ok(page.requests.some(request => request.url.startsWith('/erp/units?')))
})
