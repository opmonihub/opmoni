import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import { test } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier.startsWith('#shared/'))
      return nextResolve(new URL(`../shared/${specifier.slice(8)}.ts`, import.meta.url).href, context)
    if (specifier.startsWith('~/'))
      return nextResolve(new URL(`../app/${specifier.slice(2)}.ts`, import.meta.url).href, context)
    return nextResolve(specifier, context)
  }
})

type Node = { tag?: string, text?: string, props: Record<string, unknown>, children: Node[], parent?: Node | null }
const secret = 'a'.repeat(64)
const valid = {
  state: 'valid', operation_key: 'stable-key', data: {
    invitation: { id: 31, owner_name: 'Ana Owner', owner_email: 'ana@example.test', expires_at: '2026-10-30T00:00:00Z' },
    seller: { account_id: 8, name: 'Escritório Central' },
    client: { name: 'Loja Um', trade_name: null, tax_id: '12.345.678/0001-90' },
    offer: { name: 'Comercial', price: '99.00', currency: 'BRL', interval_months: 1, due_in_days: 10, module_keys: ['purchases'] },
    service: { billing_timezone: 'America/Sao_Paulo' }, companies: [], accepted_company: null
  }
}

function text(node: Node): string {
  const props = Object.values(node.props).filter(value => typeof value === 'string').join(' ')
  return `${node.text ?? ''}${props}${node.children.map(text).join('')}`
}
function nodes(node: Node, predicate: (candidate: Node) => boolean): Node[] {
  return [...(predicate(node) ? [node] : []), ...node.children.flatMap(child => nodes(child, predicate))]
}
function mount(component: Vue.Component, registered: Record<string, Vue.Component>) {
  const root: Node = { props: {}, children: [] }
  const renderer = Vue.createRenderer({
    patchProp(node: Node, key, _old, next) { node.props[key] = next },
    insert(node: Node, parent: Node, anchor?: Node) {
      node.parent = parent
      const i = anchor ? parent.children.indexOf(anchor) : -1
      if (i < 0)
        parent.children.push(node)
      else
        parent.children.splice(i, 0, node)
    },
    remove(node: Node) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1) },
    createElement(tag) { return { tag, props: {}, children: [], parent: null } },
    createText(value) { return { text: value, props: {}, children: [], parent: null } },
    createComment(value) { return { text: value, props: {}, children: [], parent: null } },
    setText(node: Node, value) { node.text = value },
    setElementText(node: Node, value) {
      node.text = value
      node.children = []
    },
    parentNode(node: Node) { return node.parent ?? null },
    nextSibling(node: Node) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  const app = renderer.createApp(component)
  for (const [name, value] of Object.entries(registered)) app.component(name, value)
  app.mount(root)
  return { app, root }
}

function harness(options: { hash?: string, user?: unknown, api: (path: string, config?: Record<string, unknown>) => Promise<unknown> }) {
  const calls: Array<{ path: string, config?: Record<string, unknown> }> = []
  const storage = new Map<string, string>()
  const originalWindow = globalThis.window
  const originalSessionStorage = globalThis.sessionStorage
  const url = new URL(`http://localhost:3000/erp/ativar${options.hash ?? ''}`)
  const win = {
    location: url,
    history: {
      state: null,
      replaceState(_state: unknown, _title: string, next: string) {
        url.href = new URL(next, url).href
      }
    }
  }
  Object.assign(globalThis, {
    window: win,
    sessionStorage: { setItem(key: string, value: string) { storage.set(key, value) }, getItem: (key: string) => storage.get(key) ?? null },
    definePageMeta() {}, useHead() {},
    useAccessApi: () => ({
      api: async (path: string, config?: Record<string, unknown>) => {
        calls.push({ path, config })
        return options.api(path, config)
      }
    }),
    useAuth: () => ({ user: Vue.ref(options.user ?? null), refresh: async () => {}, logout: async () => {} }),
    defineNuxtLink: undefined
  })
  const component = compileVueComponent('pages/erp/ativar.vue', {
    ref: Vue.ref, computed: Vue.computed, onMounted: Vue.onMounted, onBeforeUnmount: Vue.onBeforeUnmount
  }, {
    '#shared/utils/access': { apiFailure: (error: unknown) => ({ status: Number((error as { status?: number })?.status ?? 0), message: String((error as Error)?.message ?? 'Falha') }), passwordLength: (value: string) => [...value].length }
  })
  const passThrough: Vue.Component = { inheritAttrs: false, setup: (_props, ctx) => () => Vue.h('section', ctx.attrs, ctx.slots.default?.()) }
  const registered: Record<string, Vue.Component> = {
    UPageCard: passThrough,
    UAlert: { props: ['title', 'description'], setup: (props, ctx) => () => Vue.h('aside', {}, [Vue.h('strong', {}, String(props.title ?? '')), Vue.h('p', {}, String(props.description ?? '')), ctx.slots.actions?.()]) },
    UButton: { props: ['label', 'disabled', 'loading'], setup: (props, ctx) => () => Vue.h('button', { ...ctx.attrs, disabled: props.disabled, onClick: ctx.attrs.onClick }, props.label ?? ctx.slots.default?.()) },
    UAuthForm: { props: ['submit', 'title', 'description'], setup: (props, ctx) => () => Vue.h('form', {}, [Vue.h('h3', {}, String(props.title)), Vue.h('button', { type: 'button', onClick: () => ctx.emit('submit', { data: { password: 'safe-password-123' } }) }, (props.submit as { label?: string })?.label)]) },
    UFormField: { props: ['label', 'hint'], setup: (props, ctx) => () => Vue.h('label', {}, [String(props.label), String(props.hint), ctx.slots.default?.()]) },
    USelect: { props: ['modelValue', 'items'], setup: (props, ctx) => () => Vue.h('select', { 'aria-label': ctx.attrs['aria-label'], 'onChange': (event: Event) => ctx.emit('update:modelValue', Number((event.target as HTMLSelectElement).value) || null) }, (props.items as Array<{ label: string, value: number | null }>).map(item => Vue.h('option', { value: item.value ?? '' }, item.label))) },
    NuxtLink: { props: ['to'], setup: (props, ctx) => () => Vue.h('a', { href: props.to }, ctx.slots.default?.()) },
    USkeleton: { setup: () => () => Vue.h('span') }
  }
  const rendered = mount(component, registered)
  return {
    ...rendered,
    calls,
    storage,
    url,
    restore() {
      rendered.app.unmount()
      globalThis.window = originalWindow
      globalThis.sessionStorage = originalSessionStorage
    }
  }
}

test('fragment exchange strips the bearer secret before waiting and renders the invitation summary', async () => {
  let resolve!: (value: unknown) => void
  const held = new Promise<unknown>((done) => {
    resolve = done
  })
  const page = harness({ hash: `#token=${secret}`, api: (path, config) => {
    assert.equal(path, '/erp-invitations/resolve')
    assert.deepEqual(config?.body, { token: secret })
    return held
  } })
  try {
    await Vue.nextTick()
    assert.equal(page.url.hash, '')
    assert.equal(page.url.searchParams.has('token'), false)
    assert.equal(page.calls[0]?.path, '/erp-invitations/resolve')
    assert.equal(JSON.stringify([...page.storage]), '[]')
    resolve(valid)
    await new Promise(done => setImmediate(done))
    await Vue.nextTick()
    const rendered = text(page.root)
    assert.match(rendered, /Escritório Central/)
    assert.match(rendered, /Loja Um/)
    assert.match(rendered, /o contrato ficará pendente de ativação/i)
    assert.doesNotMatch(rendered, new RegExp(secret))
    assert.equal(page.calls.some(call => JSON.stringify(call).includes(secret)), true, 'the bearer value is sent once in the resolve body')
  } finally { page.restore() }
})

test('authenticated owner explicitly selects company adoption and submits the stable idempotency key', async () => {
  const adopted = { ...valid, state: 'ready', data: { ...valid.data, companies: [{ id: 72, name: 'Minha Loja', cnpj: '12.345.678/0001-90' }] } }
  let accepted = false
  const page = harness({ user: { id: 4, email: 'ana@example.test' }, api: async (path, config) => {
    if (path === '/erp-invitations/current') return accepted ? { state: 'accepted', data: { ...valid.data, accepted_company: { id: 72, name: 'Minha Loja' } } } : adopted
    if (path === '/erp-invitations/accept') {
      accepted = true
      return {}
    }
    throw new Error(`Unexpected request ${path} ${JSON.stringify(config)}`)
  } })
  try {
    await new Promise(done => setImmediate(done))
    await Vue.nextTick()
    const select = nodes(page.root, node => node.tag === 'select')[0]
    assert.ok(select)
    assert.match(text(page.root), /Minha Loja · CNPJ 12.345.678\/0001-90/)
    select.props.onChange?.({ target: { value: '72' } } as unknown as Event)
    await Vue.nextTick()
    const acceptButton = nodes(page.root, node => node.tag === 'button' && String(node.text).includes('associar empresa'))[0]
    assert.ok(acceptButton)
    acceptButton.props.onClick?.()
    await new Promise(done => setImmediate(done))
    await Vue.nextTick()
    const accept = page.calls.find(call => call.path === '/erp-invitations/accept')
    assert.deepEqual(accept?.config?.body, { company_id: 72 })
    assert.equal(new Headers(accept?.config?.headers as HeadersInit).get('Idempotency-Key'), 'stable-key')
    assert.match(text(page.root), /Convite aceito/)
  } finally { page.restore() }
})

test('invalid, expired, and retryable service failures produce distinct recovery states', async () => {
  for (const [status, expected] of [[422, /não é válido/i], [410, /expirou/i], [503, /não foi possível validar/i]] as const) {
    const page = harness({ hash: `#token=${secret}`, api: async () => {
      throw Object.assign(new Error('Serviço indisponível'), { status })
    } })
    try {
      await new Promise(done => setImmediate(done))
      await Vue.nextTick()
      assert.match(text(page.root), expected)
      assert.equal(page.url.hash, '')
    } finally { page.restore() }
  }
})

test('login resumes the server-verified owner continuation at the activation route', async () => {
  const states = new Map<string, Vue.Ref<unknown>>()
  const calls: string[] = []
  const redirects: string[] = []
  const api = async (path: string) => {
    calls.push(path)
    if (path === '/login') return { id: 4, name: 'Ana Owner', email: 'ana@example.test', is_super_admin: false, accounts: [], current_account: null, companies: [], current_company: null }
    if (path === '/erp-invitations/current') return { state: 'ready' }
    return { csrf_token: 'csrf' }
  }
  Object.assign(globalThis, {
    useNuxtApp: () => ({ runWithContext: (run: () => unknown) => run() }),
    useState: (key: string, initial: () => unknown) => {
      if (!states.has(key))
        states.set(key, Vue.ref(initial()))
      return states.get(key)!
    },
    useAccessApi: () => ({ api, csrf: Vue.ref(null), refreshCsrf: async () => {} }),
    clearNuxtData() {}, clearNuxtState() {},
    navigateTo: (path: string) => {
      redirects.push(path)
      return path
    }
  })
  const { useAuth } = await import('../app/composables/useAuth.ts')
  const result = await useAuth().authenticate('/login', { email: 'ana@example.test' })
  assert.equal(result, '/erp/ativar')
  assert.deepEqual(redirects, ['/erp/ativar'])
  assert.deepEqual(calls, ['/login', '/erp-invitations/current'])
})
