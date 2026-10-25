import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import type { Identity, Membership } from '../shared/types/access.ts'
import * as officeErpMoney from '../shared/utils/officeErpCommercial.ts'
import * as panel from '../app/components/data-table/panel.ts'
import * as apiErrors from '../app/composables/useApiError.ts'

const editorPath = 'components/office-erp/OfficeErpPlanEditor.vue'

function office(id: number, role: Membership['role'] = 'admin', status: Membership['status'] = 'active'): Membership {
  return { id, name: `Escritório ${id}`, role, status }
}

function identity(current: Membership | null, isSuperAdmin = false): Identity {
  return { id: 5, name: 'Usuário', email: 'user@example.test', is_super_admin: isSuperAdmin, accounts: current ? [current] : [], current_account: current, companies: [], current_company: null }
}

function compileSfcSetup(path: string, globals: Record<string, unknown>) {
  const file = new URL(`../app/${path}`, import.meta.url)
  const { descriptor } = parse(readFileSync(file, 'utf8'), { filename: file.pathname })
  const script = compileScript(descriptor, { id: path })
  const compiled = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } })
  const exports = {} as { default: { setup: (props: Record<string, unknown>, context: Record<string, unknown>) => unknown } }
  new Function('require', 'exports', ...Object.keys(globals), compiled.outputText)(
    (specifier: string) => {
      if (specifier === 'vue') return Vue
      if (specifier === '#shared/utils/officeErpCommercial') return officeErpMoney
      if (specifier === '~/components/data-table/panel') return panel
      if (specifier === '~/composables/useApiError') return apiErrors
      throw new Error(`Unexpected SFC import: ${specifier}`)
    }, exports, ...Object.values(globals)
  )
  return exports.default
}

function flushVue() {
  return Vue.nextTick().then(() => new Promise<void>(resolve => setImmediate(resolve))).then(() => Vue.nextTick())
}

const capabilities = Vue.ref({
  can_read: true,
  can_maintain: true,
  can_configure: true,
  can_sell_new: true,
  capability_key: 'capability-test',
  reasons: {},
  allowance: { enabled: true, licence_limit: 4, module_keys: ['purchases'] },
  usage: { reserved: 1, occupied: 2 }
})

function plansSetup(api: (path: string, options?: Record<string, unknown>) => Promise<unknown>, commandOverride?: Record<string, unknown>) {
  const currentUser = Vue.ref(identity(office(8)))
  const currentAccount = Vue.computed(() => currentUser.value.current_account)
  const session = Vue.ref(2)
  const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
  const toasts: Array<Record<string, unknown>> = []
  const pending = Vue.ref(false)
  const recoveryKey = Vue.ref<string | null>(null)
  const activeContext = {
    capabilities,
    capture: () => ({ key: JSON.stringify([currentUser.value.id, currentAccount.value?.id, session.value, capabilities.value?.capability_key]), generation: session.value }),
    isCurrent: (token: { key: string, generation: number }) => token.key === JSON.stringify([currentUser.value.id, currentAccount.value?.id, session.value, capabilities.value?.capability_key]) && token.generation === session.value,
    refresh: async () => {}
  }
  const command = commandOverride ?? {
    pending,
    recoveryKey,
    execute: async () => ({ status: 'confirmed', data: { id: 1 } }),
    recover: async () => ({ status: 'confirmed', data: { id: 1 } })
  }
  const component = compileSfcSetup('pages/gestao-erp/planos.vue', {
    ref: Vue.ref,
    computed: Vue.computed,
    watch: Vue.watch,
    refDebounced: (value: Vue.Ref<unknown>) => value,
    useAccessApi: () => ({ api: async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      return api(path, options)
    } }),
    useAuth: () => ({ user: currentUser, currentAccount }),
    useState: () => session,
    useOfficeErpContext: () => activeContext,
    useOfficeErpCommand: () => command,
    useRoute: () => ({ query: {} }),
    useToast: () => ({ add: (value: Record<string, unknown>) => { toasts.push(value) } }),
    definePageMeta() {}
  })
  const scope = Vue.effectScope()
  const state = scope.run(() => component.setup({}, { expose() {} })) as Record<string, unknown>
  return { state, currentUser, currentAccount, session, calls, toasts, command, pending, recoveryKey, scope }
}

function listResponse(overrides: Record<string, unknown> = {}) {
  return {
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
    ...overrides
  }
}

describe('Office ERP navigation and access', () => {
  it('admits only an active selected office administrator to office commercial routes', async () => {
    const selected = Vue.ref(identity(office(8)))
    const navs: string[] = []
    const middleware = loadAppComposable<Record<string, unknown>>('middleware/access.global.ts', {
      defineNuxtRouteMiddleware: (handler: unknown) => handler,
      useAuth: () => ({ user: selected, resolved: Vue.ref(true), refresh: async () => selected.value }),
      useAccessApi: () => ({ api: async () => ({ registration_available: false }) }),
      navigateTo: (path: string) => {
        navs.push(path)
        return path
      }
    }).default as (to: { path: string }) => Promise<unknown>

    assert.equal(await middleware({ path: '/gestao-erp/planos' }), undefined)
    selected.value = identity(office(8, 'operador'))
    assert.equal(await middleware({ path: '/gestao-erp/planos' }), '/')
    selected.value = identity(null, true)
    assert.equal(await middleware({ path: '/gestao-erp/planos' }), '/acesso-indisponivel')
    selected.value = identity(office(8, 'admin', 'suspended'))
    assert.equal(await middleware({ path: '/gestao-erp' }), '/acesso-indisponivel')
    assert.deepEqual(navs, ['/', '/acesso-indisponivel', '/acesso-indisponivel'])
  })

  it('redirects the exact commercial root to its shipped plans list', async () => {
    const navigations: unknown[] = []
    const page = compileVueComponent('pages/gestao-erp.vue', {
      definePageMeta() {},
      useRoute: () => ({ path: '/gestao-erp' }),
      useAuth: () => ({ currentAccount: Vue.ref(office(8)) }),
      navigateTo: async (...args: unknown[]) => { navigations.push(args) }
    }) as { setup: (props: object, context: object) => unknown }
    page.setup({}, { expose() {} })
    assert.deepEqual(navigations, [['/gestao-erp/planos', { replace: true }]])
  })

  it('keeps legacy plan bookmarks privileged and redirects only after office authorization', async () => {
    for (const [account, canRead, expectedRedirect] of [
      [office(8, 'operador'), true, false],
      [office(8), false, false],
      [office(8), true, true]
    ] as const) {
      const user = Vue.ref(identity(account, true))
      const session = Vue.ref(4)
      const navigations: unknown[] = []
      let mount: (() => void) | undefined
      const metadata: Record<string, unknown>[] = []
      const component = compileSfcSetup('pages/admin/erp/planos.vue', {
        ref: Vue.ref,
        useAuth: () => ({ user, currentAccount: Vue.computed(() => user.value.current_account) }),
        useState: () => session,
        useOfficeErpContext: () => ({ capabilities: Vue.ref({ can_read: canRead }), refresh: async () => {} }),
        onMounted: (callback: () => void) => { mount = callback },
        navigateTo: async (...args: unknown[]) => { navigations.push(args) },
        definePageMeta: (value: Record<string, unknown>) => { metadata.push(value) }
      })
      const scope = Vue.effectScope()
      scope.run(() => component.setup({}, { expose() {} }))
      await mount?.()

      assert.deepEqual(metadata, [{ middleware: ['auth', 'super-admin'] }])
      assert.equal(navigations.length, expectedRedirect ? 1 : 0)
      if (expectedRedirect) {
        assert.deepEqual(navigations[0], [
          { path: '/gestao-erp/planos', query: { origem: 'admin-erp' } }, { replace: true }
        ])
      }
      scope.stop()
    }
  })
})

describe('Office ERP offer editor SFC', () => {
  it('submits the exact large BRL amount as a canonical string', () => {
    const emitted: unknown[] = []
    const component = compileVueComponent(editorPath, {
      parseOfficeErpMoney: (value: string) => value,
      officeErpModuleOptions: []
    }) as { setup: (props: Record<string, unknown>, context: { emit: (...args: unknown[]) => void, expose(value: Record<string, unknown>): void }) => unknown }
    const scope = Vue.effectScope()
    let exposed: Record<string, unknown> = {}
    scope.run(() => component.setup({ open: true, offer: null, moduleKeys: ['purchases'], saving: false, fieldErrors: {}, canSellNew: true }, {
      emit: (...args) => emitted.push(args),
      expose(value) { exposed = value }
    }))
    const form = exposed.form as { name: string, price: string, intervalMonths: string, dueInDays: string, moduleKeys: string[], published: boolean }
    form.name = 'Plano Enterprise'
    form.price = '9007199254740993,01'
    form.intervalMonths = '12'
    form.dueInDays = '15'
    form.moduleKeys = ['purchases']
    form.published = true
    ;(exposed.submit as () => void)()

    assert.deepEqual(emitted[0], ['save', {
      name: 'Plano Enterprise', description: '', price: '9007199254740993.01', currency: 'BRL', interval_months: 12,
      due_in_days: 15, module_keys: ['purchases'], published: true
    }])
    scope.stop()
  })

  it('preserves entered values while showing server field errors', () => {
    const emitted: unknown[] = []
    const component = compileVueComponent(editorPath, {
      parseOfficeErpMoney: officeErpMoney.parseOfficeErpMoney,
      officeErpModuleOptions: officeErpMoney.officeErpModuleOptions
    }) as { setup: (props: Record<string, unknown>, context: { emit: (...args: unknown[]) => void, expose(value: Record<string, unknown>): void }) => unknown }
    const scope = Vue.effectScope()
    let exposed: Record<string, unknown> = {}
    scope.run(() => component.setup({
      open: true, offer: null, moduleKeys: ['purchases'], saving: false, fieldErrors: { price: ['Preço rejeitado pelo servidor.'] }, canSellNew: true
    }, { emit: (...args) => emitted.push(args), expose(value) { exposed = value } }))
    const form = exposed.form as { name: string, price: string, intervalMonths: string, dueInDays: string, moduleKeys: string[], published: boolean }
    Object.assign(form, { name: 'Plano mantido', price: '12,34', intervalMonths: '3', dueInDays: '7', moduleKeys: ['purchases'], published: false })
    ;(exposed.submit as () => void)()

    assert.equal(form.name, 'Plano mantido')
    assert.equal(form.price, '12,34')
    assert.equal(form.intervalMonths, '3')
    assert.equal(form.dueInDays, '7')
    assert.deepEqual(emitted[0], ['save', {
      name: 'Plano mantido', description: '', price: '12.34', currency: 'BRL', interval_months: 3,
      due_in_days: 7, module_keys: ['purchases'], published: false
    }])
    scope.stop()
  })
})

describe('Office ERP offers list SFC', () => {
  it('loads server search, status and counted pagination parameters', async () => {
    const apiCalls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const harness = plansSetup(async (path, options) => {
      apiCalls.push({ path, options })
      return listResponse({ meta: { current_page: 2, last_page: 4, per_page: 50, total: 173 } })
    })
    await flushVue()
    const state = harness.state as Record<string, unknown>
    const query = state.query as Vue.Ref<string>
    const status = state.status as Vue.Ref<string>
    const perPage = state.perPage as Vue.Ref<number>
    const page = state.page as Vue.Ref<number>
    query.value = '  Contábil  '
    status.value = 'archived'
    perPage.value = 50
    await flushVue()
    page.value = 2
    await (state.loadOffers as () => Promise<void>)()

    const latest = apiCalls.at(-1)
    assert.equal(latest?.path, '/erp-commercial/plans')
    assert.deepEqual(latest?.options?.params, { page: 2, per_page: 50, status: 'archived', q: 'Contábil' })
    assert.equal((state.total as Vue.Ref<number>).value, 173)
    assert.equal((state.lastPage as Vue.Ref<number>).value, 4)
    assert.equal((state.page as Vue.Ref<number>).value, 2)
    harness.scope.stop()
  })

  it('keeps a failed initial read distinct from a confirmed empty list', async () => {
    const harness = plansSetup(async () => Promise.reject(new Error('API indisponível')))
    await flushVue()
    const state = harness.state as Record<string, unknown>
    assert.equal((state.confirmedEmpty as Vue.Ref<boolean>).value, false)
    assert.equal((state.loadError as Vue.Ref<string>).value, 'API indisponível')
    harness.scope.stop()
  })

  it('keeps the editor open and returns server field errors after a rejected save', async () => {
    const command = {
      pending: Vue.ref(false), recoveryKey: Vue.ref(null),
      execute: async () => ({
        status: 'failed',
        failure: { code: 'validation_failed', message: 'Revise os campos.', http_status: 422, errors: { price: ['Preço inválido.'] } }
      }),
      recover: async () => ({ status: 'stale' })
    }
    const harness = plansSetup(async () => listResponse(), command)
    await flushVue()
    const state = harness.state as Record<string, unknown>
    ;(state.openCreate as () => void)()
    await (state.saveOffer as (terms: Record<string, unknown>) => Promise<void>)({
      name: 'Plano digitado', description: '', price: '12.34', currency: 'BRL', interval_months: 1, due_in_days: 0, module_keys: [], published: true
    })

    assert.equal((state.editorOpen as Vue.Ref<boolean>).value, true)
    assert.equal((state.mutationFailure as Vue.Ref<string>).value, 'Revise os campos.')
    assert.deepEqual((state.fieldErrors as Vue.Ref<Record<string, string[]>>).value, { price: ['Preço inválido.'] })
    harness.scope.stop()
  })

  it('discards a delayed save after the selected Account changes', async () => {
    let finish!: (value: unknown) => void
    const command = {
      pending: Vue.ref(false), recoveryKey: Vue.ref(null),
      execute: () => new Promise((resolve) => { finish = resolve }),
      recover: async () => ({ status: 'confirmed', data: {} })
    }
    const harness = plansSetup(async () => listResponse(), command)
    await flushVue()
    const state = harness.state as Record<string, unknown>
    ;(state.openCreate as () => void)()
    const save = (state.saveOffer as (terms: Record<string, unknown>) => Promise<void>)({
      name: 'Plano', description: '', price: '1.00', currency: 'BRL', interval_months: 1, due_in_days: 0, module_keys: [], published: true
    })
    harness.currentUser.value = identity(office(9))
    await flushVue()
    finish({ status: 'confirmed', data: { id: 1 } })
    await save

    assert.equal((state.editorOpen as Vue.Ref<boolean>).value, false)
    assert.deepEqual(harness.toasts, [])
    harness.scope.stop()
  })

  it('closes the editor and invalidates rows when server capability identity changes', async () => {
    const harness = plansSetup(async () => listResponse())
    await flushVue()
    const state = harness.state as Record<string, unknown>
    ;(state.openCreate as () => void)()
    const original = capabilities.value
    const requestsBeforeRefresh = harness.calls.length
    capabilities.value = { ...original, capability_key: 'capability-refreshed' }
    await flushVue()

    assert.equal((state.editorOpen as Vue.Ref<boolean>).value, false)
    assert.ok(harness.calls.length > requestsBeforeRefresh)
    capabilities.value = original
    harness.scope.stop()
  })
})
