import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { readFileSync } from 'node:fs'
import { erpCompanyHasPermission } from '../app/utils/erpModulesNav.ts'
import { apiErrorMessage, apiStatus } from '../app/composables/useApiError.ts'

const componentFile = new URL('../app/components/erp/ErpAccountingDeliveries.vue', import.meta.url)

type Node = {
  tag: string
  props: Record<string, unknown>
  children: Node[]
  parent: Node | null
  textContent: string
  listeners: Record<string, Array<(event?: unknown) => void>>
}

function createRenderer() {
  const createNode = (tag: string): Node => ({ tag, props: {}, children: [], parent: null, textContent: '', listeners: {} })
  const renderer = Vue.createRenderer({
    patchProp(node, key, _old, value) {
      node.props[key] = value
      if (key.startsWith('on') && typeof value === 'function') node.listeners[key] = [value as (event?: unknown) => void]
    },
    insert(node, parent, anchor = null) {
      if (node.parent) {
        const oldIndex = node.parent.children.indexOf(node)
        if (oldIndex >= 0) node.parent.children.splice(oldIndex, 1)
      }
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
    },
    remove(node) {
      if (!node.parent) return
      const index = node.parent.children.indexOf(node)
      if (index >= 0) node.parent.children.splice(index, 1)
      node.parent = null
    },
    createElement: createNode,
    createText(text) {
      const node = createNode('#text')
      node.textContent = text
      return node
    },
    createComment(text) {
      const node = createNode('#comment')
      node.textContent = text
      return node
    },
    setText(node, text) { node.textContent = text },
    setElementText(node, text) {
      node.textContent = text
      node.children = []
    },
    parentNode(node) { return node.parent },
    nextSibling(node) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  const passthrough = Vue.defineComponent({
    inheritAttrs: false,
    props: ['title', 'description', 'label'],
    setup(props, { attrs, slots }) {
      return () => Vue.h('div', attrs, [props.title, props.description, props.label, ...Object.values(slots).flatMap(slot => slot())])
    }
  })
  const button = Vue.defineComponent({
    inheritAttrs: false,
    props: ['label'],
    setup(props, { attrs, slots }) {
      return () => Vue.h('button', { ...attrs, label: props.label }, slots.default?.() ?? String(props.label ?? ''))
    }
  })
  return { renderer, createNode, passthrough, button }
}

function textOf(node: Node): string {
  return node.textContent + node.children.map(textOf).join('')
}

function findNodes(root: Node, predicate: (node: Node) => boolean): Node[] {
  const found: Node[] = []
  const visit = (node: Node) => {
    if (predicate(node)) found.push(node)
    node.children.forEach(visit)
  }
  root.children.forEach(visit)
  return found
}

async function flush() {
  await Vue.nextTick()
  await new Promise(resolve => setImmediate(resolve))
  await Vue.nextTick()
}

function mount(
  apiHandler: (path: string, options?: Record<string, unknown>) => Promise<unknown> | unknown,
  permissions = ['erp.accounting-deliveries.index', 'erp.accounting-deliveries.show', 'erp.accounting-deliveries.retry', 'erp.accounting-deliveries.store'],
  role = 'owner'
) {
  const source = readFileSync(componentFile, 'utf8')
  const { descriptor } = parse(source, { filename: componentFile.pathname })
  const script = compileScript(descriptor, { id: 'erp-accounting-deliveries-test' })
  const compiledScript = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } })
  const identity = Vue.ref({
    id: 7,
    company: {
      id: 21, role, access_kind: 'direct', status: 'active', permissions
    }
  })
  const company = Vue.computed(() => identity.value.company)
  const sessionStorage = new Map<string, string>()
  const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
  const api = async (path: string, options?: Record<string, unknown>) => {
    calls.push({ path, options })
    return await apiHandler(path, options)
  }
  const globals: Record<string, unknown> = {
    computed: Vue.computed,
    ref: Vue.ref,
    watch: Vue.watch,
    onScopeDispose: Vue.onScopeDispose,
    onMounted: Vue.onMounted,
    useAuth: () => ({
      user: Vue.computed(() => identity.value),
      currentCompany: company,
      currentAccount: Vue.computed(() => ({ id: 5, status: 'active', role: 'admin' }))
    }),
    useNuxtApp: () => ({ $api: api }),
    useErpAdministrationContext: () => ({
      key: Vue.computed(() => JSON.stringify([identity.value.id, identity.value.company.id, identity.value.company.permissions])),
      capture: () => ({ key: JSON.stringify([identity.value.id, identity.value.company.id, identity.value.company.permissions]), generation: 1 }),
      isCurrent: (token: { key: string }) => token.key === JSON.stringify([identity.value.id, identity.value.company.id, identity.value.company.permissions])
    }),
    erpCompanyHasPermission,
    apiErrorMessage,
    apiStatus,
    sessionStorage: {
      getItem: (key: string) => sessionStorage.get(key) ?? null,
      setItem: (key: string, value: string) => sessionStorage.set(key, value),
      removeItem: (key: string) => sessionStorage.delete(key)
    },
    crypto: { randomUUID: () => 'retry-key-0001' }
  }
  const exports = {} as { default: Record<string, unknown> }
  new Function('require', 'exports', ...Object.keys(globals), compiledScript.outputText)(
    (name: string) => {
      if (name === 'vue') return Vue
      if (name === '~/utils/erpModulesNav') return { erpCompanyHasPermission }
      if (name === '~/composables/useApiError') return { apiErrorMessage, apiStatus }
      throw new Error(`Unexpected import: ${name}`)
    },
    exports,
    ...Object.values(globals)
  )
  const compiledTemplate = compileTemplate({
    source: descriptor.template?.content ?? '',
    filename: componentFile.pathname,
    id: 'erp-accounting-deliveries-template',
    compilerOptions: { mode: 'function', hoistStatic: false, bindingMetadata: script.bindings, expressionPlugins: ['typescript'] }
  })
  const template = ts.transpileModule(compiledTemplate.code, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } })
  const runtime = { ...Vue }
  const render = new Function('Vue', template.outputText)(runtime) as (ctx: unknown, cache: unknown) => unknown
  ;(exports.default as { render?: typeof render }).render = render
  const { renderer, createNode, passthrough, button } = createRenderer()
  const root = createNode('root')
  const app = renderer.createApp(exports.default)
  for (const name of ['UAlert', 'UEmpty', 'UFormField', 'USelect', 'USkeleton', 'UBadge']) app.component(name, passthrough)
  app.component('UButton', button)
  app.mount(root)
  return { root, calls, sessionStorage, app, identity }
}

const delivery = {
  id: 31,
  erp_fiscal_document_id: 44,
  account_id: 5,
  client_id: null,
  fiscal_document_id: null,
  delegation_id: 8,
  mapping_id: null,
  mapping_revision: null,
  can_retry: true,
  status: 'failed',
  reason_code: 'client_mapping_missing',
  attempts: 0,
  delivered_at: null,
  erp_fiscal_document: { id: 44, model: '55', access_key: '35251030288513000100550010000090011234567890', erp_establishment_id: 6, status: 'authorized' },
  approved_mapping: null
}

describe('ERP accounting delivery workspace', () => {
  it('renders API-shaped safe status fields and distinguishes a failed initial read from empty', async () => {
    const mounted = mount(async (path) => {
      if (path.startsWith('/erp/accounting-deliveries?')) return { data: [delivery], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      if (path === '/erp/fiscal/documents?status=authorized&per_page=100') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      if (path === '/erp/accounting-deliveries/31') return { data: delivery }
      throw new Error(`Unexpected API call: ${path}`)
    })
    await flush()
    const rendered = textOf(mounted.root)
    assert.match(rendered, /documento 44/)
    assert.match(rendered, /cliente pendente/)
    assert.doesNotMatch(rendered, /secret client name|tax_id|storage_path|<nfeProc/)
    mounted.app.unmount()

    const failed = mount(async (path) => {
      if (path.startsWith('/erp/accounting-deliveries?')) throw new Error('Servidor indisponível')
      if (path === '/erp/fiscal/documents?status=authorized&per_page=100') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      throw new Error(`Unexpected API call: ${path}`)
    })
    await flush()
    const failedText = textOf(failed.root)
    assert.match(failedText, /Não foi possível consultar as entregas/)
    assert.doesNotMatch(failedText, /Nenhuma entrega encontrada/)
    failed.app.unmount()
  })

  it('reuses the persisted retry identity and refreshes canonical detail after receipt acknowledgement', async () => {
    let posts = 0
    const mounted = mount(async (path, options) => {
      if (path.startsWith('/erp/accounting-deliveries?')) return { data: [delivery], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      if (path === '/erp/fiscal/documents?status=authorized&per_page=100') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      if (path === '/erp/accounting-deliveries/31') return { data: { ...delivery, status: 'pending', reason_code: null } }
      if (path === '/erp/accounting-deliveries/31/retry' && options?.method === 'POST') {
        posts++
        return { data: delivery }
      }
      throw new Error(`Unexpected API call: ${path}`)
    })
    await flush()
    const retryButton = findNodes(mounted.root, node => node.props.label === 'Tentar novamente')[0]
    assert.ok(retryButton)
    retryButton.listeners.onClick?.[0]?.()
    await flush()
    const post = mounted.calls.find(call => call.options?.method === 'POST')
    assert.equal(posts, 1)
    assert.deepEqual(post?.options?.headers, { 'Idempotency-Key': 'retry-key-0001', 'Content-Type': 'application/json' })
    assert.deepEqual(post?.options?.body, {})
    assert.ok(mounted.calls.some(call => call.path === '/erp/accounting-deliveries/31'))
    assert.equal(mounted.sessionStorage.get('erp-accounting-retry:7:21'), undefined)
    mounted.app.unmount()
  })

  it('allows the server-authorized first dispatch for a legacy pending row without an outbox record', async () => {
    const pendingLegacy = { ...delivery, status: 'pending', reason_code: null, can_retry: true }
    const mounted = mount(async (path, options) => {
      if (path.startsWith('/erp/accounting-deliveries?')) return { data: [pendingLegacy], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      if (path === '/erp/fiscal/documents?status=authorized&per_page=100') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      if (path === '/erp/accounting-deliveries/31/retry' && options?.method === 'POST') return { data: { ...pendingLegacy, can_retry: false } }
      if (path === '/erp/accounting-deliveries/31') return { data: { ...pendingLegacy, can_retry: false } }
      throw new Error(`Unexpected API call: ${path}`)
    })
    await flush()
    const button = findNodes(mounted.root, node => node.props.label === 'Solicitar envio')[0]
    assert.ok(button)
    button.listeners.onClick?.[0]?.()
    await flush()
    const post = mounted.calls.find(call => call.options?.method === 'POST')
    assert.equal(post?.path, '/erp/accounting-deliveries/31/retry')
    assert.deepEqual(post?.options?.body, {})
    mounted.app.unmount()
  })

  it('does not expose detail or retry controls to an index-only read-only actor', async () => {
    const mounted = mount(async (path) => {
      if (path.startsWith('/erp/accounting-deliveries?')) return { data: [delivery], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      if (path === '/erp/fiscal/documents?status=authorized&per_page=100') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      throw new Error(`Unexpected API call: ${path}`)
    }, ['erp.accounting-deliveries.index', 'erp.accounting-deliveries.retry'], 'viewer')
    await flush()
    assert.equal(findNodes(mounted.root, node => node.tag === 'button' && textOf(node).includes('Modelo 55')).length, 0)
    assert.equal(findNodes(mounted.root, node => node.props.label === 'Tentar novamente').length, 0)
    mounted.app.unmount()
  })

  it('keeps and replays the exact original request after lookup 404 when the active outbox blocks a new retry', async () => {
    let posts = 0
    const postCalls: Array<{ options?: Record<string, unknown> }> = []
    const mounted = mount(async (path, options) => {
      if (path.startsWith('/erp/accounting-deliveries?')) {
        const row = posts > 0 ? { ...delivery, status: 'pending', can_retry: false } : delivery
        return { data: [row], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      }
      if (path === '/erp/fiscal/documents?status=authorized&per_page=100') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      if (path === '/erp/accounting-deliveries/31/retry' && options?.method === 'POST') {
        posts++
        postCalls.push({ options })
        if (posts === 1) throw new Error('Conexão interrompida')
        return { data: { ...delivery, status: 'pending', can_retry: false } }
      }
      if (path.startsWith('/erp/operations/lookup?')) throw Object.assign(new Error('Not found'), { statusCode: 404 })
      if (path === '/erp/accounting-deliveries/31') return { data: delivery }
      throw new Error(`Unexpected API call: ${path}`)
    })
    await flush()
    findNodes(mounted.root, node => node.props.label === 'Tentar novamente')[0]?.listeners.onClick?.[0]?.()
    await flush()
    const saved = mounted.sessionStorage.get('erp-accounting-retry:7:21')
    assert.ok(saved?.includes('retry-key-0001'))
    findNodes(mounted.root, node => node.props.label === 'Consultar resultado original')[0]?.listeners.onClick?.[0]?.()
    await flush()
    assert.equal(mounted.sessionStorage.get('erp-accounting-retry:7:21'), saved)
    assert.match(textOf(mounted.root), /A chave original foi preservada/)
    findNodes(mounted.root, node => node.props.label === 'Atualizar')[0]?.listeners.onClick?.[0]?.()
    await flush()
    assert.equal(findNodes(mounted.root, node => node.props.label === 'Tentar novamente').length, 0)
    const replay = findNodes(mounted.root, node => node.props.label === 'Repetir a solicitação original')[0]
    assert.ok(replay)
    replay.listeners.onClick?.[0]?.()
    await flush()
    assert.equal(posts, 2)
    assert.deepEqual(postCalls[1].options?.headers, postCalls[0].options?.headers)
    assert.deepEqual(postCalls[1].options?.body, postCalls[0].options?.body)
    mounted.app.unmount()
  })

  it('keeps a newer delivery selection when an older retry resolves late', async () => {
    let resolvePost: (() => void) | null = null
    const secondDelivery = { ...delivery, id: 32, erp_fiscal_document_id: 45, erp_fiscal_document: { ...delivery.erp_fiscal_document, id: 45 } }
    const mounted = mount(async (path, options) => {
      if (path.startsWith('/erp/accounting-deliveries?')) return { data: [delivery, secondDelivery], meta: { current_page: 1, last_page: 1, per_page: 15, total: 2 } }
      if (path === '/erp/fiscal/documents?status=authorized&per_page=100') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      if (path === '/erp/accounting-deliveries/32') return { data: { ...secondDelivery, status: 'failed' } }
      if (path === '/erp/accounting-deliveries/31/retry' && options?.method === 'POST') {
        return await new Promise((resolve) => {
          resolvePost = () => resolve({ data: delivery })
        })
      }
      throw new Error(`Unexpected API call: ${path}`)
    })
    await flush()
    findNodes(mounted.root, node => node.props.label === 'Tentar novamente')[0]?.listeners.onClick?.[0]?.()
    await flush()
    findNodes(mounted.root, node => node.tag === 'button' && textOf(node).includes('documento 45'))[0]?.listeners.onClick?.[0]?.()
    await flush()
    resolvePost?.()
    await flush()
    assert.match(textOf(mounted.root), /Detalhes da entrega 32/)
    assert.doesNotMatch(textOf(mounted.root), /Detalhes da entrega 31/)
    mounted.app.unmount()
  })

  it('clears Company A state and ignores its delayed retry after switching to Company B', async () => {
    let resolvePost: (() => void) | null = null
    let activeCompany = 21
    const companyBDelivery = { ...delivery, id: 99, erp_fiscal_document_id: 99, erp_fiscal_document: { ...delivery.erp_fiscal_document, id: 99 } }
    const mounted = mount(async (path, options) => {
      if (path.startsWith('/erp/accounting-deliveries?')) {
        const rows = activeCompany === 22 ? [companyBDelivery] : [delivery]
        return { data: rows, meta: { current_page: 1, last_page: 1, per_page: 15, total: rows.length } }
      }
      if (path === '/erp/fiscal/documents?status=authorized&per_page=100') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      if (path === '/erp/accounting-deliveries/31/retry' && options?.method === 'POST') {
        return await new Promise((resolve) => {
          resolvePost = () => resolve({ data: delivery })
        })
      }
      throw new Error(`Unexpected API call: ${path}`)
    })
    await flush()
    findNodes(mounted.root, node => node.props.label === 'Tentar novamente')[0]?.listeners.onClick?.[0]?.()
    await flush()
    activeCompany = 22
    mounted.identity.value = { ...mounted.identity.value, company: { ...mounted.identity.value.company, id: 22 } }
    await flush()
    resolvePost?.()
    await flush()
    assert.match(textOf(mounted.root), /documento 99/)
    assert.doesNotMatch(textOf(mounted.root), /documento 44/)
    mounted.app.unmount()
  })

  it('retains the last confirmed rows after a transient refresh failure', async () => {
    let reads = 0
    const mounted = mount(async (path) => {
      if (path.startsWith('/erp/accounting-deliveries?')) {
        reads++
        if (reads > 1) throw new Error('Falha de rede')
        return { data: [delivery], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      }
      if (path === '/erp/fiscal/documents?status=authorized&per_page=100') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      throw new Error(`Unexpected API call: ${path}`)
    })
    await flush()
    findNodes(mounted.root, node => node.props.label === 'Atualizar')[0]?.listeners.onClick?.[0]?.()
    await flush()
    assert.match(textOf(mounted.root), /documento 44/)
    assert.match(textOf(mounted.root), /último resultado confirmado/)
    mounted.app.unmount()
  })

  it('ignores a delayed retry error from Company A after Company B becomes current', async () => {
    let rejectPost: ((error: Error) => void) | null = null
    let activeCompany = 21
    const companyBDelivery = { ...delivery, id: 99, erp_fiscal_document_id: 99, erp_fiscal_document: { ...delivery.erp_fiscal_document, id: 99 } }
    const mounted = mount(async (path, options) => {
      if (path.startsWith('/erp/accounting-deliveries?')) {
        const rows = activeCompany === 22 ? [companyBDelivery] : [delivery]
        return { data: rows, meta: { current_page: 1, last_page: 1, per_page: 15, total: rows.length } }
      }
      if (path === '/erp/fiscal/documents?status=authorized&per_page=100') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      if (path === '/erp/accounting-deliveries/31/retry' && options?.method === 'POST') {
        return await new Promise((_resolve, reject) => {
          rejectPost = reject
        })
      }
      throw new Error(`Unexpected API call: ${path}`)
    })
    await flush()
    findNodes(mounted.root, node => node.props.label === 'Tentar novamente')[0]?.listeners.onClick?.[0]?.()
    await flush()
    activeCompany = 22
    mounted.identity.value = { ...mounted.identity.value, company: { ...mounted.identity.value.company, id: 22 } }
    await flush()
    rejectPost?.(new Error('A resposta atrasada da Empresa A falhou'))
    await flush()
    assert.match(textOf(mounted.root), /documento 99/)
    assert.doesNotMatch(textOf(mounted.root), /A resposta atrasada da Empresa A falhou/)
    mounted.app.unmount()
  })
})
