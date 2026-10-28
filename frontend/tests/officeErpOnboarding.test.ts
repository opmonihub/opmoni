import { registerOfficeErpPresentationStubs } from './helpers/officeErpPresentationStubs.ts'
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import { officeErpSidebarItem } from '../app/utils/sidebarNav.ts'

type TestNode = { tag?: string, text?: string, props: Record<string, unknown>, children: TestNode[], parent?: TestNode | null }

type ApiCall = { path: string, options?: Record<string, unknown> }

type InvitationCommand = {
  pending: Vue.ComputedRef<boolean>
  recoveryKey: Vue.ComputedRef<string | null>
  executeCalls: unknown[][]
  recoverCalls: string[]
  executeResults: unknown[]
  recoveryResult: unknown
  restore?: (key: string, result: unknown) => void
  execute: (...args: unknown[]) => Promise<unknown>
  recover: (key: string) => Promise<unknown>
}

function office(id: number, role = 'admin', status = 'active') {
  return { id, name: `Escritório ${id}`, role, status }
}

function identity(account: ReturnType<typeof office> | null) {
  return { id: 5, name: 'Usuária', email: 'user@example.test', is_super_admin: false, accounts: account ? [account] : [], current_account: account, companies: [], current_company: null }
}

function textOf(node: TestNode): string {
  return `${node.text ?? ''}${node.children.map(textOf).join('')}`
}

function findAll(node: TestNode, predicate: (candidate: TestNode) => boolean): TestNode[] {
  return [...(predicate(node) ? [node] : []), ...node.children.flatMap(child => findAll(child, predicate))]
}

function findButton(root: TestNode, label: string): TestNode | undefined {
  return findAll(root, node => node.tag === 'button' && textOf(node).includes(label))[0]
}

function mountTree(component: Vue.Component, components: Record<string, Vue.Component>) {
  const root: TestNode = { props: {}, children: [] }
  const renderer = Vue.createRenderer({
    patchProp(node: TestNode, key, _previous, next) { node.props[key] = next },
    insert(node: TestNode, parent: TestNode, anchor?: TestNode) {
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
    },
    remove(node: TestNode) {
      if (!node.parent) return
      const index = node.parent.children.indexOf(node)
      if (index >= 0) node.parent.children.splice(index, 1)
    },
    createElement(tag) { return { tag, props: {}, children: [], parent: null } },
    createText(text) { return { text, props: {}, children: [], parent: null } },
    createComment(text) { return { text, props: {}, children: [], parent: null } },
    setText(node: TestNode, text) { node.text = text },
    setElementText(node: TestNode, text) {
      node.children = []
      node.text = text
    },
    parentNode(node: TestNode) { return node.parent ?? null },
    nextSibling(node: TestNode) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  const app = renderer.createApp(component)
  for (const [name, registered] of Object.entries(components)) app.component(name, registered)
  registerOfficeErpPresentationStubs(app)
  app.mount(root)
  return { app, root }
}

function uiComponents(): Record<string, Vue.Component> {
  return {
    UButton: {
      props: ['disabled', 'loading', 'to', 'label'],
      setup(props, context) {
        return () => Vue.h('button', { ...context.attrs, 'to': props.to, 'disabled': props.disabled, 'data-loading': String(props.loading ?? false) }, [
          context.slots.leading?.(),
          context.slots.default?.() ?? props.label,
          context.slots.trailing?.()
        ])
      }
    },
    UInput: {
      props: ['modelValue', 'disabled', 'loading', 'type'],
      emits: ['update:modelValue'],
      setup(props, context) {
        return () => Vue.h('input', {
          ...context.attrs,
          type: props.type ?? 'text',
          value: props.modelValue,
          disabled: props.disabled,
          onInput: (event: { target: { value: string } }) => context.emit('update:modelValue', event.target.value)
        })
      }
    },
    USelectMenu: {
      props: ['modelValue', 'items', 'disabled', 'loading', 'valueKey', 'labelKey'],
      emits: ['update:modelValue', 'update:searchTerm'],
      setup(props, context) {
        const itemValue = (item: Record<string, unknown>) => item.id ?? item[props.valueKey as string] ?? item.value
        const itemLabel = (item: Record<string, unknown>) => item.label ?? item[props.labelKey as string] ?? item.name
        return () => Vue.h('select', {
          ...context.attrs,
          value: props.modelValue && typeof props.modelValue === 'object'
            ? itemValue(props.modelValue as Record<string, unknown>) ?? ''
            : props.modelValue ?? '',
          disabled: props.disabled,
          onChange: (event: { target: { value: string } }) => {
            const item = (props.items as Array<Record<string, unknown>>).find(option => String(itemValue(option)) === event.target.value)
            context.emit('update:modelValue', item)
          }
        }, [Vue.h('option', { value: '' }, 'Selecione'), ...(props.items as Array<Record<string, unknown>>).map(item => Vue.h('option', { value: String(itemValue(item)) }, String(itemLabel(item) ?? '')))])
      }
    },
    UForm: {
      props: ['id', 'disabled'],
      setup(_props, context) {
        return () => Vue.h('form', { ...context.attrs, onSubmit: (event: { preventDefault(): void }) => {
          event.preventDefault()
          ;(context.attrs.onSubmit as (() => void) | undefined)?.()
        } }, context.slots.default?.())
      }
    },
    UFormField: {
      props: ['name'],
      setup(props, context) { return () => Vue.h('div', { 'data-field': props.name }, context.slots.default?.()) }
    },
    UAlert: {
      props: ['title', 'description', 'color', 'variant'],
      setup(props, context) { return () => Vue.h('aside', { 'data-color': props.color, 'data-variant': props.variant }, [Vue.h('strong', {}, props.title as string ?? ''), Vue.h('p', {}, context.slots.description?.() ?? props.description as string ?? '')]) }
    },
    UBadge: { setup(_props, context) { return () => Vue.h('span', {}, context.slots.default?.()) } },
    UTable: {
      props: ['data', 'columns', 'loading', 'expandedOptions'],
      setup(props, context) {
        const expandedRows = Vue.ref(new Set<number>())
        return () => {
          const data = props.data as Array<Record<string, unknown>>
          const columns = props.columns as Array<{ id?: string, accessorKey?: string, header?: unknown }>
          if (!data.length) return Vue.h('table', {}, context.slots.empty?.())
          const rows = data.map((original, index) => {
            const row = {
              original,
              index,
              getValue: (key: string) => original[key],
              getCanExpand: () => Boolean((props.expandedOptions as { getRowCanExpand?: (row: unknown) => boolean } | undefined)?.getRowCanExpand?.(row)),
              getIsExpanded: () => expandedRows.value.has(index),
              toggleExpanded: () => {
                const next = new Set(expandedRows.value)
                if (next.has(index)) next.delete(index)
                else next.add(index)
                expandedRows.value = next
              }
            }
            const cells = columns.map((column) => {
              const key = column.id ?? column.accessorKey ?? ''
              const cell = context.slots[`${key}-cell`]?.({ row })
              return Vue.h('td', {}, cell ?? String(original[key] ?? ''))
            })
            return Vue.h(Vue.Fragment, {}, [
              Vue.h('tr', {}, cells),
              ...(row.getIsExpanded() && context.slots.expanded ? [Vue.h('tr', {}, Vue.h('td', { colspan: columns.length }, context.slots.expanded({ row })))] : [])
            ])
          })
          return Vue.h('table', {}, [
            Vue.h('thead', {}, Vue.h('tr', {}, columns.map(column => Vue.h('th', {}, typeof column.header === 'string' ? column.header : '')))),
            Vue.h('tbody', {}, rows)
          ])
        }
      }
    },
    UPagination: {
      props: ['page', 'total', 'itemsPerPage', 'disabled'],
      emits: ['update:page'],
      setup(props, context) {
        return () => Vue.h('nav', {}, [
          Vue.h('span', {}, `Página ${props.page}`),
          Vue.h('button', {
            type: 'button',
            disabled: props.disabled || Number(props.page) <= 1,
            onClick: () => context.emit('update:page', Number(props.page) - 1)
          }, 'Anterior'),
          Vue.h('button', {
            type: 'button',
            disabled: props.disabled || Number(props.page) * Number(props.itemsPerPage) >= Number(props.total),
            onClick: () => context.emit('update:page', Number(props.page) + 1)
          }, 'Próxima')
        ])
      }
    },
    DataTablePanelList: { setup(_props, context) { return () => Vue.h('main', {}, [context.slots.action?.(), context.slots.toolbar?.(), context.slots.default?.()]) } }
  }
}

function createCommand(initialKey: string | null = null): InvitationCommand {
  const key = Vue.ref(initialKey)
  const busy = Vue.ref(false)
  const executeCalls: unknown[][] = []
  const recoverCalls: string[] = []
  const command: InvitationCommand = {
    pending: Vue.computed(() => busy.value || key.value !== null),
    recoveryKey: Vue.computed(() => key.value),
    executeCalls,
    recoverCalls,
    executeResults: [],
    recoveryResult: { status: 'stale' },
    restore(operationKey, result) {
      key.value = operationKey
      command.recoveryResult = result
    },
    async execute(...args) {
      executeCalls.push(args)
      busy.value = true
      const result = command.executeResults.shift() ?? { status: 'unknown', key: '7cbbdbb6-7f88-4479-a78a-471e4cb16c78' }
      if ((result as { status?: string }).status === 'unknown') key.value = (result as { key: string }).key
      else key.value = null
      busy.value = false
      return result
    },
    async recover(operationKey) {
      recoverCalls.push(operationKey)
      busy.value = true
      const result = command.recoveryResult
      if ((result as { status?: string }).status === 'confirmed') key.value = null
      busy.value = false
      return result
    }
  }
  return command
}

const testCapabilities = Vue.ref({
  can_read: true,
  can_maintain: true,
  can_configure: true,
  can_sell_new: true,
  can_provision: true,
  can_start_trial: false,
  can_manage_trial_link: true,
  capability_key: 'capability-1',
  reasons: {},
  allowance: { enabled: true, licence_limit: 4, module_keys: ['purchases'] },
  usage: { reserved: 1, occupied: 0 }
})

function testClients(query = '') {
  return {
    data: [{ id: 18, name: 'Mercado São José', trade_name: 'São José', tax_id: '12345678000199' }],
    meta: { current_page: 1, last_page: 2, per_page: 25, total: 27 },
    query
  }
}

function testOffers() {
  return {
    data: [
      { id: 5, name: 'ERP Varejo', description: 'Operação de loja', status: 'active', archived_at: null, current_revision: { id: 34, revision_number: 3, name: 'ERP Varejo', description: 'Operação de loja', price: '249.90', currency: 'BRL', interval_months: 3, due_in_days: 12, module_keys: ['purchases'], published_at: '2026-10-24T10:00:00Z', published: true } },
      { id: 6, name: 'Rascunho interno', description: null, status: 'active', archived_at: null, current_revision: { id: 35, revision_number: 1, name: 'Rascunho interno', description: null, price: '1.00', currency: 'BRL', interval_months: 1, due_in_days: 0, module_keys: [], published_at: null, published: false } }
    ],
    meta: { current_page: 1, last_page: 2, per_page: 25, total: 27 }
  }
}

function testPreview() {
  return { data: {
    client: { id: 18, name: 'Mercado São José', trade_name: 'São José', tax_id: '12345678000199' },
    seller: { account_id: 8, name: 'Escritório 8' },
    owner: { full_name: 'Ana Souza', email: 'ana@example.test' },
    offer: { sales_plan_id: 5, revision_id: 34, name: 'ERP Varejo', description: 'Operação de loja', price: '249.90', currency: 'BRL', interval_months: 3, due_in_days: 12, module_keys: ['purchases'], published: true },
    service: { start_mode: 'activation_date', billing_timezone: 'America/Sao_Paulo', due_in_days: 12 },
    capacity: { reserved: 1, occupied: 0, available: 3, can_issue: true, reason: null }
  } }
}

function harness(path: 'pages/gestao-erp/clientes/index.vue' | 'pages/gestao-erp/clientes/novo.vue', options: {
  api?: (path: string, init?: Record<string, unknown>) => Promise<unknown>
  command?: InvitationCommand
  initialRecovery?: unknown
  routeQuery?: Record<string, string | number>
  origin?: string
  lookupCnpj?: () => Promise<unknown>
  clipboardWrite?: (value: string) => Promise<void>
  officeContext?: {
    capabilities: Vue.ComputedRef<unknown>
    capture: () => { key: string, generation: number }
    isCurrent: (token: { key: string, generation: number }) => boolean
    refresh: () => Promise<void>
  }
} = {}) {
  const user = Vue.ref(identity(office(8)))
  const currentAccount = Vue.computed(() => user.value.current_account)
  const session = Vue.ref(1)
  const apiCalls: ApiCall[] = []
  const stateKeys: string[] = []
  const navigationStates = new Map<string, Vue.Ref<unknown>>()
  const command = options.command ?? createCommand()
  if (options.initialRecovery !== undefined) command.recoveryResult = options.initialRecovery
  const context = options.officeContext ?? {
    capabilities: testCapabilities,
    capture: () => ({ key: JSON.stringify([user.value.id, session.value, currentAccount.value?.id, testCapabilities.value.capability_key]), generation: session.value }),
    isCurrent: (token: { key: string, generation: number }) => token.key === JSON.stringify([user.value.id, session.value, currentAccount.value?.id, testCapabilities.value.capability_key]) && token.generation === session.value,
    refresh: async () => {}
  }
  const component = compileVueComponent(path, {
    refDebounced: (value: Vue.Ref<unknown>) => value,
    useAccessApi: () => ({ api: async (url: string, init?: Record<string, unknown>) => {
      apiCalls.push({ path: url, options: init })
      return options.api ? options.api(url, init) : url === '/erp-commercial/plans' ? testOffers() : url.includes('onboarding-preview') ? testPreview() : testClients(String((init?.params as Record<string, unknown> | undefined)?.q ?? ''))
    } }),
    useAuth: () => ({ user, currentAccount }),
    useState: (key: string, factory: () => unknown) => {
      stateKeys.push(key)
      if (key === 'access-session-generation') return session
      if (key === 'access-user') return user
      if (!navigationStates.has(key)) navigationStates.set(key, Vue.ref(factory()))
      return navigationStates.get(key)
    },
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => command,
    useClients: () => ({ lookupCnpj: options.lookupCnpj ?? (async () => { throw new Error('Consulta de CNPJ indisponível') }) }),
    useRoute: () => ({ query: options.routeQuery ?? {} }),
    window: { location: { origin: options.origin ?? 'http://localhost:3346' } },
    navigator: { clipboard: { writeText: options.clipboardWrite ?? (async () => {}) } },
    useToast: () => ({ add() {} }),
    onBeforeUnmount: Vue.onBeforeUnmount,
    definePageMeta() {}
  })
  const mounted = mountTree(component, uiComponents())
  return { ...mounted, user, session, apiCalls, stateKeys, command, context }
}

function realCommandFixture(storage = new Map<string, string>(), api: (path: string, init?: Record<string, unknown>) => Promise<unknown>) {
  const user = Vue.ref(identity(office(8)))
  const session = Vue.ref(1)
  const states = new Map<string, Vue.Ref<unknown>>()
  const calls: Array<{ path: string, init?: Record<string, unknown> }> = []
  const capabilities = {
    can_read: true,
    can_maintain: true,
    can_configure: true,
    can_sell_new: true,
    can_provision: true,
    can_start_trial: false,
    can_manage_trial_link: true,
    capability_reasons: { can_configure: [], can_sell_new: [], can_provision: [], can_start_trial: [], can_manage_trial_link: [] },
    capability_key: 'fresh-reload-capability',
    reasons: {},
    allowance: { enabled: true, licence_limit: 4, module_keys: ['purchases'] },
    usage: { reserved: 0, occupied: 0 }
  }
  const bindings = {
    ref: Vue.ref,
    computed: Vue.computed,
    watch: Vue.watch,
    readonly: Vue.readonly,
    onScopeDispose: Vue.onScopeDispose,
    useNuxtApp: () => ({}),
    useState: <T>(key: string, factory: () => T) => {
      if (key === 'access-session-generation') return session as Vue.Ref<T>
      if (!states.has(key)) states.set(key, Vue.ref(factory()))
      return states.get(key) as Vue.Ref<T>
    },
    useAuth: () => ({ user, currentAccount: Vue.computed(() => user.value.current_account) }),
    useAccessApi: () => ({
      api: async (path: string, init?: Record<string, unknown>) => {
        calls.push({ path, init })
        return api(path, init)
      },
      refreshCsrf: async () => {}
    }),
    crypto: { randomUUID: () => '7cbbdbb6-7f88-4479-a78a-471e4cb16c78' },
    sessionStorage: {
      getItem: (key: string) => storage.get(key) ?? null,
      setItem: (key: string, value: string) => { storage.set(key, value) },
      removeItem: (key: string) => { storage.delete(key) }
    }
  }
  const contextModule = loadAppComposable<Record<string, unknown>>('composables/useOfficeErpContext.ts', bindings)
  const commandModule = loadAppComposable<Record<string, unknown>>('composables/useOfficeErpCommand.ts', {
    ...bindings,
    useOfficeErpContext: contextModule.useOfficeErpContext
  })
  const scope = Vue.effectScope()
  const context = scope.run(() => (contextModule.useOfficeErpContext as () => {
    capabilities: Vue.ComputedRef<unknown>
    capture: () => { key: string, generation: number }
    isCurrent: (token: { key: string, generation: number }) => boolean
    refresh: () => Promise<void>
  })())!
  const command = scope.run(() => (commandModule.useOfficeErpCommand as () => InvitationCommand)())!
  return { command, context, capabilities, calls, storage, stop: () => scope.stop() }
}

function flush() {
  return Vue.nextTick().then(() => new Promise<void>(resolve => setImmediate(resolve))).then(() => Vue.nextTick())
}

function controlFor(root: TestNode, field: string): TestNode | undefined {
  const wrapper = findAll(root, node => node.props['data-field'] === field)[0]
  return wrapper ? findAll(wrapper, node => node.tag === 'input' || node.tag === 'select')[0] : undefined
}

function updateInput(root: TestNode, field: string, value: string) {
  const input = controlFor(root, field)
  assert.ok(input, `expected rendered input for ${field}`)
  ;(input.props.onInput as (event: { target: { value: string } }) => void)({ target: { value } })
}

function selectOption(root: TestNode, field: string, value: string) {
  const select = controlFor(root, field)
  assert.ok(select, `expected rendered select for ${field}`)
  ;(select.props.onChange as (event: { target: { value: string } }) => void)({ target: { value } })
}

function fillNewEnrollment(page: ReturnType<typeof harness>, name = 'Cliente de teste') {
  updateInput(page.root, 'new_client.tax_id', '27865757000102')
  updateInput(page.root, 'new_client.name', name)
  updateInput(page.root, 'new_client.email', 'cliente@example.test')
  updateInput(page.root, 'new_client.phone', '+5511999999999')
  selectOption(page.root, 'erp_plan_id', '7')
  updateInput(page.root, 'price', '100,00')
  updateInput(page.root, 'due_in_days', '7')
  updateInput(page.root, 'start_on', '2026-10-25')
}

describe('Office ERP seller onboarding SFC', () => {
  it('loads the own-office active portfolio with server search and counted pagination', async () => {
    const page = harness('pages/gestao-erp/clientes/index.vue')
    await flush()
    const search = controlFor(page.root, 'client-search')
    assert.ok(search)
    updateInput(page.root, 'client-search', '  São José  ')
    await flush()
    const call = page.apiCalls.filter(item => item.path === '/erp-commercial/clients').at(-1)
    assert.deepEqual(call?.options?.params, { q: 'São José', page: 1, per_page: 25 })
    assert.ok(findButton(page.root, 'Cadastrar cliente ERP'))
    page.app.unmount()
  })

  it('keeps client rows compact and exposes full details and actions when expanded', async () => {
    const page = harness('pages/gestao-erp/clientes/index.vue', {
      api: async path => path === '/erp-commercial/clients'
        ? {
            data: [
              { id: 18, name: 'Mercado São José', trade_name: 'São José', tax_id: '27865757000102', subscription: { contract_id: 42, agreement_status: 'effective', licence_status: 'active', erp_plan_id: 7, erp_plan: { id: 7, name: 'ERP Varejo' }, trial: null, next_billing_on: '2026-11-01' } },
              { id: 19, name: 'Oficina Norte', trade_name: null, tax_id: null, subscription: { contract_id: 43, agreement_status: 'effective', licence_status: 'active', erp_plan_id: 8, erp_plan: { id: 8, name: 'ERP Oficina' }, trial: { id: 2, started_at: '2026-10-20T12:00:00Z', ends_at: '2026-10-27T12:00:00Z', converted_at: null, expired_at: null, situation: 'trialing' }, next_billing_on: null } },
              { id: 20, name: 'Sem contrato', trade_name: null, tax_id: null, subscription: null },
              { id: 21, name: 'Resumo faltando', trade_name: null, tax_id: null },
              ...['active', 'suspended', 'canceled'].map((licence_status, index) => ({
                id: 22 + index,
                name: `Trial convertido ${licence_status}`,
                trade_name: null,
                tax_id: null,
                subscription: { contract_id: 50 + index, agreement_status: 'effective', licence_status, erp_plan_id: 9, erp_plan: { id: 9, name: 'ERP Convertido' }, trial: { id: 10 + index, started_at: '2026-10-20T12:00:00Z', ends_at: '2026-10-27T12:00:00Z', converted_at: '2026-10-27T12:00:00Z', expired_at: null, situation: 'converted' }, next_billing_on: '2026-12-01' }
              }))
            ],
            meta: { current_page: 1, last_page: 1, per_page: 25, total: 7 }
          }
        : testOffers()
    })
    await flush()
    const summary = textOf(page.root)
    assert.match(summary, /ERP Varejo/)
    assert.match(summary, /ERP Oficina/)
    assert.match(summary, /Em teste/)
    assert.match(summary, /Sem assinatura/)
    assert.match(summary, /Resumo indisponível/)
    assert.doesNotMatch(summary, /27865757000102/)
    assert.doesNotMatch(summary, /01\/11\/2026/)
    assert.doesNotMatch(summary, /Mercado São José/)
    const activationLink = findButton(page.root, 'Ativar ERP')
    assert.deepEqual(activationLink?.props.to, { path: '/gestao-erp/clientes/novo', query: { cliente: 20, pagina: 1 } })

    const expanders = findAll(page.root, node => node.tag === 'button' && node.props['aria-label'] === 'Ver detalhes do cliente')
    assert.equal(expanders.length, 7)
    for (const expander of expanders)
      (expander.props.onClick as (() => void) | undefined)?.()
    await flush()

    const converted = textOf(page.root)
    assert.match(converted, /27865757000102/)
    assert.match(converted, /01\/11\/2026/)
    assert.match(converted, /27\/10\/2026/)
    assert.match(converted, /Selecionar da carteira/)
    assert.ok((converted.match(/Assinatura ativa/g) ?? []).length >= 2)
    assert.match(converted, /Assinatura suspensa/)
    assert.match(converted, /Assinatura cancelada/)
    assert.equal((converted.match(/Próxima cobrança 01\/12\/2026/g) ?? []).length, 3)
    assert.equal((converted.match(/Período de teste convertido/g) ?? []).length, 3)
    const contractLink = findAll(page.root, node => node.tag === 'button' && textOf(node).includes('Abrir contrato'))[0]
    assert.deepEqual(contractLink?.props.to, '/gestao-erp/assinaturas/42')

    const selectedLinks = findAll(page.root, node => node.tag === 'button'
      && typeof node.props.to === 'object'
      && (node.props.to as { query?: { cliente?: number } }).query?.cliente === 18)
    assert.equal(selectedLinks.length, 1, 'the same expanded row owns selection on every viewport')
    assert.ok(findButton(page.root, 'Ativar ERP'))
    page.app.unmount()
  })

  it('releases trial-link busy state on authority change without letting the stale completion clear a new copy', async () => {
    const pending: Array<(value: unknown) => void> = []
    const copied: string[] = []
    const trialUrl = 'http://localhost:3346/erp/testar/550e8400-e29b-41d4-a716-446655440000'
    const page = harness('pages/gestao-erp/clientes/index.vue', {
      api: async path => path === '/erp-commercial/trial-link'
        ? new Promise(resolve => pending.push(resolve))
        : testClients(),
      clipboardWrite: async (value) => { copied.push(value) } })
    await flush()
    const copyButton = findButton(page.root, 'Copiar link de teste')
    assert.ok(copyButton)
    ;(copyButton.props.onClick as () => void)()
    await flush()
    assert.equal(findButton(page.root, 'Copiar link de teste')?.props['data-loading'], 'true')

    page.user.value = identity(office(9))
    await flush()
    assert.equal(findButton(page.root, 'Copiar link de teste')?.props['data-loading'], 'false')
    ;(findButton(page.root, 'Copiar link de teste')?.props.onClick as () => void)()
    await flush()
    assert.equal(pending.length, 2)
    assert.equal(findButton(page.root, 'Copiar link de teste')?.props['data-loading'], 'true')

    pending[0]!({ data: { configured: true, enabled: true, url: trialUrl } })
    await flush()
    assert.equal(findButton(page.root, 'Copiar link de teste')?.props['data-loading'], 'true')
    assert.deepEqual(copied, [])
    pending[1]!({ data: { configured: true, enabled: true, url: trialUrl } })
    await flush()
    assert.deepEqual(copied, [trialUrl])
    assert.equal(findButton(page.root, 'Copiar link de teste')?.props['data-loading'], 'false')
    page.app.unmount()
  })

  it('explains provision eligibility codes in Portuguese with a next step', async () => {
    const page = harness('pages/gestao-erp/clientes/novo.vue', {
      api: async path => path === '/erp-commercial/enrollment-plans'
        ? { data: [], eligibility: { can_provision: false, reasons: ['subscription_inactive', 'licence_capacity_reached', 'commercial_module_not_allowed'], licence_limit: 1, used_licences: 1, available_module_keys: [] } }
        : testClients()
    })
    await flush()
    const rendered = textOf(page.root)
    assert.match(rendered, /A assinatura do escritório está inativa/)
    assert.match(rendered, /licenças disponíveis/)
    assert.match(rendered, /módulos ERP necessários/)
    assert.match(rendered, /Próximo passo/)
    assert.doesNotMatch(rendered, /subscription_inactive|licence_capacity_reached|commercial_module_not_allowed/)
    page.app.unmount()
  })

  it('keeps a failed client read separate from an authoritative empty result', async () => {
    const page = harness('pages/gestao-erp/clientes/index.vue', { api: async () => {
      throw new Error('Carteira indisponível')
    } })
    await flush()
    assert.match(textOf(page.root), /Carteira indisponível/)
    assert.doesNotMatch(textOf(page.root), /Nenhum cliente cadastrado/)
    assert.ok(findButton(page.root, 'Tentar novamente'))
    page.app.unmount()
  })

  it('uses a subtle retry alert when the technical plan read fails', async () => {
    const page = harness('pages/gestao-erp/clientes/novo.vue', { api: async () => {
      throw new Error('Serviço temporariamente indisponível')
    } })
    await flush()
    const alerts = findAll(page.root, node => node.tag === 'aside' && node.props['data-color'] === 'error')
    assert.equal(alerts.length, 1)
    assert.match(textOf(alerts[0]!), /Serviço temporariamente indisponível/)
    for (const alert of alerts) {
      assert.equal(alert.props['data-variant'], 'subtle')
      assert.ok(findButton(alert, 'Tentar novamente'))
    }
    assert.doesNotMatch(textOf(page.root), /Nenhum plano ERP disponível/)
    page.app.unmount()
  })

  it('keeps previous-page navigation enabled on the final portfolio page', async () => {
    const page = harness('pages/gestao-erp/clientes/index.vue', { api: async () => ({
      data: [{ id: 77, name: 'Cliente da última página', trade_name: null, tax_id: null }],
      meta: { current_page: 2, last_page: 2, per_page: 25, total: 27 }
    }) })
    await flush()
    const previous = findButton(page.root, 'Anterior')
    assert.ok(previous)
    assert.equal(previous.props.disabled, false)
    page.app.unmount()
  })

  it('carries the searched portfolio page to the form and preselects its client', async () => {
    const page = harness('pages/gestao-erp/clientes/index.vue', { api: async (_path, init) => {
      const params = init?.params as Record<string, unknown>
      const pageNumber = Number(params.page)
      return {
        data: pageNumber === 2 ? [{ id: 77, name: 'Cliente da página dois', trade_name: null, tax_id: null }] : [],
        meta: { current_page: pageNumber, last_page: 2, per_page: 25, total: 27 }
      }
    } })
    await flush()
    updateInput(page.root, 'client-search', 'Caribe')
    await flush()
    ;(findButton(page.root, 'Próxima')?.props.onClick as () => void)()
    await flush()
    const activationLink = findButton(page.root, 'Ativar ERP')
    assert.ok(activationLink)
    const destination = activationLink.props.to as { query: Record<string, unknown> }
    assert.deepEqual(destination.query, { cliente: 77, pagina: 2, q: 'Caribe' })

    const form = harness('pages/gestao-erp/clientes/novo.vue', {
      routeQuery: destination.query as Record<string, string | number>,
      api: async (path, init) => {
        if (path === '/erp-commercial/enrollment-plans') return { data: [{ id: 7, slug: 'standard', name: 'ERP Padrão', module_keys: ['purchases'] }], eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 1, available_module_keys: ['purchases'] } }
        if (path !== '/erp-commercial/clients') return testPreview()
        const params = init?.params as Record<string, unknown>
        return {
          data: Number(params.page) === 2 ? [{ id: 77, name: 'Cliente da página dois', trade_name: null, tax_id: null }] : [],
          meta: { current_page: Number(params.page), last_page: 2, per_page: 25, total: 27 }
        }
      }
    })
    await flush()
    assert.deepEqual(form.apiCalls.find(call => call.path === '/erp-commercial/clients')?.options?.params, { page: 2, per_page: 25, q: 'Caribe' })
    assert.equal(controlFor(form.root, 'client_id')?.props.value, 77, textOf(form.root))
    page.app.unmount()
    form.app.unmount()
  })

  it('preserves a larger portfolio page when enrolling a client beyond the first 25 rows', async () => {
    const clients = harness('pages/gestao-erp/clientes/index.vue', {
      routeQuery: { pagina: 1, porPagina: 50 },
      api: async (_path, request) => ({ data: [{ id: 26, name: 'Cliente 26', trade_name: null, tax_id: null }], meta: { current_page: 1, last_page: 1, per_page: Number((request?.params as Record<string, unknown>).per_page), total: 26 } })
    })
    await flush()
    const destination = findButton(clients.root, 'Ativar ERP')?.props.to as { query: Record<string, string | number> }
    assert.equal(destination.query.porPagina, 50)
    const form = harness('pages/gestao-erp/clientes/novo.vue', {
      routeQuery: destination.query,
      api: async (path, request) => path === '/erp-commercial/clients'
        ? { data: Number((request?.params as Record<string, unknown>).per_page) === 50 ? [{ id: 26, name: 'Cliente 26', trade_name: null, tax_id: null }] : [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 26 } }
        : { data: [{ id: 7, slug: 'standard', name: 'ERP Padrão', module_keys: ['purchases'] }], eligibility: { can_provision: true, reasons: [], licence_limit: null, used_licences: 1, available_module_keys: ['purchases'] } }
    })
    await flush()
    assert.equal(controlFor(form.root, 'client_id')?.props.value, 26)
    clients.app.unmount()
    form.app.unmount()
  })

  it('keeps the direct portfolio preselection while the same authority resolves its initial ERP capabilities', async () => {
    const actual = realCommandFixture(new Map(), async (path) => {
      if (path === '/erp-commercial/context') return { data: actual.capabilities }
      return { data: {} }
    })
    const page = harness('pages/gestao-erp/clientes/novo.vue', {
      command: actual.command,
      officeContext: actual.context,
      routeQuery: { cliente: 18 },
      api: async path => path === '/erp-commercial/clients'
        ? testClients()
        : {
            data: [{ id: 7, slug: 'standard', name: 'ERP Padrão', module_keys: ['purchases'] }],
            eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 1, available_module_keys: ['purchases'] }
          }
    })
    try {
      assert.equal(actual.context.capabilities.value, null)
      await flush()
      assert.equal(controlFor(page.root, 'client_id')?.props.value, 18, textOf(page.root))
      assert.notEqual(actual.context.capabilities.value, null)
    } finally {
      page.app.unmount()
      actual.stop()
    }
  })

  it('does not reapply a route-selected client after the Account changes', async () => {
    const page = harness('pages/gestao-erp/clientes/novo.vue', {
      routeQuery: { cliente: 18 },
      api: async path => path === '/erp-commercial/enrollment-plans'
        ? { data: [{ id: 7, slug: 'standard', name: 'ERP Padrão', module_keys: ['purchases'] }], eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 1, available_module_keys: ['purchases'] } }
        : testClients()
    })
    await flush()
    assert.equal(controlFor(page.root, 'client_id')?.props.value, 18)
    page.user.value = identity(office(9))
    await flush()
    assert.equal(controlFor(page.root, 'client_id'), undefined)
    ;(findButton(page.root, 'Selecionar da carteira')?.props.onClick as () => void)()
    await flush()
    assert.equal(controlFor(page.root, 'client_id')?.props.value, '', 'the old deep-link client must not be selected under a new Account')
    page.app.unmount()
  })

  it('keeps the page and selected office blank of stale results after authority changes', async () => {
    let finish!: (value: unknown) => void
    const page = harness('pages/gestao-erp/clientes/novo.vue', { api: async (path) => {
      if (path === '/erp-commercial/context') return { data: testCapabilities.value }
      return new Promise((resolve) => {
        finish = resolve
      })
    } })
    await flush()
    page.user.value = identity(office(9))
    await flush()
    finish(testClients())
    await flush()
    assert.doesNotMatch(textOf(page.root), /Mercado São José/)
    page.app.unmount()
  })

  it('clears new-client lookup drafts and releases its busy state after authority changes', async () => {
    let finishLookup!: (value: unknown) => void
    const page = harness('pages/gestao-erp/clientes/novo.vue', {
      lookupCnpj: () => new Promise((resolve) => { finishLookup = resolve }),
      api: async path => path === '/erp-commercial/enrollment-plans'
        ? { data: [{ id: 7, slug: 'standard', name: 'ERP Padrão', module_keys: ['purchases'] }], eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 1, available_module_keys: ['purchases'] } }
        : testClients()
    })
    await flush()
    updateInput(page.root, 'new_client.tax_id', '12345678000199')
    await flush()
    updateInput(page.root, 'new_client.name', 'Nome protegido')
    await flush()
    updateInput(page.root, 'new_client.email', 'protegido@example.test')
    await flush()
    updateInput(page.root, 'new_client.phone', '11999999999')
    await flush()
    assert.equal(controlFor(page.root, 'new_client.tax_id')?.props.value, '12345678000199')
    const lookupButton = findButton(page.root, 'Consultar CNPJ')
    assert.ok(lookupButton, textOf(page.root))
    assert.equal(typeof lookupButton.props.onClick, 'function', JSON.stringify(lookupButton.props))
    ;(lookupButton.props.onClick as () => void)()
    await flush()
    assert.equal(findButton(page.root, 'Consultar CNPJ')?.props.disabled, true)
    page.user.value = identity(office(9))
    await flush()
    assert.equal(controlFor(page.root, 'new_client.tax_id')?.props.value, '')
    assert.equal(controlFor(page.root, 'new_client.name')?.props.value, '')
    assert.equal(controlFor(page.root, 'new_client.email')?.props.value, '')
    assert.equal(controlFor(page.root, 'new_client.phone')?.props.value, '')
    updateInput(page.root, 'new_client.tax_id', '12345678000199')
    await flush()
    assert.equal(findButton(page.root, 'Consultar CNPJ')?.props.disabled, false)
    finishLookup({ tax_id: '27865757000102', name: 'Resultado antigo', mei: false, simple_national: false, email: null, phone: null })
    await flush()
    assert.equal(controlFor(page.root, 'new_client.tax_id')?.props.value, '12345678000199')
    assert.equal(controlFor(page.root, 'new_client.name')?.props.value, '')
    assert.doesNotMatch(textOf(page.root), /Nome protegido|Resultado antigo/)
    page.app.unmount()
  })

  it('keeps the newest CNPJ lookup busy when an older same-authority lookup finishes', async () => {
    const lookups: Array<(value: unknown) => void> = []
    const page = harness('pages/gestao-erp/clientes/novo.vue', {
      lookupCnpj: () => new Promise(resolve => lookups.push(resolve)),
      api: async path => path === '/erp-commercial/enrollment-plans'
        ? { data: [{ id: 7, slug: 'standard', name: 'ERP Padrão', module_keys: ['purchases'] }], eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 1, available_module_keys: ['purchases'] } }
        : testClients()
    })
    await flush()
    updateInput(page.root, 'new_client.tax_id', '12345678000199')
    await flush()
    ;(findButton(page.root, 'Consultar CNPJ')?.props.onClick as () => void)()
    await flush()
    assert.equal(findButton(page.root, 'Consultar CNPJ')?.props.disabled, true)

    updateInput(page.root, 'new_client.tax_id', '27865757000102')
    await flush()
    assert.equal(findButton(page.root, 'Consultar CNPJ')?.props.disabled, false)
    ;(findButton(page.root, 'Consultar CNPJ')?.props.onClick as () => void)()
    await flush()
    assert.equal(lookups.length, 2)
    assert.equal(findButton(page.root, 'Consultar CNPJ')?.props.disabled, true)

    lookups[0]!({ tax_id: '12345678000199', name: 'Empresa antiga', mei: false, simple_national: false, email: null, phone: null })
    await flush()
    assert.equal(findButton(page.root, 'Consultar CNPJ')?.props.disabled, true)
    assert.equal(controlFor(page.root, 'new_client.tax_id')?.props.value, '27865757000102')
    lookups[1]!({ tax_id: '27865757000102', name: 'Empresa atual', mei: false, simple_national: false, email: null, phone: null })
    await flush()
    assert.equal(findButton(page.root, 'Consultar CNPJ')?.props.disabled, false)
    assert.equal(controlFor(page.root, 'new_client.name')?.props.value, 'Empresa atual')
    assert.doesNotMatch(textOf(page.root), /Empresa antiga/)
    page.app.unmount()
  })

  it('registers_without_published_offer', async () => {
    const command = createCommand()
    command.executeResults.push({ status: 'confirmed', data: {
      client_id: 18, company_id: 40, subscription_id: 41, contract_id: 42, invoice_id: 43, owner_setup_status: 'unclaimed'
    } })
    const page = harness('pages/gestao-erp/clientes/novo.vue', {
      command,
      lookupCnpj: async () => ({ tax_id: '12345678000199', name: 'Mercado São José', mei: false, simple_national: false, email: null, phone: null }),
      api: async (path, _init) => {
        if (path === '/erp-commercial/enrollment-plans') return {
          data: [{ id: 7, slug: 'standard', name: 'ERP Padrão', module_keys: ['purchases'] }],
          eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 1, available_module_keys: ['purchases'] }
        }
        if (path === '/erp-commercial/clients') return testClients()
        return { data: { owner_setup_status: 'unclaimed' } }
      }
    })
    await flush()
    updateInput(page.root, 'new_client.tax_id', '12345678000199')
    updateInput(page.root, 'new_client.name', 'Mercado São José')
    await flush()
    ;(findButton(page.root, 'Consultar CNPJ')?.props.onClick as () => void)()
    await flush()
    selectOption(page.root, 'erp_plan_id', '7')
    updateInput(page.root, 'price', '9.007.199.254.740.993,01')
    selectOption(page.root, 'interval_months', '1')
    updateInput(page.root, 'due_in_days', '7')
    updateInput(page.root, 'start_on', '2026-10-25')
    ;(findButton(page.root, 'Cadastrar cliente ERP')?.props.onClick as () => void)()
    await flush()
    const request = command.executeCalls[0]
    assert.equal(request?.[0], 'customer.enroll')
    assert.equal(request?.[1], '/erp-commercial/enrollments')
    const body = request?.[2] as Record<string, unknown>
    assert.equal(body.price, '9007199254740993.01')
    assert.equal('revision_id' in body, false)
    assert.equal('password' in body, false)
    assert.equal(body.currency, 'BRL')
    assert.equal(body.erp_plan_id, 7)
    assert.deepEqual(body.new_client, {
      tax_id: '12345678000199',
      tax_regime: 'presumed_profit',
      name: 'Mercado São José'
    })
    assert.match(textOf(page.root), /Assinatura cadastrada/)
    page.app.unmount()
  })

  it('allows correction after local validation and a definitive server 422', async () => {
    const command = createCommand()
    command.executeResults.push(
      { status: 'failed', failure: { code: 'validation', message: 'O preço foi recusado.', http_status: 422, errors: { price: ['Informe outro valor.'] } } },
      { status: 'confirmed', data: { client_id: 18, company_id: 40, subscription_id: 41, contract_id: 42, invoice_id: 43, owner_setup_status: 'unclaimed' } }
    )
    const page = harness('pages/gestao-erp/clientes/novo.vue', {
      command,
      api: async path => path === '/erp-commercial/enrollment-plans'
        ? { data: [{ id: 7, slug: 'standard', name: 'ERP Padrão', module_keys: ['purchases'] }], eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 1, available_module_keys: ['purchases'] } }
        : testClients()
    })
    await flush()
    fillNewEnrollment(page)
    updateInput(page.root, 'price', 'valor inválido')
    ;(findButton(page.root, 'Cadastrar cliente ERP')?.props.onClick as () => void)()
    await flush()
    assert.match(textOf(page.root), /Informe um valor em reais com até duas casas decimais/)
    updateInput(page.root, 'price', '100,00')
    await flush()
    ;(findButton(page.root, 'Cadastrar cliente ERP')?.props.onClick as () => void)()
    await flush()
    assert.equal(command.executeCalls.length, 1)
    assert.match(textOf(page.root), /O preço foi recusado/)
    updateInput(page.root, 'price', '110,00')
    await flush()
    assert.equal(findButton(page.root, 'Cadastrar cliente ERP')?.props.disabled, false)
    ;(findButton(page.root, 'Cadastrar cliente ERP')?.props.onClick as () => void)()
    await flush()
    assert.equal(command.executeCalls.length, 2)
    assert.match(textOf(page.root), /Assinatura cadastrada/)
    page.app.unmount()
  })

  it('releases an old enrollment busy state without letting its late result mask the new operation', async () => {
    const command = createCommand()
    const executions: Array<(result: unknown) => void> = []
    command.execute = async (...args) => {
      command.executeCalls.push(args)
      return new Promise(resolve => executions.push(resolve))
    }
    const page = harness('pages/gestao-erp/clientes/novo.vue', {
      command,
      api: async path => path === '/erp-commercial/enrollment-plans'
        ? { data: [{ id: 7, slug: 'standard', name: 'ERP Padrão', module_keys: ['purchases'] }], eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 1, available_module_keys: ['purchases'] } }
        : testClients()
    })
    await flush()
    fillNewEnrollment(page)
    ;(findButton(page.root, 'Cadastrar cliente ERP')?.props.onClick as () => void)()
    await flush()
    assert.equal(findButton(page.root, 'Cadastrar cliente ERP')?.props.disabled, true)
    page.user.value = identity(office(9))
    await flush()
    fillNewEnrollment(page, 'Novo escritório')
    await flush()
    assert.equal(findButton(page.root, 'Cadastrar cliente ERP')?.props.disabled, false)
    ;(findButton(page.root, 'Cadastrar cliente ERP')?.props.onClick as () => void)()
    await flush()
    assert.equal(executions.length, 2)
    executions[0]!({ status: 'confirmed', data: { client_id: 18, company_id: 40, subscription_id: 41, contract_id: 42, invoice_id: 43, owner_setup_status: 'unclaimed' } })
    await flush()
    assert.equal(findButton(page.root, 'Cadastrar cliente ERP')?.props.disabled, true)
    assert.doesNotMatch(textOf(page.root), /Assinatura cadastrada/)
    executions[1]!({ status: 'confirmed', data: { client_id: 28, company_id: 50, subscription_id: 51, contract_id: 52, invoice_id: 53, owner_setup_status: 'unclaimed' } })
    await flush()
    assert.match(textOf(page.root), /Assinatura cadastrada/)
    assert.match(textOf(page.root), /52/)
    page.app.unmount()
  })

  it('restores the recovery action when the command key arrives after the SFC mounts', async () => {
    const command = createCommand()
    const page = harness('pages/gestao-erp/clientes/novo.vue', { command })
    await flush()
    command.restore('7cbbdbb6-7f88-4479-a78a-471e4cb16c78', { status: 'confirmed', data: {
      client_id: 18, company_id: 40, subscription_id: 41, contract_id: 42, invoice_id: 43, owner_setup_status: 'unclaimed'
    } })
    await flush()
    assert.ok(findButton(page.root, 'Verificar resultado'))
    ;(findButton(page.root, 'Verificar resultado')?.props.onClick as () => void)()
    await flush()
    assert.deepEqual(command.recoverCalls, ['7cbbdbb6-7f88-4479-a78a-471e4cb16c78'])
    assert.equal(command.executeCalls.length, 0)
    assert.match(textOf(page.root), /Assinatura cadastrada/)
    page.app.unmount()
  })

  it('restores a real command after fresh reload and recovers from the rendered SFC without another enrollment POST', async () => {
    const storage = new Map<string, string>()
    const outcome = { client_id: 18, company_id: 40, subscription_id: 41, contract_id: 42, invoice_id: 43, owner_setup_status: 'unclaimed' }
    const first = realCommandFixture(storage, async (path) => {
      if (path === '/erp-commercial/context') return { data: first.capabilities }
      throw new Error('connection dropped after dispatch')
    })
    const unknown = await first.command.execute('customer.enroll', '/erp-commercial/enrollments', { new_client: { tax_id: '12345678000199' }, price: '100.00' })
    assert.deepEqual(unknown, { status: 'unknown', key: '7cbbdbb6-7f88-4479-a78a-471e4cb16c78' })
    assert.equal(first.calls.filter(call => call.path === '/erp-commercial/enrollments').length, 1)
    first.stop()

    const reloaded = realCommandFixture(storage, async (path) => {
      if (path === '/erp-commercial/context') return { data: reloaded.capabilities }
      if (path === '/erp-commercial/operations/7cbbdbb6-7f88-4479-a78a-471e4cb16c78') return { data: outcome }
      throw new Error(`Unexpected recovered request: ${path}`)
    })
    const page = harness('pages/gestao-erp/clientes/novo.vue', {
      command: reloaded.command,
      api: async path => path === '/erp-commercial/enrollment-plans'
        ? { data: [{ id: 7, slug: 'standard', name: 'ERP Padrão', module_keys: ['purchases'] }], eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 1, available_module_keys: ['purchases'] } }
        : testClients()
    })
    try {
      await flush()
      assert.equal(findButton(page.root, 'Verificar resultado'), undefined)
      await reloaded.context.refresh()
      await flush()
      assert.ok(findButton(page.root, 'Verificar resultado'))
      ;(findButton(page.root, 'Verificar resultado')?.props.onClick as () => void)()
      await flush()
      assert.match(textOf(page.root), /Assinatura cadastrada/)
      assert.equal(reloaded.calls.filter(call => call.path === '/erp-commercial/enrollments').length, 0)
      assert.equal(reloaded.calls.filter(call => call.path === '/erp-commercial/operations/7cbbdbb6-7f88-4479-a78a-471e4cb16c78').length, 1)
    } finally {
      page.app.unmount()
      reloaded.stop()
    }
  })

  it('selects_portfolio_client_without_duplicate', async () => {
    const command = createCommand()
    command.executeResults.push({ status: 'confirmed', data: {
      client_id: 18, company_id: 40, subscription_id: 41, contract_id: 42, invoice_id: 43, owner_setup_status: 'claimed'
    } })
    const page = harness('pages/gestao-erp/clientes/novo.vue', {
      command,
      api: async path => path === '/erp-commercial/enrollment-plans'
        ? { data: [{ id: 7, slug: 'standard', name: 'ERP Padrão', module_keys: ['purchases'] }], eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 1, available_module_keys: ['purchases'] } }
        : testClients()
    })
    await flush()
    ;(findButton(page.root, 'Selecionar da carteira')?.props.onClick as () => void)()
    await flush()
    selectOption(page.root, 'client_id', '18')
    await flush()
    selectOption(page.root, 'erp_plan_id', '7')
    updateInput(page.root, 'price', '100,00')
    selectOption(page.root, 'interval_months', '1')
    updateInput(page.root, 'due_in_days', '7')
    updateInput(page.root, 'start_on', '2026-10-25')
    ;(findButton(page.root, 'Cadastrar cliente ERP')?.props.onClick as () => void)()
    await flush()
    const body = command.executeCalls[0]?.[2] as Record<string, unknown>
    assert.equal(body.client_id, 18)
    assert.equal('new_client' in body, false)
    assert.equal('password' in body, false)
    page.app.unmount()
  })

  it('recovers_enrollment_without_second_invoice', async () => {
    const command = createCommand()
    command.executeResults.push({ status: 'unknown', key: '7cbbdbb6-7f88-4479-a78a-471e4cb16c78' })
    command.recoveryResult = { status: 'confirmed', data: { data: {
      client_id: 18, company_id: 40, subscription_id: 41, contract_id: 42, invoice_id: 43, owner_setup_status: 'unclaimed'
    } } }
    const page = harness('pages/gestao-erp/clientes/novo.vue', {
      command,
      api: async path => path === '/erp-commercial/enrollment-plans'
        ? { data: [{ id: 7, slug: 'standard', name: 'ERP Padrão', module_keys: ['purchases'] }], eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 1, available_module_keys: ['purchases'] } }
        : testClients()
    })
    await flush()
    ;(findButton(page.root, 'Selecionar da carteira')?.props.onClick as () => void)()
    await flush()
    selectOption(page.root, 'client_id', '18')
    await flush()
    selectOption(page.root, 'erp_plan_id', '7')
    updateInput(page.root, 'price', '100,00')
    selectOption(page.root, 'interval_months', '1')
    updateInput(page.root, 'due_in_days', '7')
    updateInput(page.root, 'start_on', '2026-10-25')
    ;(findButton(page.root, 'Cadastrar cliente ERP')?.props.onClick as () => void)()
    await flush()
    ;(findButton(page.root, 'Verificar resultado')?.props.onClick as () => void)()
    await flush()
    assert.equal(command.executeCalls.length, 1)
    assert.deepEqual(command.recoverCalls, ['7cbbdbb6-7f88-4479-a78a-471e4cb16c78'])
    assert.match(textOf(page.root), /Assinatura cadastrada/)
    page.app.unmount()
  })

  it('preserves_inputs_on_lookup_failure', async () => {
    const page = harness('pages/gestao-erp/clientes/novo.vue', {
      lookupCnpj: async () => { throw new Error('Receita indisponível') },
      api: async path => path === '/erp-commercial/enrollment-plans'
        ? { data: [{ id: 7, slug: 'standard', name: 'ERP Padrão', module_keys: ['purchases'] }], eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 1, available_module_keys: ['purchases'] } }
        : testClients()
    })
    await flush()
    updateInput(page.root, 'new_client.tax_id', '12345678000199')
    updateInput(page.root, 'new_client.name', 'Nome digitado para revisão')
    await flush()
    ;(findButton(page.root, 'Consultar CNPJ')?.props.onClick as () => void)()
    await flush()
    assert.equal(controlFor(page.root, 'new_client.tax_id')?.props.value, '12345678000199')
    assert.equal(controlFor(page.root, 'new_client.name')?.props.value, 'Nome digitado para revisão')
    assert.match(textOf(page.root), /Receita indisponível/)
    page.app.unmount()
  })

  it('clears_protected_customer_after_authority_change', async () => {
    let selectedAccount = 8
    const page = harness('pages/gestao-erp/clientes/novo.vue', {
      api: async path => path === '/erp-commercial/enrollment-plans'
        ? { data: [{ id: 7, slug: 'standard', name: 'ERP Padrão', module_keys: ['purchases'] }], eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 1, available_module_keys: ['purchases'] } }
        : selectedAccount === 9 ? { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } : testClients()
    })
    await flush()
    ;(findButton(page.root, 'Selecionar da carteira')?.props.onClick as () => void)()
    await flush()
    selectOption(page.root, 'client_id', '18')
    await flush()
    assert.match(textOf(page.root), /São José/)
    selectedAccount = 9
    page.user.value = identity(office(9))
    await flush()
    assert.doesNotMatch(textOf(page.root), /Mercado São José/)
    page.app.unmount()
  })

  it('prepares_optional_owner_access_after_confirmed_enrollment', async () => {
    const command = createCommand()
    command.executeResults.push(
      { status: 'confirmed', data: { client_id: 18, company_id: 40, subscription_id: 41, contract_id: 42, invoice_id: 43, owner_setup_status: 'unclaimed' } },
      { status: 'confirmed', data: { invitation_id: 77, contract_id: 42, company_id: 40, owner_setup_status: 'unclaimed', expires_at: '2026-11-01T12:00:00Z', status: 'pending', url: `http://localhost:3346/erp/ativar#token=${'a'.repeat(64)}` } }
    )
    const page = harness('pages/gestao-erp/clientes/novo.vue', {
      command,
      api: async path => path === '/erp-commercial/enrollment-plans'
        ? { data: [{ id: 7, slug: 'standard', name: 'ERP Padrão', module_keys: ['purchases'] }], eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 1, available_module_keys: ['purchases'] } }
        : testClients()
    })
    await flush()
    ;(findButton(page.root, 'Selecionar da carteira')?.props.onClick as () => void)()
    await flush()
    selectOption(page.root, 'client_id', '18')
    await flush()
    selectOption(page.root, 'erp_plan_id', '7')
    updateInput(page.root, 'price', '100,00')
    updateInput(page.root, 'due_in_days', '7')
    updateInput(page.root, 'start_on', '2026-10-25')
    ;(findButton(page.root, 'Cadastrar cliente ERP')?.props.onClick as () => void)()
    await flush()
    updateInput(page.root, 'owner.full_name', 'Ana Souza')
    updateInput(page.root, 'owner.email', 'ana@example.test')
    await flush()
    ;(findButton(page.root, 'Preparar acesso do responsável')?.props.onClick as () => void)()
    await flush()
    assert.equal(command.executeCalls.length, 2)
    assert.equal(command.executeCalls[1]?.[0], 'owner.setup.issue')
    assert.equal(command.executeCalls[1]?.[1], '/erp-commercial/contracts/42/owner-setup')
    assert.deepEqual(command.executeCalls[1]?.[2], { owner: { full_name: 'Ana Souza', email: 'ana@example.test' } })
    assert.equal('password' in (command.executeCalls[1]?.[2] as Record<string, unknown>), false)
    assert.match(textOf(page.root), /Acesso preparado/)
    assert.equal(findAll(page.root, node => node.tag === 'a')[0]?.props.href, `http://localhost:3346/erp/ativar#token=${'a'.repeat(64)}`)
    page.app.unmount()
  })

  it('clears owner setup drafts and busy state without accepting a stale owner result', async () => {
    const command = createCommand()
    const ownerExecutions: Array<(result: unknown) => void> = []
    let enrollments = 0
    command.execute = async (...args) => {
      command.executeCalls.push(args)
      if (args[0] === 'customer.enroll') {
        enrollments++
        const offset = enrollments === 1 ? 0 : 10
        return { status: 'confirmed', data: { client_id: 18 + offset, company_id: 40 + offset, subscription_id: 41 + offset, contract_id: 42 + offset, invoice_id: 43 + offset, owner_setup_status: 'unclaimed' } }
      }
      return new Promise(resolve => ownerExecutions.push(resolve))
    }
    const page = harness('pages/gestao-erp/clientes/novo.vue', {
      command,
      api: async path => path === '/erp-commercial/enrollment-plans'
        ? { data: [{ id: 7, slug: 'standard', name: 'ERP Padrão', module_keys: ['purchases'] }], eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 1, available_module_keys: ['purchases'] } }
        : testClients()
    })
    await flush()
    fillNewEnrollment(page, 'Cliente antigo')
    ;(findButton(page.root, 'Cadastrar cliente ERP')?.props.onClick as () => void)()
    await flush()
    updateInput(page.root, 'owner.full_name', 'Responsável antigo')
    updateInput(page.root, 'owner.email', 'antigo@example.test')
    ;(findButton(page.root, 'Preparar acesso do responsável')?.props.onClick as () => void)()
    await flush()
    assert.equal(findButton(page.root, 'Preparar acesso do responsável')?.props['data-loading'], 'true')

    page.user.value = identity(office(9))
    await flush()
    assert.equal(controlFor(page.root, 'new_client.tax_id')?.props.value, '')
    assert.equal(controlFor(page.root, 'new_client.name')?.props.value, '')
    assert.equal(controlFor(page.root, 'new_client.email')?.props.value, '')
    assert.equal(controlFor(page.root, 'new_client.phone')?.props.value, '')
    assert.equal(controlFor(page.root, 'price')?.props.value, '')
    assert.doesNotMatch(textOf(page.root), /Cliente antigo|Responsável antigo|antigo@example\.test|Acesso preparado/)

    fillNewEnrollment(page, 'Cliente novo')
    ;(findButton(page.root, 'Cadastrar cliente ERP')?.props.onClick as () => void)()
    await flush()
    assert.equal(controlFor(page.root, 'owner.full_name')?.props.value, '')
    assert.equal(controlFor(page.root, 'owner.email')?.props.value, '')
    assert.equal(findButton(page.root, 'Preparar acesso do responsável')?.props['data-loading'], 'false')
    updateInput(page.root, 'owner.full_name', 'Responsável novo')
    updateInput(page.root, 'owner.email', 'novo@example.test')
    ;(findButton(page.root, 'Preparar acesso do responsável')?.props.onClick as () => void)()
    await flush()
    assert.equal(ownerExecutions.length, 2)
    ownerExecutions[0]!({ status: 'confirmed', data: { invitation_id: 77, contract_id: 42, company_id: 40, owner_setup_status: 'unclaimed', expires_at: '2026-11-01T12:00:00Z', status: 'pending', url: `http://localhost:3346/erp/ativar#token=${'a'.repeat(64)}` } })
    await flush()
    assert.equal(findButton(page.root, 'Preparar acesso do responsável')?.props['data-loading'], 'true')
    assert.equal(controlFor(page.root, 'owner.full_name')?.props.value, 'Responsável novo')
    assert.doesNotMatch(textOf(page.root), /Responsável antigo|antigo@example\.test/)
    ownerExecutions[1]!({ status: 'confirmed', data: { invitation_id: 78, contract_id: 52, company_id: 50, owner_setup_status: 'unclaimed', expires_at: '2026-11-02T12:00:00Z', status: 'pending', url: `http://localhost:3346/erp/ativar#token=${'b'.repeat(64)}` } })
    await flush()
    assert.equal(findAll(page.root, node => node.tag === 'a').at(-1)?.props.href, `http://localhost:3346/erp/ativar#token=${'b'.repeat(64)}`)
    page.app.unmount()
  })

  it('recovers_owner_setup_without_issuing_a_second_access_link', async () => {
    const command = createCommand()
    command.executeResults.push(
      { status: 'confirmed', data: { client_id: 18, company_id: 40, subscription_id: 41, contract_id: 42, invoice_id: 43, owner_setup_status: 'unclaimed' } },
      { status: 'unknown', key: '9a1bdbb6-7f88-4479-a78a-471e4cb16c78' }
    )
    command.recoveryResult = { status: 'confirmed', data: {
      invitation_id: 77, contract_id: 42, company_id: 40, owner_setup_status: 'unclaimed', expires_at: '2026-11-01T12:00:00Z', status: 'pending'
    } }
    const page = harness('pages/gestao-erp/clientes/novo.vue', {
      command,
      api: async path => path === '/erp-commercial/enrollment-plans'
        ? { data: [{ id: 7, slug: 'standard', name: 'ERP Padrão', module_keys: ['purchases'] }], eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 1, available_module_keys: ['purchases'] } }
        : testClients()
    })
    await flush()
    ;(findButton(page.root, 'Selecionar da carteira')?.props.onClick as () => void)()
    await flush()
    selectOption(page.root, 'client_id', '18')
    await flush()
    selectOption(page.root, 'erp_plan_id', '7')
    updateInput(page.root, 'price', '100,00')
    updateInput(page.root, 'due_in_days', '7')
    updateInput(page.root, 'start_on', '2026-10-25')
    ;(findButton(page.root, 'Cadastrar cliente ERP')?.props.onClick as () => void)()
    await flush()
    updateInput(page.root, 'owner.full_name', 'Ana Souza')
    updateInput(page.root, 'owner.email', 'ana@example.test')
    await flush()
    ;(findButton(page.root, 'Preparar acesso do responsável')?.props.onClick as () => void)()
    await flush()
    ;(findButton(page.root, 'Verificar resultado')?.props.onClick as () => void)()
    await flush()
    assert.equal(command.executeCalls.length, 2)
    assert.deepEqual(command.recoverCalls, ['9a1bdbb6-7f88-4479-a78a-471e4cb16c78'])
    assert.match(textOf(page.root), /resultado foi confirmado sem recuperar o link único/)
    assert.ok(findButton(page.root, 'Emitir novo link de acesso'))
    assert.equal(findAll(page.root, node => node.tag === 'a' && textOf(node).includes('Abrir link de acesso')).length, 0)
    page.app.unmount()
  })
})

describe('Office ERP seller navigation', () => {
  it('links the overview and every shipped ERP page while marking the portfolio client active', () => {
    assert.deepEqual(officeErpSidebarItem('/gestao-erp/clientes').children, [
      { label: 'Visão geral', to: '/gestao-erp', exact: true, active: false },
      { label: 'Planos', to: '/gestao-erp/planos', exact: true, active: false },
      { label: 'Clientes ERP', to: '/gestao-erp/clientes', exact: true, active: true },
      { label: 'Assinaturas', to: '/gestao-erp/assinaturas', exact: false, active: false },
      { label: 'Faturas', to: '/gestao-erp/faturas', exact: false, active: false },
      { label: 'Recebimentos', to: '/gestao-erp/recebimentos', exact: true, active: false },
      { label: 'Configurações', to: '/gestao-erp/configuracoes', exact: true, active: false }
    ])
  })
})
