import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import type { TrialRegistrationInput, TrialRegistrationOutcome } from '../shared/types/officeErpCommercial.ts'

const composablePath = new URL('../app/composables/useErpTrialRegistration.ts', import.meta.url)
const pagePath = new URL('../app/pages/erp/testar/[code].vue', import.meta.url)

function setupTrial(
  api: (path: string, options?: Record<string, unknown>) => Promise<unknown>,
  refresh: () => Promise<unknown> = async () => ({ id: 31 }),
  refreshCsrf: () => Promise<void> = async () => {}
) {
  const states = new Map<string, Vue.Ref<unknown>>([
    ['access-user', Vue.ref(null)],
    ['access-resolved', Vue.ref(false)],
    ['access-session-generation', Vue.ref(0)]
  ])
  const composable = loadAppComposable<{ useErpTrialRegistration: () => Record<string, Vue.Ref<unknown> | ((...args: never[]) => Promise<void>)> }>(
    'composables/useErpTrialRegistration.ts',
    {
      ref: Vue.ref,
      useState: (key: string, initial: () => unknown) => {
        if (!states.has(key)) states.set(key, Vue.ref(initial()))
        return states.get(key)
      },
      useAccessApi: () => ({ api, refreshCsrf }),
      useAuth: () => ({
        user: states.get('access-user'),
        resolved: states.get('access-resolved'),
        refreshWithStatus: async () => {
          const identity = await refresh()
          states.get('access-user')!.value = identity
          states.get('access-resolved')!.value = true
          return { identity, committed: !!identity }
        }
      }),
      useRoute: () => ({ params: { code: 'public-code' } }),
      crypto: globalThis.crypto
    }
  )
  const scope = Vue.effectScope()
  const view = scope.run(() => composable.useErpTrialRegistration())!
  return { view, states, stop: () => scope.stop() }
}

const input = (): TrialRegistrationInput => ({
  name: 'Ana Teste', email: 'ana@example.test', password: 'senha-segura-123',
  company_name: 'Empresa Teste Ltda.', cnpj: '12345678000195', tax_regime: 'presumed_profit'
})

const trialOutcome: TrialRegistrationOutcome = {
  client_id: 8, company_id: 9, subscription_id: 10, contract_id: 11, trial_id: 12,
  status: 'trialing', started_at: '2026-10-25T12:00:00Z', ends_at: '2026-11-01T12:00:00Z'
}

function renderPublicPage(trial: Record<string, unknown>, authUser: Vue.Ref<unknown> = Vue.ref(null)) {
  type Node = { type: string, props: Record<string, unknown>, children: Node[], text?: string, parent?: Node }
  const root: Node = { type: 'root', props: {}, children: [] }
  const renderer = Vue.createRenderer<Node, Node>({
    createElement: type => ({ type, props: {}, children: [] }),
    createText: text => ({ type: '#text', props: {}, children: [], text }),
    createComment: text => ({ type: '#comment', props: {}, children: [], text }),
    setText: (node, text) => { node.text = text },
    setElementText: (node, text) => { node.children = text ? [{ type: '#text', props: {}, children: [], text, parent: node }] : [] },
    patchProp: (node, key, _previous, next) => { node.props[key] = next },
    insert: (node, parent, anchor) => {
      node.parent = parent
      const position = anchor ? parent.children.indexOf(anchor) : -1
      if (position < 0) parent.children.push(node)
      else parent.children.splice(position, 0, node)
    },
    remove: (node) => {
      const parent = node.parent
      if (parent) parent.children = parent.children.filter(child => child !== node)
    },
    parentNode: node => node.parent ?? null,
    nextSibling: (node) => {
      if (!node.parent) return null
      return node.parent.children[node.parent.children.indexOf(node) + 1] ?? null
    }
  })
  const component = compileVueComponent('pages/erp/testar/[code].vue', {
    definePageMeta() {},
    useHead() {},
    useRoute: () => ({ params: { code: 'public-code' } }),
    onMounted() {},
    useAuth: () => ({ user: authUser }),
    useErpTrialRegistration: () => trial
  })
  const app = renderer.createApp(component)
  for (const name of ['NuxtLink', 'UAlert', 'UButton', 'UCheckbox', 'UForm', 'UFormField', 'UInput', 'USelect']) {
    app.component(name, Vue.defineComponent({
      name,
      inheritAttrs: true,
      setup(_props, { attrs, slots }) {
        return () => Vue.h(name, attrs, Object.values(slots).flatMap(slot => slot?.() ?? []))
      }
    }))
  }
  app.mount(root)
  const all = (node: Node): Node[] => [node, ...node.children.flatMap(all)]
  return {
    app,
    get visibleText() { return all(root).map(node => node.text ?? '').join(' ') },
    nodes: () => all(root),
    get form() { return all(root).find(node => node.type === 'UForm') }
  }
}

describe('office ERP trial registration surface', () => {
  it('exposes the contracted public registration methods and read-only recovery states', async () => {
    const source = await readFile(composablePath, 'utf8')
    for (const state of ['availability', 'form', 'submitting', 'confirmed', 'recovery'])
      assert.match(source, new RegExp(`['"]${state}['"]`))
    for (const method of ['load', 'submit', 'recover'])
      assert.match(source, new RegExp(`function ${method}\\b`))
    assert.match(source, /Idempotency-Key/)
    assert.match(source, /sessionStorage/)
    assert.match(source, /15 \* 60 \* 1000/)
  })

  it('compiles the real public page without an office layout or auth middleware', async () => {
    const source = await readFile(pagePath, 'utf8')
    const { descriptor, errors } = parse(source, { filename: pagePath.pathname })
    assert.deepEqual(errors, [])
    const script = compileScript(descriptor, { id: 'erp-trial-signup' })
    const template = compileTemplate({
      source: descriptor.template?.content ?? '',
      filename: pagePath.pathname,
      id: 'erp-trial-signup',
      compilerOptions: { bindingMetadata: script.bindings }
    })
    assert.deepEqual(template.errors, [])
    assert.match(script.content, /layout:\s*false/)
    assert.doesNotMatch(script.content, /middleware:\s*['"](?:auth|account-admin|erp)/)
    assert.match(template.code, /Teste de 7 dias/)
    assert.match(template.code, /confirm_existing_association/)
  })

  it('renders the signup form and invokes its real submit handler without tenant context', (t) => {
    const submitted: TrialRegistrationInput[] = []
    const trial = {
      phase: Vue.ref('form'),
      failure: Vue.ref(null),
      outcome: Vue.ref(null),
      availability: Vue.ref({ sponsor: { name: 'Escritório Exemplo' }, plan: { name: 'ERP Essencial' }, duration_days: 7, available: true }),
      pending: Vue.ref(null),
      load: async () => {},
      submit: async (value: TrialRegistrationInput) => { submitted.push({ ...value }) },
      recover: async () => {}
    }
    const mounted = renderPublicPage(trial)
    t.after(mounted.app.unmount)
    assert.match(mounted.visibleText, /Teste de 7 dias/)
    assert.match(mounted.visibleText, /Escritório Exemplo/)
    assert.ok(mounted.form, 'the actual rendered SFC contains its Nuxt UI form')
    const onSubmit = mounted.form.props.onSubmit as ((event: { data: TrialRegistrationInput }) => void) | undefined
    assert.equal(typeof onSubmit, 'function')
    onSubmit!({ data: input() })
    assert.equal(submitted.length, 1)
    assert.equal(submitted[0]?.email, 'ana@example.test')
    assert.equal('account_id' in submitted[0]!, false)
    assert.equal('client_id' in submitted[0]!, false)
  })

  it('renders an authenticated owner without a new-password field and submits the existing identity', (t) => {
    const identity = { id: 41, name: 'Ana já cadastrada', email: 'ana@example.test' }
    const authUser = Vue.ref<unknown>(identity)
    const submitted: TrialRegistrationInput[] = []
    const trial = {
      phase: Vue.ref('form'),
      failure: Vue.ref(null),
      outcome: Vue.ref(null),
      availability: Vue.ref({ sponsor: { name: 'Escritório Exemplo' }, plan: { name: 'ERP Essencial' }, duration_days: 7, available: true }),
      pending: Vue.ref(null),
      load: async () => {},
      submit: async (value: TrialRegistrationInput) => { submitted.push({ ...value }) },
      recover: async () => {}
    }
    const mounted = renderPublicPage(trial, authUser)
    t.after(mounted.app.unmount)
    assert.ok(mounted.form)
    assert.equal(mounted.nodes().some(node => node.type === 'UInput' && node.props.type === 'password'), false)
    assert.equal(mounted.nodes().some(node => node.type === 'UInput' && node.props.modelValue === identity.email), true)
    const onSubmit = mounted.form?.props.onSubmit as ((event: { data: TrialRegistrationInput }) => void) | undefined
    onSubmit?.({ data: { ...input(), name: identity.name, email: identity.email, password: undefined } })
    assert.equal(submitted.length, 1)
    assert.equal(submitted[0]?.email, identity.email)
    assert.equal(submitted[0]?.password, undefined)
    assert.equal((authUser.value as typeof identity).id, identity.id, 'the existing authenticated identity remains the owner')
  })

  it('renders the association confirmation required by the actual Client 409 codes', (t) => {
    for (const code of ['client_association_requires_owner_verification', 'confirm_existing_association_required']) {
      const trial = {
        phase: Vue.ref('form'),
        failure: Vue.ref({ code, message: 'Confirme a relação com este escritório.', fieldErrors: {} }),
        outcome: Vue.ref(null),
        availability: Vue.ref({ sponsor: { name: 'Escritório Exemplo' }, plan: { name: 'ERP Essencial' }, duration_days: 7, available: true }),
        pending: Vue.ref(null),
        load: async () => {},
        submit: async () => {},
        recover: async () => {}
      }
      const authUser = Vue.ref<unknown>(code === 'confirm_existing_association_required' ? { id: 41, name: 'Ana', email: 'ana@example.test' } : null)
      const mounted = renderPublicPage(trial, authUser)
      t.after(mounted.app.unmount)
      if (code === 'client_association_requires_owner_verification') {
        assert.equal(mounted.nodes().some(node => node.type === 'UAlert' && node.props.title === 'Esta conta já está cadastrada'), true)
        assert.equal(mounted.nodes().some(node => node.type === 'UButton' && node.props.target === '_blank'), true)
        assert.equal(mounted.nodes().some(node => node.type === 'UCheckbox'), false)
      } else {
        assert.equal(mounted.nodes().some(node => node.type === 'UAlert' && node.props.title === 'Confirme a associação ao escritório'), true)
        assert.equal(mounted.nodes().some(node => node.type === 'UCheckbox'), true)
      }
    }
  })

  it('offers a new-tab login and refreshes identity safely when returning to the trial link', async (t) => {
    const authUser = Vue.ref<unknown>(null)
    const failure = Vue.ref({ code: 'login_required', message: 'Entre com o email já cadastrado.', fieldErrors: {} })
    const phase = Vue.ref('form')
    let refreshes = 0
    const trial = {
      phase,
      failure,
      outcome: Vue.ref(null),
      availability: Vue.ref({ sponsor: { name: 'Escritório Exemplo' }, plan: { name: 'ERP Essencial' }, duration_days: 7, available: true }),
      pending: Vue.ref(null),
      async load() {
        refreshes++
        authUser.value = { id: 42, name: 'Ana Owner', email: 'ana@example.test' }
        failure.value = null
        phase.value = 'form'
      },
      submit: async () => {},
      recover: async () => {}
    }
    const mounted = renderPublicPage(trial, authUser)
    t.after(mounted.app.unmount)
    assert.equal(mounted.form, undefined)
    const login = mounted.nodes().find(node => node.type === 'UButton' && node.props.target === '_blank')
    assert.ok(login, JSON.stringify(mounted.nodes().map(node => ({ type: node.type, props: node.props }))))
    assert.equal(login.props.to ?? login.props.href, '/login')
    const refresh = mounted.nodes().find(node => node.type === 'UButton' && String(node.children.map(child => child.text).join('')).includes('Já entrei'))
    assert.ok(refresh)
    ;(refresh.props.onClick as () => void)()
    await Vue.nextTick()
    assert.equal(refreshes, 1)
    assert.ok(mounted.form, 'the form returns only after the page refreshes identity')
    assert.equal(mounted.nodes().some(node => node.type === 'UInput' && node.props.type === 'password'), false)
    assert.equal(mounted.nodes().some(node => node.type === 'UInput' && node.props.modelValue === 'ana@example.test'), true)
  })

  it('keeps the existing direct-registration route independent', async () => {
    const source = await readFile(new URL('../app/pages/erp/cadastro.vue', import.meta.url), 'utf8')
    assert.match(source, /erp\/registration-status/)
    assert.match(source, /erp\/register/)
    assert.doesNotMatch(source, /trials\/.*\/register/)
  })

  it('provides accountable trial-link controls and explicit paid conversion', async () => {
    const settings = await readFile(new URL('../app/pages/gestao-erp/configuracoes.vue', import.meta.url), 'utf8')
    const subscription = await readFile(new URL('../app/pages/gestao-erp/assinaturas/[id].vue', import.meta.url), 'utf8')
    const owner = await readFile(new URL('../app/pages/erp/contrato.vue', import.meta.url), 'utf8')
    assert.match(settings, /erp-commercial\/trial-link/)
    assert.match(settings, /can_manage_trial_link/)
    assert.match(settings, /Copiar link/)
    assert.match(settings, /trial-link\/rotate/)
    assert.match(subscription, /convert-trial/)
    assert.match(subscription, /Iniciar cobrança paga/)
    assert.match(subscription, /Idempotency-Key|command\.execute/)
    assert.match(owner, /trial\.situation/)
    assert.match(owner, /Teste encerrado/)
    assert.match(owner, /Fim original do teste/)
  })

  it('registers anonymously once and consumes identity plus outcome from the response', async (t) => {
    const requests: Array<{ path: string, options?: Record<string, unknown> }> = []
    const identity = { id: 31, name: 'Ana Teste', email: 'ana@example.test' }
    const app = setupTrial(async (path, options) => {
      requests.push({ path, options })
      assert.equal(path, '/erp/trials/public-code/register')
      assert.equal(new Headers(options?.headers as HeadersInit).has('X-Account-Id'), false)
      assert.equal(new Headers(options?.headers as HeadersInit).has('X-Company-Id'), false)
      return { identity, data: trialOutcome }
    }, async () => identity)
    t.after(app.stop)
    const body = input()
    await app.view.submit(body)
    const post = requests.filter(request => request.options?.method === 'POST')
    assert.equal(post.length, 1)
    assert.equal((post[0]?.options?.body as TrialRegistrationInput).password, 'senha-segura-123')
    assert.equal((post[0]?.options?.headers as Record<string, string>)['Idempotency-Key']?.length, 36)
    assert.equal(body.password, undefined)
    assert.equal((app.view.phase as Vue.Ref<string>).value, 'confirmed')
    assert.deepEqual((app.view.outcome as Vue.Ref<TrialRegistrationOutcome | null>).value, trialOutcome)
    assert.deepEqual((app.states.get('access-user') as Vue.Ref<typeof identity | null>).value, identity)
  })

  it('keeps a definitive 422 editable and sends corrected input with a new operation key', async (t) => {
    const keys: string[] = []
    let attempts = 0
    const app = setupTrial(async (_path, options) => {
      keys.push((options?.headers as Record<string, string>)['Idempotency-Key'] ?? '')
      if (++attempts === 1) throw { statusCode: 422, data: { code: 'validation_failed', message: 'Revise os dados.', errors: { cnpj: ['CNPJ inválido.'] } } }
      return { identity: { id: 31 }, data: trialOutcome }
    })
    t.after(app.stop)
    const body = input()
    await app.view.submit(body)
    assert.equal((app.view.phase as Vue.Ref<string>).value, 'form')
    assert.equal(body.password, 'senha-segura-123')
    assert.equal((app.view.failure as Vue.Ref<{ fieldErrors: Record<string, string[]> } | null>).value?.fieldErrors.cnpj?.[0], 'CNPJ inválido.')
    body.cnpj = '11222333000181'
    await app.view.submit(body)
    assert.notEqual(keys[0], keys[1])
    assert.equal((app.view.phase as Vue.Ref<string>).value, 'confirmed')
  })

  it('moves an ambiguous result to read-only recovery and never posts the password twice', async (t) => {
    const requests: Array<{ path: string, options?: Record<string, unknown> }> = []
    const app = setupTrial(async (path, options) => {
      requests.push({ path, options })
      if (options?.method === 'POST') throw { statusCode: 503, data: { message: 'temporarily unavailable' } }
      return { data: trialOutcome }
    })
    t.after(app.stop)
    const body = input()
    await app.view.submit(body)
    assert.equal(body.password, undefined)
    assert.equal((app.view.phase as Vue.Ref<string>).value, 'recovery')
    await app.view.recover()
    assert.equal(requests.filter(request => request.options?.method === 'POST').length, 1)
    assert.equal(requests.filter(request => request.path === '/erp/trials/current').length, 1)
    assert.equal(new Headers(requests.find(request => request.path === '/erp/trials/current')?.options?.headers as HeadersInit).get('Idempotency-Key')?.length, 36)
    assert.equal((app.view.phase as Vue.Ref<string>).value, 'confirmed')
  })

  it('uses the existing authenticated identity without including a password in the request', async (t) => {
    let body: Record<string, unknown> | undefined
    const identity = { id: 77, name: 'Ana', email: 'ana@example.test' }
    const app = setupTrial(async (_path, options) => {
      body = options?.body as Record<string, unknown>
      return { identity, data: trialOutcome }
    }, async () => identity)
    t.after(app.stop)
    ;(app.states.get('access-user') as Vue.Ref<unknown>).value = identity
    ;(app.states.get('access-resolved') as Vue.Ref<boolean>).value = true
    const request = input()
    request.name = identity.name
    request.email = identity.email
    request.password = undefined
    await app.view.submit(request)
    assert.equal(body?.email, identity.email)
    assert.equal('password' in (body ?? {}), false)
    assert.equal(((app.states.get('access-user') as Vue.Ref<typeof identity>).value).id, identity.id, 'the signup response cannot replace an already verified owner identity')
  })

  it('keeps every post-commit identity refresh failure in read-only recovery', async () => {
    for (const status of [403, 410, 422, 503]) {
      let posts = 0
      const app = setupTrial(async (_path, options) => {
        if (options?.method === 'POST') {
          posts++
          return { identity: { id: 31 }, data: trialOutcome }
        }
        return { data: trialOutcome }
      }, async () => { throw { statusCode: status } })
      const body = input()
      await app.view.submit(body)
      assert.equal(body.password, undefined, `status ${status} clears the password after the committed POST`)
      assert.equal(posts, 1)
      assert.equal((app.view.phase as Vue.Ref<string>).value, 'recovery', `status ${status} cannot reopen editable signup`)
      await app.view.recover()
      assert.equal(posts, 1, `status ${status} never replays the password POST`)
      app.stop()
    }
  })

  it('requires existing owners to log in and returns rotated links to an availability denial', async (t) => {
    const app = setupTrial(async (_path, options) => {
      if (options?.method === 'POST') throw { statusCode: 409, data: { code: 'login_required', message: 'Entre para continuar.' } }
      throw { statusCode: 410, data: { code: 'invalid_trial_link', message: 'Este link expirou.' } }
    })
    t.after(app.stop)
    const body = input()
    await app.view.submit(body)
    assert.equal((app.view.phase as Vue.Ref<string>).value, 'form')
    assert.equal((app.view.failure as Vue.Ref<{ code: string } | null>).value?.code, 'login_required')
    assert.equal(body.password, undefined)
    await app.view.load('rotated-code')
    assert.equal((app.view.phase as Vue.Ref<string>).value, 'availability')
    assert.equal((app.view.failure as Vue.Ref<{ http_status: number } | null>).value?.http_status, 410)
  })

  it('treats the actual Client association verification codes as definitive editable confirmation', async () => {
    for (const code of ['client_association_requires_owner_verification', 'confirm_existing_association_required']) {
      let posts = 0
      const app = setupTrial(async (_path, options) => {
        if (options?.method === 'POST') {
          posts++
          if (posts === 1) throw { statusCode: 409, data: { code, message: 'Confirme a relação com este escritório.' } }
          return { identity: { id: 31 }, data: trialOutcome }
        }
        return { data: trialOutcome }
      })
      const body = input()
      await app.view.submit(body)
      assert.equal((app.view.phase as Vue.Ref<string>).value, 'form', `${code} is an editable definitive response`)
      assert.equal((app.view.failure as Vue.Ref<{ code: string } | null>).value?.code, code)
      assert.equal(body.password, 'senha-segura-123')
      body.confirm_existing_association = true
      await app.view.submit(body)
      assert.equal(posts, 2)
      assert.equal((app.view.phase as Vue.Ref<string>).value, 'confirmed')
      app.stop()
    }
  })

  it('requires login for anonymous existing-client verification, then submits only an explicit authenticated confirmation', async () => {
    const owner = { id: 88, name: 'Ana Owner', email: 'ana@example.test' }
    let loggedIn = false
    let posts = 0
    const bodies: Array<Record<string, unknown>> = []
    const app = setupTrial(async (_path, options) => {
      if (options?.method === 'POST') {
        posts++
        bodies.push(options.body as Record<string, unknown>)
        if (posts === 1)
          throw { statusCode: 409, data: { code: 'client_association_requires_owner_verification', message: 'Entre para confirmar a associação.' } }
        return { identity: owner, data: trialOutcome }
      }
      return { data: { sponsor: { name: 'Escritório Exemplo' }, plan: { name: 'ERP Essencial' }, duration_days: 7, available: true } }
    }, async () => loggedIn ? owner : null)
    const mounted = renderPublicPage(app.view, app.states.get('access-user') as Vue.Ref<unknown>)
    try {
      const settle = async () => {
        for (let index = 0; index < 6; index++) await Promise.resolve()
        await Vue.nextTick()
      }
      await app.view.load('public-code')
      const initialForm = mounted.form
      assert.equal(!!initialForm, true)
      ;(initialForm.props.onSubmit as (event: { data: TrialRegistrationInput }) => void)({ data: input() })
      await settle()
      assert.equal(posts, 1)
      assert.equal(!!mounted.form, false, 'the anonymous association response cannot resubmit or present a checkbox loop')
      assert.equal(mounted.nodes().some(node => node.type === 'UButton' && node.props.target === '_blank' && (node.props.to ?? node.props.href) === '/login'), true)
      assert.equal(mounted.nodes().some(node => node.type === 'UCheckbox'), false)

      loggedIn = true
      const refresh = mounted.nodes().find(node => node.type === 'UButton' && String(node.children.map(child => child.text).join('')).includes('Já entrei'))
      assert.equal(!!refresh, true)
      await (refresh.props.onClick as () => Promise<void>)()
      await settle()
      assert.equal((app.states.get('access-user') as Vue.Ref<typeof owner>).value.id, owner.id)
      assert.equal(posts, 1, 'refreshing the logged-in identity never replays the registration')
      assert.equal(!!mounted.form, true)
      const checkbox = mounted.nodes().find(node => node.type === 'UCheckbox')
      assert.equal(!!checkbox, true, 'the association confirmation appears only after authentication refresh')
      ;(checkbox.props['onUpdate:modelValue'] as (value: boolean) => void)(true)
      ;(mounted.form!.props.onSubmit as (event: { data: TrialRegistrationInput }) => void)({ data: { ...input(), name: owner.name, email: owner.email, password: undefined, confirm_existing_association: true } })
      await settle()
      assert.equal(posts, 2)
      assert.equal(bodies[1]?.confirm_existing_association, true)
      assert.equal('password' in (bodies[1] ?? {}), false)
      assert.equal((app.view.phase as Vue.Ref<string>).value, 'confirmed')
    } finally {
      mounted.app.unmount()
      app.stop()
    }
  })

  it('denies a wrong Company owner without offering Client association confirmation', async () => {
    let posts = 0
    const bodies: Array<Record<string, unknown>> = []
    const keys: string[] = []
    const app = setupTrial(async (_path, options) => {
      if (options?.method === 'POST') {
        posts++
        bodies.push(options.body as Record<string, unknown>)
        keys.push((options.headers as Record<string, string>)['Idempotency-Key']!)
        if (posts === 1) throw { statusCode: 409, data: { code: 'company_association_required', message: 'Conflict' } }
        return { identity: { id: 21, name: 'Outro titular', email: 'outro@example.test' }, data: trialOutcome }
      }
      return { data: { sponsor: { name: 'Escritório Exemplo' }, plan: { name: 'ERP Essencial' }, duration_days: 7, available: true } }
    }, async () => ({ id: 21, name: 'Outro titular', email: 'outro@example.test' }))
    const mounted = renderPublicPage(app.view, app.states.get('access-user') as Vue.Ref<unknown>)
    try {
      await app.view.load('public-code')
      ;(mounted.form!.props.onSubmit as (event: { data: TrialRegistrationInput }) => void)({ data: input() })
      for (let index = 0; index < 6; index++) await Promise.resolve()
      await Vue.nextTick()
      assert.equal(posts, 1, 'the wrong-owner response does not automatically resubmit')
      assert.equal((app.view.phase as Vue.Ref<string>).value, 'form', 'the applicant may correct a mistyped CNPJ')
      assert.equal((app.view.pending as Vue.Ref<unknown>).value, null, 'the definitive rejection must not create recovery state')
      assert.equal((app.view.operationKey as Vue.Ref<string>).value, '', 'the rejected operation key is discarded')
      assert.equal(mounted.nodes().some(node => node.type === 'UCheckbox'), false)
      assert.ok(mounted.form, 'the signup form stays available for a corrected CNPJ')
      const cnpjField = mounted.nodes().find(node => node.type === 'UFormField' && node.props.name === 'cnpj')
      assert.ok(cnpjField)
      const cnpjInput = mounted.nodes().find(node => node.type === 'UInput' && node.props.inputmode === 'numeric')
      assert.ok(cnpjInput, 'the CNPJ field remains editable')
      const ownershipAlert = mounted.nodes().find(node => node.type === 'UAlert' && node.props.title === 'Não foi possível confirmar a titularidade')
      assert.ok(ownershipAlert)
      assert.match(String(ownershipAlert.props.description), /titular correto|proprietário correto/i)
      assert.match(String(ownershipAlert.props.description), /revise o CNPJ|fale com o escritório/i)
      assert.equal(mounted.nodes().some(node => node.type === 'UButton' && node.props.target === '_blank' && node.props.to === '/login'), true)
      ;(cnpjInput.props['onUpdate:modelValue'] as (value: string) => void)('98765432000198')
      await Vue.nextTick()
      ;(mounted.form!.props.onSubmit as (event: { data: TrialRegistrationInput }) => void)({ data: { ...input(), cnpj: '98765432000198', password: undefined } })
      for (let index = 0; index < 6; index++) await Promise.resolve()
      await Vue.nextTick()
      assert.equal(posts, 2, 'a corrected CNPJ is sent only after a second explicit form submission')
      assert.equal(bodies[1]?.cnpj, '98765432000198')
      assert.notEqual(keys[0], keys[1], 'a corrected CNPJ uses a fresh operation key')
      assert.equal((app.view.phase as Vue.Ref<string>).value, 'confirmed')
    } finally {
      mounted.app.unmount()
      app.stop()
    }
  })

  it('keeps route-specific pre-commit 409s out of ambiguous recovery', async () => {
    const cases = [
      ['trial_link_changed', 'availability'],
      ['trial_plan_modules_changed', 'availability'],
      ['client_not_active', 'availability'],
      ['company_subscription_ineligible', 'availability'],
      ['idempotency_conflict', 'form']
    ] as const
    for (const [code, expectedPhase] of cases) {
      const app = setupTrial(async (_path, options) => {
        if (options?.method === 'POST') throw { statusCode: 409, data: { code, message: 'Conflict' } }
        return { data: { sponsor: { name: 'Escritório Exemplo' }, plan: { name: 'ERP Essencial' }, duration_days: 7, available: true } }
      }, async () => null)
      const body = input()
      try {
        await app.view.load('public-code')
        await app.view.submit(body)
        assert.equal((app.view.phase as Vue.Ref<string>).value, expectedPhase, code)
        assert.notEqual((app.view.failure as Vue.Ref<{ message: string } | null>).value?.message, 'Conflict', `${code} uses product copy instead of an untrusted exception message`)
        assert.equal((app.view.pending as Vue.Ref<unknown>).value, null, `${code} is definitive before commit`)
        assert.equal((app.view.operationKey as Vue.Ref<string>).value, '', `${code} releases the rejected key`)
        assert.notEqual((app.view.phase as Vue.Ref<string>).value, 'recovery', code)
      } finally {
        app.stop()
      }
    }
  })

  it('maps public trial availability reason codes to safe Portuguese copy', async (t) => {
    const app = setupTrial(async () => ({
      data: { sponsor: { name: 'Escritório Exemplo' }, plan: { name: 'ERP Essencial' }, duration_days: 7, available: false, reason: 'trial_capacity_unavailable' }
    }), async () => null)
    t.after(app.stop)
    await app.view.load('public-code')
    const message = (app.view.failure as Vue.Ref<{ message: string } | null>).value?.message ?? ''
    assert.match(message, /limite de avaliações disponíveis/)
    assert.doesNotMatch(message, /trial_capacity_unavailable/)
  })

  it('refreshes identity before reading public availability and allows an anonymous refresh result', async (t) => {
    const calls: string[] = []
    const app = setupTrial(async (path) => {
      calls.push(path)
      return { data: { sponsor: { name: 'Escritório Exemplo' }, plan: { name: 'ERP Essencial' }, duration_days: 7, available: true, reason: null } }
    }, async () => {
      calls.push('/me')
      return null
    })
    t.after(app.stop)
    await app.view.load('public-code')
    assert.deepEqual(calls, ['/me', '/erp/trials/public-code'])
    assert.equal((app.view.phase as Vue.Ref<string>).value, 'form')
    assert.equal((app.states.get('access-resolved') as Vue.Ref<boolean>).value, true)
  })

  it('refreshes CSRF after a new-tab login before allowing an authenticated trial POST', async (t) => {
    const calls: string[] = []
    const identity = { id: 55, name: 'Ana', email: 'ana@example.test' }
    const app = setupTrial(async (path, options) => {
      calls.push(`${options?.method ?? 'GET'} ${path}`)
      if (options?.method === 'POST') return { identity, data: trialOutcome }
      return { data: { sponsor: { name: 'Escritório Exemplo' }, plan: { name: 'ERP Essencial' }, duration_days: 7, available: true } }
    }, async () => {
      calls.push('GET /me')
      return identity
    }, async () => { calls.push('GET /csrf') })
    t.after(app.stop)
    await app.view.load('public-code')
    const request = input()
    request.name = identity.name
    request.email = identity.email
    request.password = undefined
    await app.view.submit(request)
    assert.deepEqual(calls, ['GET /me', 'GET /csrf', 'GET /erp/trials/public-code', 'POST /erp/trials/public-code/register', 'GET /me'])
  })

  it('discards a late signup result when the authenticated session changes', async (t) => {
    let release!: (value: unknown) => void
    const response = new Promise<unknown>((resolve) => {
      release = resolve
    })
    const app = setupTrial(() => response)
    t.after(app.stop)
    const body = input()
    const pending = app.view.submit(body)
    ;(app.states.get('access-session-generation') as Vue.Ref<number>).value++
    release({ identity: { id: 31 }, data: trialOutcome })
    await pending
    assert.equal(body.password, undefined)
    assert.equal((app.view.outcome as Vue.Ref<TrialRegistrationOutcome | null>).value, null)
    assert.equal((app.view.phase as Vue.Ref<string>).value, 'availability')
  })
})
