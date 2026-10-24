import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

type ApiOptions = { method?: string, body?: Record<string, unknown>, headers?: Record<string, string> }
type ApiCall = { path: string, options?: ApiOptions }
type InitializationPreview = {
  preview_version: string
  preset_version: string
  revision: number
  sale_numbering_adopted: boolean
  fingerprint: string
  has_conflicts: boolean
  items: Array<{ key: string, status: string, reason: string, before: unknown, after: unknown, effective_changed: boolean }>
}
type InitializationOutcome = Omit<InitializationPreview, 'fingerprint'> & { completed: boolean }

const previewResult: InitializationPreview = {
  preview_version: 'setup-v1',
  preset_version: 'br-basic-v1',
  revision: 0,
  sale_numbering_adopted: false,
  fingerprint: 'a'.repeat(64),
  has_conflicts: false,
  items: [
    { key: 'operating_settings.time_format', status: 'create', reason: 'missing_preference', before: 12, after: 24, effective_changed: true },
    { key: 'unit', status: 'reuse', reason: 'compatible_native_identity', before: 11, after: 11, effective_changed: false }
  ]
}
const completeResult: InitializationOutcome = {
  preview_version: 'setup-v1',
  preset_version: 'br-basic-v1',
  revision: 1,
  sale_numbering_adopted: false,
  has_conflicts: false,
  items: previewResult.items,
  completed: true
}

function initializationHarness(api: (path: string, options?: ApiOptions) => Promise<unknown>, role = 'owner', accessKind = 'direct', permissions = ['erp.company.settings.update'], accountContext: { id: number, status: string, role: string } | null = { id: 3, status: 'active', role: 'admin' }) {
  const user = Vue.ref({ id: 91, is_super_admin: false })
  const company = Vue.ref({ id: 7, status: 'active', access_kind: accessKind, role, permissions, delegated_office_id: accessKind === 'delegated' ? 3 : null })
  const account = Vue.ref(accountContext)
  const sessionGeneration = Vue.ref(0)
  const stateCache = new Map<string, Vue.Ref>()
  stateCache.set('access-session-generation', sessionGeneration)
  const bindings = {
    ref: Vue.ref,
    reactive: Vue.reactive,
    computed: Vue.computed,
    watch: Vue.watch,
    onMounted: Vue.onMounted,
    onScopeDispose: Vue.onScopeDispose,
    useAccessApi: () => ({ api, refreshCsrf: async () => {} }),
    useAuth: () => ({ user, currentCompany: company, currentAccount: account }),
    useState: (name: string, init: () => unknown) => {
      if (!stateCache.has(name)) stateCache.set(name, Vue.ref(init()))
      return stateCache.get(name)!
    }
  }
  const source = loadAppComposable<{ useErpCompanyInitialization: () => unknown }>('composables/useErpCompanyInitialization.ts', bindings)
  const scope = Vue.effectScope()
  const workspace = scope.run(() => source.useErpCompanyInitialization())!
  return { workspace, user, company, account, sessionGeneration, dispose: () => scope.stop() }
}

function sessionStorageFixture() {
  const values = new Map<string, string>()
  const previous = Object.getOwnPropertyDescriptor(globalThis, 'sessionStorage')
  Object.defineProperty(globalThis, 'sessionStorage', {
    configurable: true,
    value: {
      getItem: (key: string) => values.get(key) ?? null,
      setItem: (key: string, value: string) => values.set(key, value),
      removeItem: (key: string) => values.delete(key)
    }
  })
  return {
    values,
    restore() {
      if (previous) Object.defineProperty(globalThis, 'sessionStorage', previous)
      else Reflect.deleteProperty(globalThis, 'sessionStorage')
    }
  }
}

const flush = async () => {
  await new Promise(resolve => setImmediate(resolve))
  await Vue.nextTick()
}

type TestNode = { tag?: string, text?: string, children: TestNode[], parent: TestNode | null, props: Record<string, unknown> }

function mountReconciliationComponent(t: { after: (callback: () => void) => void }, workspace: unknown) {
  const component = compileVueComponent('components/erp/ErpCompanyInitializationReconciliation.vue', {
    useErpCompanyInitialization: () => workspace
  })
  const root: TestNode = { tag: 'root', children: [], parent: null, props: {} }
  const renderer = Vue.createRenderer<TestNode>({
    patchProp(node, name, _previous, next) { node.props[name] = next },
    insert(node, parent, anchor) {
      if (node.parent) {
        const index = node.parent.children.indexOf(node)
        if (index >= 0) node.parent.children.splice(index, 1)
      }
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
    },
    remove(node) {
      if (node.parent) {
        const index = node.parent.children.indexOf(node)
        if (index >= 0) node.parent.children.splice(index, 1)
      }
    },
    createElement(tag) { return { tag, children: [], parent: null, props: {} } },
    createText(text) { return { text, children: [], parent: null, props: {} } },
    createComment(text) { return { text, children: [], parent: null, props: {} } },
    setText(node, text) { node.text = text },
    setElementText(node, text) {
      node.children = []
      node.text = text
    },
    parentNode(node) { return node.parent },
    nextSibling(node) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  const app = renderer.createApp(component)
  app.component('UPageCard', { props: ['title', 'description', 'variant'], setup: (props, context) => () => Vue.h('section', [Vue.h('h2', String(props.title ?? '')), context.slots.default?.()]) })
  app.component('UAlert', { props: ['title', 'description', 'color'], setup: props => () => Vue.h('div', { role: 'alert' }, [String(props.title ?? ''), String(props.description ?? '')]) })
  app.component('UButton', { props: ['label', 'disabled', 'loading', 'type'], setup: (props, context) => () => Vue.h('button', { ...context.attrs, disabled: props.disabled }, String(props.label ?? '')) })
  app.component('UTable', { props: ['data', 'columns'], setup: props => () => Vue.h('div', { role: 'table' }, props.data.flatMap((row: Record<string, unknown>) => [String(row.label), String(row.statusLabel), String(row.reasonLabel), String(row.beforeLabel), String(row.afterLabel)]).join(' ')) })
  app.component('UBadge', { setup: (_props, context) => () => Vue.h('span', context.slots.default?.()) })
  app.mount(root)
  t.after(() => app.unmount())
  const nodes = (node: TestNode): TestNode[] => [node, ...node.children.flatMap(nodes)]
  const text = (node: TestNode) => [node.text ?? '', ...node.children.map(text)].join(' ')
  const click = (label: RegExp) => {
    const button = nodes(root).find(node => node.tag === 'button' && label.test(String(node.text)))
    assert.ok(button, `Expected button matching ${label}`)
    const handler = button.props.onClick as ((event: Event) => void) | undefined
    assert.equal(typeof handler, 'function')
    handler!(new Event('click'))
  }
  return { root, nodes: () => nodes(root), text: () => text(root), click }
}

describe('company initialization reconciliation authority and recovery', () => {
  it('allows a direct Company owner without an Account while still fencing Account changes', async (t) => {
    let finish!: (value: unknown) => void
    const app = initializationHarness(async () => new Promise((resolve) => {
      finish = resolve
    }), 'owner', 'direct', ['erp.company.settings.update'], null)
    t.after(app.dispose)
    const state = app.workspace as { canPreview: Vue.Ref<boolean>, review: Vue.Ref<InitializationPreview | null>, preview: () => Promise<unknown> }
    assert.equal(Vue.unref(state.canPreview), true)
    const request = state.preview()
    app.account.value = { id: 4, status: 'active', role: 'admin' }
    finish({ data: previewResult })
    await request
    assert.equal(Vue.unref(state.review), null)
  })

  it('previews and explicitly applies for a current direct owner with the server-bound preview proof', async (t) => {
    const calls: ApiCall[] = []
    const app = initializationHarness(async (path, options) => {
      calls.push({ path, options })
      return path.endsWith('/preview') ? { data: previewResult } : { data: completeResult }
    })
    t.after(app.dispose)
    const state = app.workspace as {
      canPreview: Vue.Ref<boolean>
      canApply: Vue.Ref<boolean>
      review: Vue.Ref<InitializationPreview | null>
      outcome: Vue.Ref<InitializationOutcome | null>
      preview: () => Promise<unknown>
      apply: () => Promise<unknown>
    }
    assert.equal(Vue.unref(state.canPreview), true)
    await state.preview()
    assert.equal(Vue.unref(state.review)?.fingerprint, previewResult.fingerprint)
    assert.equal(calls[0]?.path, '/erp/company/initialization/preview')
    assert.equal(calls[0]?.options?.method, 'POST')

    await state.apply()
    const request = calls[1]
    assert.equal(request?.path, '/erp/company/initialization/apply')
    assert.equal(request?.options?.method, 'POST')
    assert.deepEqual(request?.options?.body, { preview_version: 'setup-v1', fingerprint: previewResult.fingerprint })
    assert.match(request?.options?.headers?.['Idempotency-Key'] ?? '', /^[0-9a-f-]{36}$/i)
    assert.equal(Vue.unref(state.outcome)?.completed, true)
  })

  it('denies preview and apply to managers, delegated operators and platform status without direct ownership', async (t) => {
    for (const context of [
      { role: 'manager', accessKind: 'direct', permissions: ['*'] },
      { role: 'owner', accessKind: 'delegated', permissions: ['*'] },
      { role: 'operator', accessKind: 'direct', permissions: ['*'] },
      { role: 'owner', accessKind: 'direct', permissions: ['erp.company.settings.show'] }
    ]) {
      const calls: ApiCall[] = []
      const app = initializationHarness(async (path, options) => {
        calls.push({ path, options })
        return { data: previewResult }
      }, context.role, context.accessKind, context.permissions)
      t.after(app.dispose)
      app.user.value.is_super_admin = true
      const state = app.workspace as { canPreview: Vue.Ref<boolean>, canApply: Vue.Ref<boolean>, preview: () => Promise<unknown>, apply: () => Promise<unknown> }
      assert.equal(Vue.unref(state.canPreview), false)
      assert.equal(Vue.unref(state.canApply), false)
      await state.preview()
      await state.apply()
      assert.deepEqual(calls, [])
    }
  })

  it('discards a preview response after Company or session authority changes', async (t) => {
    let finish!: (value: unknown) => void
    const app = initializationHarness(async () => new Promise((resolve) => {
      finish = resolve
    }))
    t.after(app.dispose)
    const state = app.workspace as { review: Vue.Ref<InitializationPreview | null>, preview: () => Promise<unknown> }
    const request = state.preview()
    app.company.value.id = 8
    finish({ data: previewResult })
    await request
    assert.equal(Vue.unref(state.review), null)

    app.company.value.id = 7
    let finishSession!: (value: unknown) => void
    const later = initializationHarness(async () => new Promise((resolve) => {
      finishSession = resolve
    }))
    t.after(later.dispose)
    const laterState = later.workspace as { review: Vue.Ref<InitializationPreview | null>, preview: () => Promise<unknown> }
    const second = laterState.preview()
    later.sessionGeneration.value++
    finishSession({ data: previewResult })
    await second
    assert.equal(Vue.unref(laterState.review), null)
  })

  it('discards an apply response after current write authority is revoked', async (t) => {
    let finishApply!: (value: unknown) => void
    const app = initializationHarness(async (path) => {
      if (path.endsWith('/preview')) return { data: previewResult }
      return new Promise((resolve) => {
        finishApply = resolve
      })
    })
    t.after(app.dispose)
    const state = app.workspace as { outcome: Vue.Ref<InitializationOutcome | null>, preview: () => Promise<unknown>, apply: () => Promise<unknown> }
    await state.preview()
    const request = state.apply()
    await flush()
    app.company.value.permissions = []
    finishApply({ data: { ...previewResult, revision: 1, completed: true } })
    await request
    assert.equal(Vue.unref(state.outcome), null)
  })

  it('invalidates stale review proof after a 409 and requires a fresh preview', async (t) => {
    const calls: ApiCall[] = []
    let previews = 0
    const app = initializationHarness(async (path, options) => {
      calls.push({ path, options })
      if (path.endsWith('/preview')) return { data: { ...previewResult, fingerprint: (++previews === 1 ? 'a' : 'b').repeat(64) } }
      if (calls.filter(call => call.path.endsWith('/apply')).length === 1)
        throw { statusCode: 409, data: { code: 'initialization_preview_stale', message: 'A configuração mudou. Gere uma nova prévia antes de aplicar.' } }
      return { data: completeResult }
    })
    t.after(app.dispose)
    const state = app.workspace as { review: Vue.Ref<InitializationPreview | null>, canApply: Vue.Ref<boolean>, error: Vue.Ref<string>, preview: () => Promise<unknown>, apply: () => Promise<unknown> }
    await state.preview()
    await state.apply()
    assert.equal(Vue.unref(state.review), null)
    assert.equal(Vue.unref(state.canApply), false)
    assert.match(Vue.unref(state.error), /nova prévia/i)
    assert.equal(calls.filter(call => call.path.endsWith('/preview')).length, 1)
    await state.preview()
    await state.apply()
    const applyCalls = calls.filter(call => call.path.endsWith('/apply'))
    assert.equal(applyCalls[1]?.options?.body?.fingerprint, 'b'.repeat(64))
    assert.notEqual(applyCalls[1]?.options?.headers?.['Idempotency-Key'], applyCalls[0]?.options?.headers?.['Idempotency-Key'])
  })

  it('recovers a lost apply response through the same journal identity and retains partial-conflict semantics', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const calls: ApiCall[] = []
    const partial: InitializationOutcome = { ...completeResult, has_conflicts: true, completed: false }
    const app = initializationHarness(async (path, options) => {
      calls.push({ path, options })
      if (path.endsWith('/preview')) return { data: { ...previewResult, has_conflicts: true, items: [...previewResult.items, { key: 'currency', status: 'conflict', reason: 'unresolved_legacy_namespace', before: null, after: null, effective_changed: false }] } }
      if (path.endsWith('/apply')) throw new Error('response connection lost after commit')
      return { data: { operation: 'erp.company.initialization.apply', state: 'completed', result: partial } }
    })
    t.after(app.dispose)
    const state = app.workspace as {
      review: Vue.Ref<InitializationPreview | null>
      outcome: Vue.Ref<InitializationOutcome | null>
      needsReconciliation: Vue.Ref<boolean>
      apply: () => Promise<unknown>
      reconcile: () => Promise<unknown>
    }
    await state.preview()
    await state.apply()
    assert.equal(Vue.unref(state.needsReconciliation), true)
    const original = calls.find(call => call.path.endsWith('/apply'))!
    await state.reconcile()
    assert.deepEqual(calls.at(-1), {
      path: '/erp/operations/lookup?operation=erp.company.initialization.apply',
      options: { headers: { 'Idempotency-Key': original.options?.headers?.['Idempotency-Key'] } }
    })
    assert.equal(Vue.unref(state.outcome)?.completed, false)
    assert.equal(Vue.unref(state.needsReconciliation), false)
  })

  it('only retries an unrecorded apply with the exact original payload and key', async (t) => {
    const storage = sessionStorageFixture()
    t.after(storage.restore)
    const calls: ApiCall[] = []
    const app = initializationHarness(async (path, options) => {
      calls.push({ path, options })
      if (path.endsWith('/preview')) return { data: previewResult }
      if (path.endsWith('/apply')) {
        if (calls.filter(call => call.path.endsWith('/apply')).length === 1) throw new Error('response outcome unknown')
        return { data: completeResult }
      }
      throw { statusCode: 404, data: { code: 'operation_not_recorded' } }
    })
    t.after(app.dispose)
    const state = app.workspace as {
      canRetryExact: Vue.Ref<boolean>
      preview: () => Promise<unknown>
      apply: () => Promise<unknown>
      reconcile: () => Promise<unknown>
      retryExact: () => Promise<unknown>
    }
    await state.preview()
    await state.apply()
    await state.reconcile()
    assert.equal(Vue.unref(state.canRetryExact), true)
    const original = calls.find(call => call.path.endsWith('/apply'))!
    await state.retryExact()
    const retry = calls.filter(call => call.path.endsWith('/apply'))[1]
    assert.deepEqual(retry?.options?.body, original.options?.body)
    assert.equal(retry?.options?.headers?.['Idempotency-Key'], original.options?.headers?.['Idempotency-Key'])
  })
})

describe('company initialization reconciliation interface', () => {
  it('applies the real preview proof when the owner clicks the Apply button', async (t) => {
    const calls: ApiCall[] = []
    const app = initializationHarness(async (path, options) => {
      calls.push({ path, options })
      return path.endsWith('/preview') ? { data: previewResult } : { data: completeResult }
    })
    t.after(app.dispose)
    const mounted = mountReconciliationComponent(t, app.workspace)
    await flush()
    mounted.click(/gerar prévia/i)
    await flush()
    mounted.click(/aplicar configuração/i)
    await flush()
    assert.equal(calls.filter(call => call.path.endsWith('/apply')).length, 1)
    const request = calls.find(call => call.path.endsWith('/apply'))
    assert.deepEqual(request?.options?.body, { preview_version: 'setup-v1', fingerprint: previewResult.fingerprint })
    assert.match(request?.options?.headers?.['Idempotency-Key'] ?? '', /^[0-9a-f-]{36}$/i)
    assert.match(mounted.text(), /Preparação inicial concluída/)
  })

  it('shows the full safe backend manifest and a customized UN conflict in the rendered review', async (t) => {
    const manifestPreview: InitializationPreview = {
      ...previewResult,
      items: [
        { key: 'operating_settings.time_zone', status: 'create', reason: 'missing_preference', before: 'UTC', after: 'America/Sao_Paulo', effective_changed: true },
        { key: 'operating_settings.date_format', status: 'create', reason: 'missing_preference', before: 'Y-m-d', after: 'd/m/Y', effective_changed: true },
        { key: 'operating_settings.time_format', status: 'create', reason: 'missing_preference', before: 12, after: 24, effective_changed: true },
        { key: 'operating_settings.fy_start_month', status: 'create', reason: 'missing_preference', before: 7, after: 1, effective_changed: true },
        { key: 'operating_settings.accounting_method', status: 'create', reason: 'missing_preference', before: 'average', after: 'fifo', effective_changed: true },
        { key: 'operating_settings.stock_overselling_enabled', status: 'create', reason: 'missing_preference', before: true, after: false, effective_changed: true },
        { key: 'currency', status: 'create', reason: 'missing_native_currency', before: null, after: 'BRL', effective_changed: true },
        { key: 'unit', status: 'create', reason: 'missing_native_record', before: null, after: { native_key: 'br-basic-v1.unit.un', short_name: 'UN', actual_name: 'Unidade', allow_decimal: false, base_unit_id: null, conversion_factor: null, status: 'active' }, effective_changed: true },
        { key: 'customer', status: 'create', reason: 'missing_native_record', before: null, after: { native_key: 'br-basic-v1.customer.counter-sale', name: 'Consumidor final', is_customer: true, is_supplier: false, status: 'active' }, effective_changed: true },
        { key: 'sale_scheme', status: 'create', reason: 'missing_native_record', before: null, after: { native_key: 'br-basic-v1.sale-scheme', name: 'Vendas', prefix: '', start_number: 1, total_digits: 4, is_default: false, status: 'active' }, effective_changed: true },
        { key: 'sale_layout', status: 'create', reason: 'missing_native_record', before: null, after: { native_key: 'br-basic-v1.sale-layout', name: 'Venda comercial', layout_settings: { private_configuration: 'must not be displayed' }, is_default: false, status: 'active' }, effective_changed: true }
      ]
    }
    let previews = 0
    const app = initializationHarness(async () => ({ data: { ...manifestPreview, items: previews++ === 0 ? manifestPreview.items : manifestPreview.items.map(item => item.key === 'unit' ? { ...item, status: 'conflict', reason: 'native_record_customized_or_archived', after: null } : item) } }))
    t.after(app.dispose)
    const mounted = mountReconciliationComponent(t, app.workspace)
    const workspace = app.workspace as { preview: () => Promise<unknown> }
    await workspace.preview()
    await flush()
    const proposed = mounted.text()
    assert.match(proposed, /Início do ano fiscal/)
    assert.match(proposed, /Venda sem excesso de estoque/)
    assert.match(proposed, /UN · Unidade · unidade inteira/)
    assert.match(proposed, /Consumidor final/)
    assert.match(proposed, /Vendas · sem prefixo · inicia em 1 · 4 dígitos/)
    assert.match(proposed, /Venda comercial/)
    assert.doesNotMatch(proposed, /br-basic-v1\.|private_configuration|\[object Object\]/)

    await workspace.preview()
    await flush()
    const conflict = mounted.text()
    assert.match(conflict, /Unidade padrão/)
    assert.match(conflict, /Unidade existente foi personalizada ou arquivada/)
    assert.doesNotMatch(conflict, /br-basic-v1\.|private_configuration|\[object Object\]/)
  })

  it('renders a partial apply as unresolved conflicts, never as completed setup', async (t) => {
    const state = {
      canPreview: Vue.ref(true),
      canApply: Vue.ref(false),
      canRetryExact: Vue.ref(false),
      busy: Vue.ref(false),
      review: Vue.ref<InitializationPreview | null>({ ...previewResult, has_conflicts: true, items: [...previewResult.items, { key: 'currency', status: 'conflict', reason: 'unresolved_legacy_namespace', before: null, after: null, effective_changed: false }] }),
      outcome: Vue.ref<InitializationOutcome | null>({ ...completeResult, has_conflicts: true, completed: false }),
      error: Vue.ref(''),
      needsReconciliation: Vue.ref(false),
      preview: async () => {},
      apply: async () => {},
      reconcile: async () => {},
      retryExact: async () => {}
    }
    const component = compileVueComponent('components/erp/ErpCompanyInitializationReconciliation.vue', {
      useErpCompanyInitialization: () => state
    })
    type Node = { tag?: string, text?: string, children: Node[], parent: Node | null, props: Record<string, unknown> }
    const root: Node = { tag: 'root', children: [], parent: null, props: {} }
    const renderer = Vue.createRenderer<Node>({
      patchProp(node, name, _previous, next) { node.props[name] = next },
      insert(node, parent, anchor) {
        if (node.parent) {
          const index = node.parent.children.indexOf(node)
          if (index >= 0) node.parent.children.splice(index, 1)
        }
        node.parent = parent
        const index = anchor ? parent.children.indexOf(anchor) : -1
        if (index < 0) parent.children.push(node)
        else parent.children.splice(index, 0, node)
      },
      remove(node) {
        if (node.parent) {
          const index = node.parent.children.indexOf(node)
          if (index >= 0) node.parent.children.splice(index, 1)
        }
      },
      createElement(tag) { return { tag, children: [], parent: null, props: {} } },
      createText(text) { return { text, children: [], parent: null, props: {} } },
      createComment(text) { return { text, children: [], parent: null, props: {} } },
      setText(node, text) { node.text = text },
      setElementText(node, text) {
        node.children = []
        node.text = text
      },
      parentNode(node) { return node.parent },
      nextSibling(node) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
    })
    const app = renderer.createApp(component)
    app.component('UPageCard', { props: ['title', 'description', 'variant'], setup: (props, context) => () => Vue.h('section', [Vue.h('h2', String(props.title ?? '')), context.slots.default?.()]) })
    app.component('UAlert', { props: ['title', 'description', 'color'], setup: props => () => Vue.h('div', { role: 'alert' }, [String(props.title ?? ''), String(props.description ?? '')]) })
    app.component('UButton', { props: ['label', 'disabled', 'loading', 'type'], setup: (props, context) => () => Vue.h('button', { ...context.attrs, disabled: props.disabled }, String(props.label ?? '')) })
    app.component('UTable', { props: ['data', 'columns'], setup: props => () => Vue.h('div', { role: 'table' }, props.data.flatMap((row: Record<string, unknown>) => [String(row.label), String(row.statusLabel), String(row.reasonLabel), String(row.beforeLabel), String(row.afterLabel)]).join(' ')) })
    app.component('UBadge', { setup: (_props, context) => () => Vue.h('span', context.slots.default?.()) })
    t.after(() => app.unmount())
    app.mount(root)
    await flush()
    const nodes = (node: Node): Node[] => [node, ...node.children.flatMap(nodes)]
    const text = (node: Node): string => [node.text ?? '', ...node.children.map(text)].join(' ')
    assert.match(text(root), /conflito/i)
    assert.match(text(root), /moeda/i)
    assert.doesNotMatch(text(root), /unresolved_legacy_namespace|operating_settings\.time_format/)
    assert.doesNotMatch(text(root), /setup concluído|configuração concluída/i)
    assert.ok(nodes(root).some(node => node.tag === 'button' && /prévia/i.test(String(node.text))))
  })
})
