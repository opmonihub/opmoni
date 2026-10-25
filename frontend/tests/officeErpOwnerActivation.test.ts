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
function submitNewOwnerForm(root: Node) {
  const form = nodes(root, node => node.tag === 'form')[0]
  const submit = form?.props.onSubmit
  const callback = Array.isArray(submit) ? submit[0] : submit
  if (typeof callback === 'function')
    callback({ preventDefault() {} } as Event)
  else
    nodes(root, node => node.tag === 'button' && String(node.text).includes('Aceitar convite e criar empresa'))[0]?.props.onClick?.()
}
function setRenderedPassword(root: Node, password: string) {
  const input = nodes(root, node => node.tag === 'input')[0]
  assert.ok(input)
  const onInput = input.props.onInput as ((event: Event) => void) | undefined
  onInput?.({ target: { value: password } } as unknown as Event)
}
async function flushPage() {
  for (let index = 0; index < 5; index++) {
    await new Promise(done => setImmediate(done))
    await Vue.nextTick()
  }
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

function harness(options: { hash?: string, user?: unknown, storage?: Map<string, string>, refresh?: () => Promise<unknown>, api: (path: string, config?: Record<string, unknown>) => Promise<unknown> }) {
  const calls: Array<{ path: string, config?: Record<string, unknown> }> = []
  const storage = options.storage ?? new Map<string, string>()
  const originalWindow = globalThis.window
  const originalSessionStorage = globalThis.sessionStorage
  const url = new URL(`http://localhost:3000/erp/ativar${options.hash ?? ''}`)
  const browserStorage = {
    setItem(key: string, value: string) { storage.set(key, value) },
    getItem: (key: string) => storage.get(key) ?? null,
    removeItem(key: string) { storage.delete(key) }
  }
  const authUser = Vue.ref(options.user ?? null)
  let restored = false
  const win = {
    location: url,
    sessionStorage: browserStorage,
    history: {
      state: null,
      replaceState(_state: unknown, _title: string, next: string) {
        url.href = new URL(next, url).href
      }
    }
  }
  Object.assign(globalThis, {
    window: win,
    sessionStorage: browserStorage,
    definePageMeta() {}, useHead() {},
    useAccessApi: () => ({
      api: async (path: string, config?: Record<string, unknown>) => {
        calls.push({ path, config })
        return options.api(path, config)
      }
    }),
    useAuth: () => ({
      user: authUser,
      refresh: async () => {
        const identity = await options.refresh?.()
        if (identity !== undefined)
          authUser.value = identity
        return authUser.value
      },
      logout: async () => {}
    }),
    defineNuxtLink: undefined
  })
  const component = compileVueComponent('pages/erp/ativar.vue', {
    ref: Vue.ref, computed: Vue.computed, onMounted: Vue.onMounted, onBeforeUnmount: Vue.onBeforeUnmount
  }, {
    '#shared/utils/access': { apiFailure: (error: unknown) => ({ status: Number((error as { status?: number })?.status ?? 0), code: String((error as { data?: { code?: string } })?.data?.code ?? ''), errors: (error as { data?: { errors?: Record<string, string[]> } })?.data?.errors ?? {}, message: String((error as { data?: { message?: string } })?.data?.message ?? (error as Error)?.message ?? 'Falha') }), passwordLength: (value: string) => [...value].length }
  })
  const passThrough: Vue.Component = { inheritAttrs: false, setup: (_props, ctx) => () => Vue.h('section', ctx.attrs, ctx.slots.default?.()) }
  const registered: Record<string, Vue.Component> = {
    UPageCard: passThrough,
    UAlert: { props: ['title', 'description'], setup: (props, ctx) => () => Vue.h('aside', {}, [Vue.h('strong', {}, String(props.title ?? '')), Vue.h('p', {}, String(props.description ?? '')), ctx.slots.actions?.()]) },
    UButton: { props: ['label', 'disabled', 'loading'], setup: (props, ctx) => () => Vue.h('button', { ...ctx.attrs, disabled: props.disabled, onClick: ctx.attrs.onClick }, props.label ?? ctx.slots.default?.()) },
    UForm: {
      props: ['state', 'schema'],
      setup: (props, ctx) => () => Vue.h('form', {
        onSubmit: (event: Event) => {
          event.preventDefault()
          ctx.emit('submit', { data: { password: (props.state as { password: string }).password } })
        }
      }, ctx.slots.default?.())
    },
    UFormField: { props: ['label', 'hint', 'error', 'name'], setup: (props, ctx) => () => Vue.h('label', {}, [String(props.label), String(props.hint), String(props.error ?? ''), ctx.slots.default?.()]) },
    UInput: { props: ['modelValue'], setup: (props, ctx) => () => Vue.h('input', { value: props.modelValue, onInput: (event: Event) => ctx.emit('update:modelValue', (event.target as HTMLInputElement).value) }) },
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
    user: authUser,
    restore() {
      if (restored)
        return
      restored = true
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

test('new-owner lost response reconciles the authenticated session and accepted continuation without resubmitting password', async () => {
  let accepted = false
  const pageOwner = { id: 91, name: 'Ana Owner', email: 'ana@example.test', is_super_admin: false, accounts: [], current_account: null, companies: [], current_company: null }
  const page = harness({
    api: async (path, config) => {
      if (path === '/erp-invitations/current')
        return accepted ? { state: 'accepted', operation_key: 'stable-key', data: { ...valid.data, accepted_company: { id: 83, name: 'Loja Um' } } } : valid
      if (path === '/erp-invitations/accept') {
        accepted = true
        assert.deepEqual(config?.body, { password: 'safe-password-123' })
        throw new Error('Connection closed after commit')
      }
      throw new Error(`Unexpected request: ${path}`)
    },
    refresh: async () => pageOwner
  })
  try {
    await flushPage()
    assert.ok(nodes(page.root, node => node.tag === 'form').length > 0, 'the anonymous owner initially receives the password form')
    setRenderedPassword(page.root, 'safe-password-123')
    submitNewOwnerForm(page.root)
    const pendingStorageAtDispatch = page.storage.get('office-erp.owner-acceptance.pending')
    await flushPage()

    assert.equal(page.calls.filter(call => call.path === '/erp-invitations/accept').length, 1)
    assert.equal((page.user.value as { email: string }).email, pageOwner.email)
    assert.ok(page.calls.some(call => call.path === '/erp-invitations/current'), 'the server continuation is reloaded after the ambiguous POST')
    assert.match(text(page.root), /Convite aceito/)
    assert.equal(nodes(page.root, node => node.tag === 'form').length, 0)
    assert.equal(JSON.stringify(pendingStorageAtDispatch).includes('safe-password-123'), false)
    assert.equal(JSON.stringify(pendingStorageAtDispatch).includes(secret), false)
    assert.equal(JSON.stringify(pendingStorageAtDispatch).includes('stable-key'), false)
    assert.deepEqual([...page.storage], [])
  } finally { page.restore() }
})

test('post-acceptance refresh rejections keep recovery read-only until current confirms the result', async () => {
  for (const status of [403, 429]) {
    let accepted = false
    let currentCalls = 0
    const page = harness({
      api: async (path, config) => {
        if (path === '/erp-invitations/current') {
          currentCalls++
          if (currentCalls === 1) return valid
          if (currentCalls === 2) throw Object.assign(new Error('Status indisponível.'), { status: 503 })
          return { state: 'accepted', operation_key: 'stable-key', data: { ...valid.data, accepted_company: { id: 83, name: 'Loja Um' } } }
        }
        if (path === '/erp-invitations/accept') {
          accepted = true
          assert.deepEqual(config?.body, { password: 'safe-password-123' })
          return {}
        }
        throw new Error(`Unexpected request: ${path}`)
      },
      refresh: async () => { throw Object.assign(new Error('Falha ao atualizar a sessão.'), { status }) }
    })
    try {
      await flushPage()
      setRenderedPassword(page.root, 'safe-password-123')
      submitNewOwnerForm(page.root)
      await flushPage()

      assert.equal(accepted, true)
      assert.equal(page.calls.filter(call => call.path === '/erp-invitations/accept').length, 1)
      assert.equal(nodes(page.root, node => node.tag === 'form').length, 0)
      assert.match(text(page.root), /Confirme o resultado antes de continuar/)
      const pending = JSON.parse(page.storage.get('office-erp.owner-acceptance.pending') ?? '{}') as Record<string, unknown>
      assert.deepEqual(Object.keys(pending).sort(), ['expires_at', 'invitation_id', 'owner_email'])

      const verify = nodes(page.root, node => node.tag === 'button' && String(node.text).includes('Verificar novamente'))[0]
      assert.ok(verify)
      verify.props.onClick?.()
      await flushPage()
      assert.match(text(page.root), /Convite aceito/)
      assert.equal(page.storage.has('office-erp.owner-acceptance.pending'), false)
      assert.equal(page.calls.filter(call => call.path === '/erp-invitations/accept').length, 1)
    } finally { page.restore() }
  }
})

test('unresolved new-owner acceptance persists bounded nonsecret identity and recovery never repeats the password POST', async () => {
  let currentCalls = 0
  const page = harness({
    api: async (path) => {
      if (path === '/erp-invitations/accept')
        throw new Error('Connection closed after dispatch')
      if (path === '/erp-invitations/current') {
        currentCalls++
        if (currentCalls === 1) return valid
        if (currentCalls === 2) throw Object.assign(new Error('Temporary read failure'), { status: 503 })
        return valid
      }
      throw new Error(`Unexpected request: ${path}`)
    },
    refresh: async () => { throw Object.assign(new Error('Still anonymous'), { status: 401 }) }
  })
  try {
    await flushPage()
    assert.ok(nodes(page.root, node => node.tag === 'form').length > 0)
    setRenderedPassword(page.root, 'safe-password-123')
    submitNewOwnerForm(page.root)
    await flushPage()

    assert.match(text(page.root), /Confirme o resultado antes de continuar/)
    assert.match(text(page.root), /Não envie a senha novamente/)
    assert.equal(nodes(page.root, node => node.tag === 'form').length, 0)
    const stored = JSON.parse(page.storage.get('office-erp.owner-acceptance.pending') ?? '{}') as Record<string, unknown>
    assert.equal(stored.invitation_id, valid.data.invitation.id)
    assert.equal(stored.owner_email, 'ana@example.test')
    assert.equal(typeof stored.expires_at, 'number')
    assert.ok(Number(stored.expires_at) > Date.now() && Number(stored.expires_at) <= Date.now() + 15 * 60 * 1000)
    assert.equal(JSON.stringify(stored).includes('safe-password-123'), false)
    assert.equal(JSON.stringify(stored).includes(secret), false)
    assert.equal(JSON.stringify(stored).includes('stable-key'), false)

    const verify = nodes(page.root, node => node.tag === 'button' && String(node.text).includes('Verificar novamente'))[0]
    assert.ok(verify)
    verify.props.onClick?.()
    await flushPage()
    assert.match(text(page.root), /Confirme o resultado antes de continuar/)
    assert.equal(page.calls.filter(call => call.path === '/erp-invitations/accept').length, 1)

    const persistedStorage = page.storage
    page.restore()
    const resumed = harness({
      storage: persistedStorage,
      api: async (path) => {
        if (path === '/erp-invitations/current')
          return { state: 'accepted', operation_key: 'stable-key', data: { ...valid.data, accepted_company: { id: 83, name: 'Loja Um' } } }
        throw new Error(`Unexpected request after reload: ${path}`)
      }
    })
    try {
      await flushPage()
      assert.match(text(resumed.root), /Convite aceito/)
      assert.equal(resumed.calls.filter(call => call.path === '/erp-invitations/accept').length, 0)
      assert.equal(resumed.storage.has('office-erp.owner-acceptance.pending'), false)
    } finally { resumed.restore() }
  } finally { page.restore() }
})

test('known existing-account rejection clears pending state and login resumes normal acceptance', async () => {
  const storage = new Map<string, string>()
  const page = harness({ storage, api: async (path) => {
    if (path === '/erp-invitations/current') return valid
    if (path === '/erp-invitations/accept')
      throw Object.assign(new Error('A conta já existe. Entre para continuar.'), { status: 409, data: { code: 'login_required', message: 'A conta já existe. Entre para continuar.' } })
    throw new Error(`Unexpected request ${path}`)
  } })
  try {
    await flushPage()
    setRenderedPassword(page.root, 'safe-password-123')
    submitNewOwnerForm(page.root)
    await flushPage()
    assert.equal(storage.has('office-erp.owner-acceptance.pending'), false)
    assert.match(text(page.root), /A conta já existe\. Entre para continuar\./)
    assert.doesNotMatch(text(page.root), /Confirme o resultado antes de continuar|já foi aceito por uma conta/i)
    assert.ok(nodes(page.root, node => node.tag === 'button' && node.props.to === '/login').length > 0)
  } finally { page.restore() }

  const authenticated = harness({ user: { id: 4, email: 'ana@example.test' }, storage, api: async (path, config) => {
    if (path === '/erp-invitations/current') return { ...valid, state: 'ready' }
    if (path === '/erp-invitations/accept') return {}
    throw new Error(`Unexpected request ${path} ${JSON.stringify(config)}`)
  } })
  try {
    await flushPage()
    assert.match(text(authenticated.root), /Criar uma nova empresa/)
    const submit = nodes(authenticated.root, node => node.tag === 'button' && String(node.text).includes('Aceitar convite'))[0]
    assert.ok(submit)
    submit.props.onClick?.()
    await flushPage()
    assert.deepEqual(authenticated.calls.find(call => call.path === '/erp-invitations/accept')?.config?.body, {})
  } finally { authenticated.restore() }
})

test('known password validation rejection clears pending state and allows a corrected password', async () => {
  const passwords: unknown[] = []
  let accepted = false
  const page = harness({ api: async (path, config) => {
    if (path === '/erp-invitations/current') return accepted ? { ...valid, state: 'accepted' } : valid
    if (path === '/erp-invitations/accept') {
      passwords.push((config?.body as Record<string, unknown>).password)
      if (passwords.length === 1)
        throw Object.assign(new Error('A senha informada não atende aos requisitos.'), { status: 422, data: { message: 'A senha informada não atende aos requisitos.', errors: { password: ['A senha informada não atende aos requisitos.'] } } })
      accepted = true
      return {}
    }
    throw new Error(`Unexpected request ${path}`)
  } })
  try {
    await flushPage()
    setRenderedPassword(page.root, 'safe-password-123')
    submitNewOwnerForm(page.root)
    await flushPage()
    assert.equal(page.storage.has('office-erp.owner-acceptance.pending'), false)
    assert.match(text(page.root), /A senha informada não atende aos requisitos\./)
    assert.match(text(nodes(page.root, node => node.tag === 'label')[0]!), /A senha informada não atende aos requisitos\./)
    assert.doesNotMatch(text(page.root), /Confirme o resultado antes de continuar/)
    assert.ok(nodes(page.root, node => node.tag === 'form').length > 0)
    setRenderedPassword(page.root, 'corrected-password-456')
    submitNewOwnerForm(page.root)
    await flushPage()
    assert.deepEqual(passwords, ['safe-password-123', 'corrected-password-456'])
    assert.match(text(page.root), /Convite aceito/)
  } finally { page.restore() }
})

test('known authorization and expiry rejections clear pending acceptance without claiming an unknown result', async () => {
  for (const [status, expected] of [[403, /não foi autorizad[oa]/i], [410, /Este convite expirou/i]] as const) {
    const page = harness({ api: async (path) => {
      if (path === '/erp-invitations/current') return valid
      if (path === '/erp-invitations/accept')
        throw Object.assign(new Error(status === 410 ? 'Este convite expirou.' : 'A aceitação não foi autorizada.'), { status })
      throw new Error(`Unexpected request ${path}`)
    } })
    try {
      await flushPage()
      setRenderedPassword(page.root, 'safe-password-123')
      submitNewOwnerForm(page.root)
      await flushPage()
      assert.equal(page.storage.has('office-erp.owner-acceptance.pending'), false)
      assert.match(text(page.root), expected)
      assert.doesNotMatch(text(page.root), /Confirme o resultado antes de continuar/)
      assert.equal(page.calls.filter(call => call.path === '/erp-invitations/accept').length, 1)
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

test('the real global middleware admits owner activation for anonymous and authenticated visitors', async () => {
  let activeUser: unknown = null
  const authUser = Vue.ref<unknown>(null)
  const resolved = Vue.ref(true)
  let refreshCalls = 0
  Object.assign(globalThis, {
    defineNuxtRouteMiddleware: (handler: unknown) => handler,
    useAuth: () => ({
      user: authUser,
      resolved,
      refresh: async () => {
        refreshCalls++
        authUser.value = activeUser
        resolved.value = true
        return activeUser
      }
    }),
    useAccessApi: () => ({ api: async () => ({ registration_available: false }) }),
    navigateTo: (path: string) => path
  })
  const middleware = (await import('../app/middleware/access.global.ts')).default as (to: { path: string }) => Promise<unknown>
  assert.equal(await middleware({ path: '/erp/ativar' }), undefined)
  assert.equal(await middleware({ path: '/settings' }), '/login', 'ordinary anonymous routes retain the login guard')
  resolved.value = false
  activeUser = { id: 9, name: 'Ana', email: 'ana@example.test', is_super_admin: false, accounts: [], current_account: null, companies: [], current_company: null }
  assert.equal(await middleware({ path: '/erp/ativar' }), undefined)
  assert.equal(refreshCalls, 1, 'unresolved identity is refreshed before allowing activation')
  assert.notEqual(await middleware({ path: '/settings' }), undefined, 'ordinary authenticated routes retain destination guards')
})
