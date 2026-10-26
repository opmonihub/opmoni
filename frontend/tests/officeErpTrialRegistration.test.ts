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

function setupTrial(api: (path: string, options?: Record<string, unknown>) => Promise<unknown>, refresh: () => Promise<unknown> = async () => ({ id: 31 })) {
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
      useAccessApi: () => ({ api }),
      useAuth: () => ({
        user: states.get('access-user'),
        resolved: states.get('access-resolved'),
        refreshWithStatus: async () => ({ identity: await refresh(), committed: true })
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

function renderPublicPage(trial: Record<string, unknown>) {
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
    useErpTrialRegistration: () => trial
  })
  const app = renderer.createApp(component)
  for (const name of ['NuxtLink', 'UAlert', 'UButton', 'UCheckbox', 'UForm', 'UFormField', 'UInput', 'USelect']) {
    app.component(name, Vue.defineComponent({
      name,
      inheritAttrs: true,
      setup(_props, { attrs, slots }) {
        return () => Vue.h(name, attrs, slots.default?.())
      }
    }))
  }
  app.mount(root)
  const all = (node: Node): Node[] => [node, ...node.children.flatMap(all)]
  const nodes = all(root)
  const visibleText = nodes.map(node => node.text ?? '').join(' ')
  const form = nodes.find(node => node.type === 'UForm')
  return { app, visibleText, form }
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
      availability: Vue.ref({ sponsor: { name: 'Escritório Exemplo' }, plan: { name: 'ERP Essencial' }, trial_duration_hours: 168, available: true }),
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

  it('treats a failed post-commit identity refresh as recovery, not a second submission', async (t) => {
    let posts = 0
    const app = setupTrial(async (_path, options) => {
      if (options?.method === 'POST') {
        posts++
        return { identity: { id: 31 }, data: trialOutcome }
      }
      return { data: trialOutcome }
    }, async () => { throw { statusCode: 503 } })
    t.after(app.stop)
    const body = input()
    await app.view.submit(body)
    assert.equal(body.password, undefined)
    assert.equal(posts, 1)
    assert.equal((app.view.phase as Vue.Ref<string>).value, 'recovery')
    await app.view.recover()
    assert.equal(posts, 1)
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
