import { registerOfficeErpPresentationStubs } from './helpers/officeErpPresentationStubs.ts'
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { refDebounced } from '@vueuse/core'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import { formatOfficeErpMoney } from '../shared/utils/officeErpCommercial.ts'

type HostNode = { tag?: string, text?: string, props: Record<string, unknown>, children: HostNode[], parent?: HostNode | null }
type Account = { id: number, status: 'active' | 'suspended', role: 'admin' | 'operador' }
type Identity = { id: number, current_account: Account }
type ApiCall = { path: string, options?: { params?: Record<string, unknown> }, authority: string }

const summary = (amount: string) => ({
  contracted_licences: 2,
  reserved_licences: 1,
  invoiced_movement: amount,
  effective_received: '20.00',
  outstanding: '70.00',
  overdue: '30.00',
  from_on: '2026-10-01',
  to_on: '2026-10-31',
  due_from: null,
  due_to: null
})

function client(name: string) {
  return { id: 5, registration_id: 2, client_id: 5, name, cnpj: '12.345.678/0001-90', registered_at: '2026-10-28T12:00:00Z', owner_setup_status: 'claimed', current_contract_id: null, capabilities: { relationship: 'unassigned', can_edit_profile: true, can_assign: true, can_manage_members: false, capability_key: 'company-capability' } }
}

function subscriptionCompany(name: string) {
  return { id: 5, registration_id: 2, client_id: 5, name, cnpj: '12.345.678/0001-90', registered_at: '2026-10-28T12:00:00Z', owner_setup_status: 'claimed', current_contract_id: 18, linked_contract_id: 18, relationship: 'current', subscription: { id: 19, status: 'active', erp_plan_id: 1, erp_plan: { id: 1, name: 'Plano ERP' } }, current_contract: { id: 18, agreement_status: 'effective', next_billing_on: '2026-11-01', balance: { outstanding: '50.00' } }, trial: null, action_capabilities: {} }
}

function invoice(name: string) {
  return {
    id: 41,
    contract_id: 18,
    number: 'FAT-000041',
    document_status: 'issued',
    due_on: '2026-11-10',
    original_total: '99.00',
    balance: { adjusted_charge: '99.00', effective_received: '0.00', outstanding: '99.00' },
    bill_to_snapshot: { client: { name }, company: null }
  }
}

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((done, fail) => {
    resolve = done
    reject = fail
  })
  return { promise, resolve, reject }
}

function text(node: HostNode): string {
  return `${node.text ?? ''}${node.children.map(text).join('')}`
}

function nodes(node: HostNode, predicate: (candidate: HostNode) => boolean): HostNode[] {
  return [...(predicate(node) ? [node] : []), ...node.children.flatMap(child => nodes(child, predicate))]
}

function find(node: HostNode, predicate: (candidate: HostNode) => boolean): HostNode | undefined {
  return nodes(node, predicate)[0]
}

function component(setup: (props: Record<string, unknown>, context: Vue.SetupContext) => () => unknown, props: string[] = []): Vue.Component {
  return { props, setup }
}

function mountRenderer(componentToMount: Vue.Component) {
  const root: HostNode = { props: {}, children: [], parent: null }
  const renderer = Vue.createRenderer<HostNode, HostNode>({
    patchProp(node, key, _previous, next) { node.props[key] = next },
    insert(node, parent, anchor) {
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      parent.children.splice(index < 0 ? parent.children.length : index, 0, node)
    },
    remove(node) {
      if (node.parent) {
        const index = node.parent.children.indexOf(node)
        if (index >= 0) node.parent.children.splice(index, 1)
      }
      node.parent = null
    },
    createElement: tag => ({ tag, props: {}, children: [], parent: null }),
    createText: value => ({ text: value, props: {}, children: [], parent: null }),
    createComment: value => ({ text: value, props: {}, children: [], parent: null }),
    setText(node, value) { node.text = value },
    setElementText(node, value) {
      node.text = value
      node.children = []
    },
    parentNode: node => node.parent ?? null,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null
  })
  const app = renderer.createApp(componentToMount)
  const passthrough = component((_props, ctx) => () => Vue.h('section', {}, [
    ctx.slots.default?.(), ctx.slots.toolbar?.(), ctx.slots.action?.(), ctx.slots.description?.(), ctx.slots.footer?.(), ctx.slots.empty?.()
  ]))
  app.component('DataTablePanelList', component((props, ctx) => () => Vue.h('section', { 'aria-label': props.title }, [
    ctx.slots.action?.(), ctx.slots.toolbar?.(), ctx.slots.default?.()
  ]), ['title']))
  app.component('OfficeErpPanel', component((props, ctx) => () => Vue.h('section', {}, [
    Vue.h('h2', {}, String(props.title ?? '')), ctx.slots.action?.(), ctx.slots.toolbar?.(), ctx.slots.default?.()
  ]), ['title', 'description']))
  app.component('OfficeErpDataTable', component((props, ctx) => () => Vue.h('div', {}, (props.data as Array<Record<string, unknown>> ?? []).map(original => Vue.h('article', {}, Object.entries(ctx.slots).filter(([name]) => name.endsWith('-cell')).map(([, slot]) => slot({ row: { original } }))))), ['data', 'columns', 'loading', 'getRowId', 'mobileColumns', 'compact', 'label']))
  app.component('OfficeErpPagination', component((props, ctx) => () => Vue.h('button', { 'aria-label': 'Próxima página', 'onClick': () => ctx.emit('update:page', Number(props.page) + 1) }, 'Próxima página'), ['page', 'perPage', 'total', 'label', 'loading']))
  app.component('OfficeErpCompanyInspectionModal', { setup: () => () => null })
  app.component('OfficeErpCompanyPortfolioModal', { setup: () => () => null })
  app.component('UPageGrid', passthrough)
  app.component('UPageCard', component((props, ctx) => () => Vue.h('article', {}, [
    Vue.h('h3', {}, String(props.title ?? '')),
    Vue.h('p', {}, String(props.description ?? '')),
    ctx.slots.header?.(), ctx.slots.default?.(), ctx.slots.footer?.()
  ]), ['title', 'description']))
  app.component('UAlert', component((props, ctx) => () => Vue.h('aside', { role: props.role ?? 'alert' }, [
    Vue.h('strong', {}, String(props.title ?? '')),
    Vue.h('span', {}, String(props.description ?? '')),
    ctx.slots.description?.(), ctx.slots.default?.()
  ]), ['title', 'description', 'role']))
  app.component('UButton', component((props, ctx) => () => Vue.h(props.to ? 'a' : 'button', {
    ...ctx.attrs,
    'aria-label': props['aria-label'] ?? ctx.attrs['aria-label'],
    'disabled': props.disabled,
    'href': props.to
  }, ctx.slots.default?.()), ['to', 'disabled', 'aria-label']))
  app.component('UInput', component((props, ctx) => () => Vue.h('input', {
    ...ctx.attrs,
    type: props.type ?? ctx.attrs.type ?? 'text',
    value: props.modelValue,
    onInput: (event: { target: { value: string } }) => ctx.emit('update:modelValue', event.target.value)
  }), ['modelValue', 'type']))
  app.component('USelect', component((props, ctx) => () => Vue.h('select', {
    ...ctx.attrs,
    value: props.modelValue,
    onChange: (event: { target: { value: string } }) => ctx.emit('update:modelValue', event.target.value)
  }, (props.items as Array<{ label: string, value: string }> ?? []).map(item => Vue.h('option', { value: item.value }, item.label))), ['modelValue', 'items']))
  app.component('UFormField', component((_props, ctx) => () => Vue.h('label', {}, [ctx.slots.default?.(), ctx.slots.hint?.()])))
  app.component('UBadge', passthrough)
  app.component('NuxtLink', component((props, ctx) => () => Vue.h('a', { href: props.to }, ctx.slots.default?.()), ['to']))
  app.component('UTable', component((props, ctx) => () => Vue.h('div', {}, (props.data as unknown[] ?? []).map(original => Vue.h('article', {}, Object.entries(ctx.slots).filter(([name]) => name.endsWith('-cell')).map(([, slot]) => slot({ row: { original, getIsExpanded: () => false, toggleExpanded: () => {} } }))))), ['data', 'columns', 'loading', 'ui']))
  app.component('UPagination', component((props, ctx) => () => Vue.h('button', {
    'aria-label': 'Próxima página',
    'disabled': props.disabled || Number(props.page) >= 2,
    'onClick': () => ctx.emit('update:page', Number(props.page) + 1)
  }, 'Próxima página'), ['page', 'total', 'itemsPerPage', 'disabled']))
  registerOfficeErpPresentationStubs(app)
  app.mount(root)
  return { app, root }
}

function capabilities(capabilityKey: string) {
  return {
    can_read: true,
    can_maintain: true,
    can_configure: true,
    can_sell_new: true,
    can_provision: true,
    can_start_trial: true,
    can_manage_trial_link: true,
    capability_reasons: {
      can_configure: [],
      can_sell_new: [],
      can_provision: [],
      can_start_trial: [],
      can_manage_trial_link: []
    },
    capability_key: capabilityKey,
    reasons: {},
    allowance: { enabled: true, licence_limit: 8, module_keys: ['purchases'] },
    usage: { reserved: 1, occupied: 2 }
  }
}

function mountCaller(path: string, options: {
  initialRead: (request?: ApiCall) => Promise<unknown>
  currentRead: (request?: ApiCall) => Promise<unknown>
  onToast?: (value: unknown) => void
  debounceSearch?: boolean
}): {
  app: Vue.App
  root: HostNode
  identity: Vue.Ref<Identity>
  session: Vue.Ref<number>
  context: { refresh(): Promise<void> }
  calls: ApiCall[]
  readCalls: ApiCall[]
  setCapabilityKey(value: string): void
  stop(): void
} {
  const identity = Vue.ref<Identity>({ id: 7, current_account: { id: 9, status: 'active', role: 'admin' } })
  const currentAccount = Vue.computed(() => identity.value.current_account)
  const session = Vue.ref(3)
  const capabilityKey = Vue.ref('cap-1')
  const states = new Map<string, Vue.Ref<unknown>>()
  const calls: ApiCall[] = []
  const readCalls: ApiCall[] = []
  const authority = () => JSON.stringify([identity.value.id, session.value, currentAccount.value.id, currentAccount.value.status, currentAccount.value.role, capabilityKey.value])
  const api = async (requestPath: string, requestOptions?: { params?: Record<string, unknown> }) => {
    const request: ApiCall = { path: requestPath, options: requestOptions, authority: authority() }
    calls.push(request)
    if (requestPath === '/erp-commercial/context') return { data: capabilities(capabilityKey.value) }
    readCalls.push(request)
    return request.authority === JSON.stringify([7, 3, 9, 'active', 'admin', 'cap-1'])
      ? options.initialRead(request)
      : options.currentRead(request)
  }

  const bindings = {
    ref: Vue.ref,
    computed: Vue.computed,
    watch: Vue.watch,
    onMounted: Vue.onMounted,
    onBeforeUnmount: Vue.onBeforeUnmount,
    readonly: Vue.readonly,
    onScopeDispose: Vue.onScopeDispose,
    useNuxtApp: () => ({}),
    useRoute: () => ({ query: {} }),
    useState: <T>(key: string, init: () => T) => {
      if (key === 'access-session-generation') return session as Vue.Ref<T>
      if (!states.has(key)) states.set(key, Vue.ref(init()))
      return states.get(key) as Vue.Ref<T>
    },
    useAuth: () => ({ user: identity, currentAccount }),
    useAccessApi: () => ({ api }),
    refDebounced: options.debounceSearch ? refDebounced : (value: Vue.Ref<unknown>) => value,
    definePageMeta() {},
    useToast: () => ({ add: (value: unknown) => options.onToast?.(value) }),
    formatOfficeErpMoney,
    formatOfficeErpDate: (value: string) => value
  }
  const loadedContext = loadAppComposable<Record<string, unknown>>('composables/useOfficeErpContext.ts', bindings)
  const scope = Vue.effectScope()
  const context = scope.run(() => (loadedContext.useOfficeErpContext as () => { refresh(): Promise<void> })())!
  const callerBindings = { ...bindings, useOfficeErpContext: () => context }
  const componentToMount = compileVueComponent(path, callerBindings)
  const mounted = mountRenderer(componentToMount)
  return {
    ...mounted,
    identity,
    session,
    context,
    calls,
    readCalls,
    setCapabilityKey(value) { capabilityKey.value = value },
    stop() {
      mounted.app.unmount()
      scope.stop()
    }
  }
}

async function settle() {
  for (let index = 0; index < 6; index++) {
    await new Promise(resolve => setImmediate(resolve))
    await Vue.nextTick()
  }
}

async function until(predicate: () => boolean) {
  for (let index = 0; index < 30 && !predicate(); index++) await settle()
  assert.equal(predicate(), true, 'expected the rendered caller request to reach the next boundary')
}

const callers = [
  {
    name: 'overview', path: 'pages/gestao-erp/index.vue', endpoint: '/erp-commercial/summary',
    oldResponse: () => ({ data: summary('111.00') }), currentResponse: () => ({ data: summary('222.00') }),
    oldMarker: 'R$ 111,00', currentMarker: 'R$ 222,00',
    trigger(page: ReturnType<typeof mountCaller>) {
      const button = find(page.root, node => node.tag === 'button' && text(node).includes('Atualizar'))
      assert.ok(button)
      ;(button.props.onClick as () => void)()
    }
  },
  {
    name: 'Company list', path: 'pages/gestao-erp/empresas/index.vue', endpoint: '/erp-commercial/companies',
    oldResponse: () => ({ data: [client('Old Protected Client')], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }),
    currentResponse: () => ({ data: [client('Current Client')], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }),
    oldMarker: 'Old Protected Client', currentMarker: 'Current Client',
    trigger(page: ReturnType<typeof mountCaller>) {
      const input = find(page.root, node => node.tag === 'input' && node.props['aria-label'] === 'Buscar empresa')
      assert.ok(input)
      ;(input.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'second read' } })
    }
  },
  {
    name: 'Company subscription list', path: 'pages/gestao-erp/assinaturas/index.vue', endpoint: '/erp-commercial/subscription-companies',
    oldResponse: () => ({ data: [subscriptionCompany('Old Protected Contract')], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }),
    currentResponse: () => ({ data: [subscriptionCompany('Current Contract')], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }),
    oldMarker: 'Old Protected Contract', currentMarker: 'Current Contract',
    trigger(page: ReturnType<typeof mountCaller>) {
      const input = find(page.root, node => node.tag === 'input' && node.props['aria-label'] === 'Buscar empresa')
      assert.ok(input)
      ;(input.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'second read' } })
    }
  },
  {
    name: 'invoice list', path: 'pages/gestao-erp/faturas/index.vue', endpoint: '/erp-commercial/invoices',
    oldResponse: () => ({ data: [invoice('Old Protected Invoice')], meta: { current_page: 1, last_page: 2, per_page: 25, total: 26 } }),
    currentResponse: () => ({ data: [invoice('Current Invoice')], meta: { current_page: 1, last_page: 2, per_page: 25, total: 26 } }),
    oldMarker: 'Old Protected Invoice', currentMarker: 'Current Invoice',
    trigger(page: ReturnType<typeof mountCaller>) {
      const input = find(page.root, node => node.tag === 'input' && node.props['aria-label'] === 'Buscar fatura')
      assert.ok(input)
      ;(input.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'second read' } })
    }
  }
] as const

type Switch = 'account' | 'session' | 'capability'
const switches: Switch[] = ['account', 'session', 'capability']
for (const caller of callers) {
  for (const switchType of switches) {
    for (const outcome of ['success', 'error'] as const) {
      it(`${caller.name} clears old rows and fences a delayed ${outcome} after ${switchType} authority changes`, async (t) => {
        const oldRead = deferred<unknown>()
        const currentRead = deferred<unknown>()
        let originalReadCount = 0
        const page = mountCaller(caller.path, {
          initialRead: () => ++originalReadCount === 1 ? Promise.resolve(caller.oldResponse()) : oldRead.promise,
          currentRead: () => currentRead.promise
        })
        t.after(page.stop)

        await until(() => page.readCalls.some(call => call.path === caller.endpoint))
        await settle()
        assert.match(text(page.root), new RegExp(caller.oldMarker.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), JSON.stringify(page.calls))

        caller.trigger(page as ReturnType<typeof mountCaller>)
        await until(() => page.readCalls.filter(call => call.path === caller.endpoint).length >= 2)
        const baselineAuthority = JSON.stringify([7, 3, 9, 'active', 'admin', 'cap-1'])
        const originalRead = page.readCalls.filter(call => call.path === caller.endpoint && call.authority === baselineAuthority).at(-1)
        assert.ok(originalRead)
        assert.ok(page.readCalls.indexOf(originalRead) >= 1, 'the rendered search or refresh issued a second old-authority read')

        if (switchType === 'account') {
          page.identity.value = { id: 7, current_account: { id: 10, status: 'active', role: 'admin' } }
        } else if (switchType === 'session') {
          page.session.value++
        } else {
          page.setCapabilityKey('cap-2')
          await page.context.refresh()
        }
        await until(() => page.readCalls.some(call => call.path === caller.endpoint && call.authority !== baselineAuthority))
        await settle()
        assert.doesNotMatch(text(page.root), new RegExp(caller.oldMarker.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))

        currentRead.resolve(caller.currentResponse())
        await settle()
        assert.match(text(page.root), new RegExp(caller.currentMarker.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))

        if (outcome === 'success') oldRead.resolve(caller.oldResponse())
        else oldRead.reject(new Error('Old authority read failed'))
        await settle()
        assert.match(text(page.root), new RegExp(caller.currentMarker.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))
        assert.doesNotMatch(text(page.root), /Old authority read failed|Old Protected/)
      })
    }
  }
}

describe('rendered invoice list controls and read states', () => {
  it('coalesces rapid invoice typing into one authorized read for the final search', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] })
    const response = { data: [invoice('Search Result')], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }
    const page = mountCaller('pages/gestao-erp/faturas/index.vue', {
      debounceSearch: true,
      initialRead: async () => response,
      currentRead: async () => response
    })
    t.after(page.stop)
    await settle()
    const initialCalls = page.calls.length
    const search = find(page.root, node => node.tag === 'input' && node.props['aria-label'] === 'Buscar fatura')
    assert.ok(search)
    for (const value of ['N', 'NF', 'NF 41']) {
      ;(search.props.onInput as (event: { target: { value: string } }) => void)({ target: { value } })
      await settle()
      t.mock.timers.tick(80)
    }
    assert.equal(page.calls.length, initialCalls, 'typing should not queue context or invoice requests')
    t.mock.timers.tick(300)
    await settle()
    const newCalls = page.calls.slice(initialCalls)
    assert.deepEqual(newCalls.map(call => call.path), ['/erp-commercial/context', '/erp-commercial/invoices'])
    assert.equal(newCalls[1]?.options?.params?.q, 'NF 41')
  })

  it('keeps same-query confirmed invoices visible and labeled when refresh fails', async (t) => {
    let reads = 0
    const page = mountCaller('pages/gestao-erp/faturas/index.vue', {
      initialRead: async () => {
        reads++
        if (reads === 2) throw Object.assign(new Error('Invoice refresh unavailable'), { statusCode: 503 })
        return {
          data: [invoice(reads === 1 ? 'Last Confirmed Invoice' : 'Recovered Invoice')],
          meta: { current_page: 1, last_page: 2, per_page: 25, total: 26 }
        }
      },
      currentRead: async () => ({ data: [invoice('Other Authority Invoice')], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } })
    })
    t.after(page.stop)
    await settle()
    assert.match(text(page.root), /Last Confirmed Invoice/)

    const refresh = find(page.root, node => node.tag === 'button' && text(node).includes('Atualizar'))
    assert.ok(refresh, 'the rendered invoice list exposes a same-filter refresh action')
    ;(refresh.props.onClick as () => void)()
    await settle()

    assert.equal(reads, 2)
    assert.match(text(page.root), /Invoice refresh unavailable/)
    assert.match(text(page.root), /Last Confirmed Invoice/)
    assert.match(text(page.root), /última consulta confirmada/i)
    assert.match(text(page.root), /26 faturas/)
    const retry = find(page.root, node => node.tag === 'button' && text(node).includes('Tentar novamente'))
    assert.ok(retry)
    ;(retry.props.onClick as () => void)()
    await settle()
    assert.match(text(page.root), /Recovered Invoice/)
    assert.doesNotMatch(text(page.root), /Invoice refresh unavailable|Last Confirmed Invoice|última consulta confirmada/i)
  })

  it('does not present invoices from an older filter as results for a failed search', async (t) => {
    let reads = 0
    const page = mountCaller('pages/gestao-erp/faturas/index.vue', {
      initialRead: async () => {
        reads++
        if (reads > 1) throw new Error('Filtered invoice search unavailable')
        return { data: [invoice('Previous Filter Invoice')], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }
      },
      currentRead: async () => ({ data: [invoice('Other Authority Invoice')], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } })
    })
    t.after(page.stop)
    await settle()
    assert.match(text(page.root), /Previous Filter Invoice/)

    const search = find(page.root, node => node.tag === 'input' && node.props['aria-label'] === 'Buscar fatura')
    assert.ok(search)
    ;(search.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'new filter' } })
    await settle()

    assert.match(text(page.root), /Filtered invoice search unavailable/)
    assert.doesNotMatch(text(page.root), /Previous Filter Invoice|última consulta confirmada/i)
    assert.equal(find(page.root, node => node.tag === 'article' && text(node).includes('Previous Filter Invoice')), undefined)
  })

  for (const statusCode of [401, 403]) {
    it(`discards confirmed invoice content after a ${statusCode} context denial`, async (t) => {
      let reads = 0
      const page = mountCaller('pages/gestao-erp/faturas/index.vue', {
        initialRead: async () => {
          reads++
          if (reads > 1) throw Object.assign(new Error(`Invoice denied ${statusCode}`), { statusCode })
          return { data: [invoice('Protected Invoice')], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }
        },
        currentRead: async () => ({ data: [invoice('Other Authority Invoice')], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } })
      })
      t.after(page.stop)
      await settle()
      assert.match(text(page.root), /Protected Invoice/)

      const refresh = find(page.root, node => node.tag === 'button' && text(node).includes('Atualizar'))
      assert.ok(refresh)
      ;(refresh.props.onClick as () => void)()
      await settle()

      assert.match(text(page.root), new RegExp(`Invoice denied ${statusCode}`))
      assert.doesNotMatch(text(page.root), /Protected Invoice|última consulta confirmada/i)
    })
  }

  it('discards confirmed invoice content when the server rejects the account context', async (t) => {
    let reads = 0
    const page = mountCaller('pages/gestao-erp/faturas/index.vue', {
      initialRead: async () => {
        reads++
        if (reads > 1) {
          throw Object.assign(new Error('The selected account changed'), {
            statusCode: 409,
            data: { code: 'account_context_changed' }
          })
        }
        return { data: [invoice('Protected Invoice')], meta: { current_page: 1, last_page: 2, per_page: 25, total: 26 } }
      },
      currentRead: async () => ({ data: [invoice('Other Authority Invoice')], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } })
    })
    t.after(page.stop)
    await settle()
    assert.match(text(page.root), /Protected Invoice/)

    const refresh = find(page.root, node => node.tag === 'button' && text(node).includes('Atualizar'))
    assert.ok(refresh)
    ;(refresh.props.onClick as () => void)()
    await settle()

    assert.match(text(page.root), /The selected account changed/)
    assert.doesNotMatch(text(page.root), /Protected Invoice|26 faturas|última consulta confirmada/i)
  })

  it('sends the actual search, status, date and pagination controls as server filters', async (t) => {
    const page = mountCaller('pages/gestao-erp/faturas/index.vue', {
      initialRead: async request => ({ data: [invoice('Current Invoice')], meta: { current_page: Number(request?.options?.params?.page ?? 1), last_page: 3, per_page: 25, total: 51 } }),
      currentRead: async request => ({ data: [invoice('Current Invoice')], meta: { current_page: Number(request?.options?.params?.page ?? 1), last_page: 3, per_page: 25, total: 51 } })
    })
    t.after(page.stop)
    await settle()

    const input = (label: string) => find(page.root, node => node.tag === 'input' && node.props['aria-label'] === label)!
    const update = (node: HostNode, value: string) => (node.props.onInput as (event: { target: { value: string } }) => void)({ target: { value } })
    update(input('Buscar fatura'), 'NF 41')
    await settle()
    const panel = find(page.root, node => node.props['data-filter-panel'] === true)
    assert.ok(panel)
    ;(panel.props.onApplyFilters as (filters: unknown[]) => void)([
      { columnId: 'status', type: 'option', operator: 'is', values: ['issued'] },
      { columnId: 'issued', type: 'date', operator: 'is between', values: ['2026-10-01', '2026-10-31'] },
      { columnId: 'due', type: 'date', operator: 'is between', values: ['2026-11-01', '2026-11-30'] }
    ])
    await settle()
    const nextPage = find(page.root, node => node.tag === 'button' && node.props['aria-label'] === 'Próxima página')
    assert.ok(nextPage)
    ;(nextPage.props.onClick as () => void)()
    await settle()

    const latest = page.readCalls.filter(call => call.path === '/erp-commercial/invoices').at(-1)
    assert.deepEqual(latest?.options?.params, {
      page: 2,
      per_page: 25,
      q: 'NF 41',
      status: 'issued',
      from_on: '2026-10-01',
      to_on: '2026-10-31',
      due_from: '2026-11-01',
      due_to: '2026-11-30'
    })
    assert.match(text(page.root), /Current Invoice/)
  })

  it('distinguishes a failed read from a confirmed empty result and then renders current rows', async (t) => {
    let resultNumber = 0
    const page = mountCaller('pages/gestao-erp/faturas/index.vue', {
      initialRead: async () => {
        resultNumber++
        if (resultNumber === 1) throw new Error('Invoice service unavailable')
        if (resultNumber === 2) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
        return { data: [invoice('Current Invoice')], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }
      },
      currentRead: async () => ({ data: [invoice('Current Invoice')], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } })
    })
    t.after(page.stop)
    await settle()
    assert.match(text(page.root), /Invoice service unavailable/)
    assert.doesNotMatch(text(page.root), /Nenhuma fatura encontrada/)

    const retry = find(page.root, node => node.tag === 'button' && text(node).includes('Tentar novamente'))
    assert.ok(retry)
    ;(retry.props.onClick as () => void)()
    await settle()
    assert.match(text(page.root), /Nenhuma fatura encontrada/)
    assert.doesNotMatch(text(page.root), /Invoice service unavailable/)

    const search = find(page.root, node => node.tag === 'input' && node.props['aria-label'] === 'Buscar fatura')
    assert.ok(search)
    ;(search.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'FAT-41' } })
    await settle()
    assert.match(text(page.root), /Current Invoice/)
    assert.doesNotMatch(text(page.root), /Nenhuma fatura encontrada|Invoice service unavailable/)
  })
})
