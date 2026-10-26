import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
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
  app.mount(root)
  return { app, root }
}

function uiComponents(): Record<string, Vue.Component> {
  return {
    UButton: {
      props: ['disabled', 'loading', 'to', 'label'],
      setup(props, context) {
        return () => Vue.h('button', { ...context.attrs, 'to': props.to, 'disabled': props.disabled, 'data-loading': String(props.loading ?? false) }, context.slots.default?.() ?? props.label)
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
      props: ['data', 'loading'],
      setup(props, context) {
        return () => (props.data as Array<Record<string, unknown>>).length
          ? Vue.h('table', {}, (props.data as Array<Record<string, unknown>>).map(row => Vue.h('tr', {}, [Vue.h('td', {}, String(row.name ?? '')), context.slots['actions-cell']?.({ row: { original: row } })])))
          : Vue.h('table', {}, context.slots.empty?.())
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
} = {}) {
  const user = Vue.ref(identity(office(8)))
  const currentAccount = Vue.computed(() => user.value.current_account)
  const session = Vue.ref(1)
  const apiCalls: ApiCall[] = []
  const stateKeys: string[] = []
  const command = options.command ?? createCommand()
  if (options.initialRecovery !== undefined) command.recoveryResult = options.initialRecovery
  const context = {
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
      return key === 'access-session-generation' ? session : factory()
    },
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => command,
    useClients: () => ({ lookupCnpj: options.lookupCnpj ?? (async () => { throw new Error('Consulta de CNPJ indisponível') }) }),
    useRoute: () => ({ query: options.routeQuery ?? {} }),
    window: { location: { origin: options.origin ?? 'http://localhost:3346' } },
    useToast: () => ({ add() {} }),
    onBeforeUnmount: Vue.onBeforeUnmount,
    definePageMeta() {}
  })
  const mounted = mountTree(component, uiComponents())
  return { ...mounted, user, session, apiCalls, stateKeys, command, context }
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
    const invitationLink = findButton(page.root, 'Selecionar da carteira')
    assert.ok(invitationLink)
    const destination = invitationLink.props.to as { query: Record<string, unknown> }
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
      { label: 'Clientes ERP', to: '/gestao-erp/clientes', exact: true, active: true },
      { label: 'Assinaturas', to: '/gestao-erp/assinaturas', exact: false, active: false },
      { label: 'Faturas', to: '/gestao-erp/faturas', exact: false, active: false },
      { label: 'Recebimentos', to: '/gestao-erp/recebimentos', exact: true, active: false },
      { label: 'Configurações', to: '/gestao-erp/configuracoes', exact: true, active: false }
    ])
  })
})
