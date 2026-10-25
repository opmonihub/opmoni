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
      props: ['modelValue', 'items', 'disabled', 'loading'],
      emits: ['update:modelValue', 'update:searchTerm'],
      setup(props, context) {
        return () => Vue.h('select', {
          ...context.attrs,
          value: (props.modelValue as { id?: number } | null)?.id ?? '',
          disabled: props.disabled,
          onChange: (event: { target: { value: string } }) => {
            const item = (props.items as Array<{ id: number }>).find(option => String(option.id) === event.target.value)
            context.emit('update:modelValue', item)
          }
        }, [Vue.h('option', { value: '' }, 'Selecione'), ...(props.items as Array<{ id: number, label: string }>).map(item => Vue.h('option', { value: String(item.id) }, item.label))])
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
      props: ['title', 'description'],
      setup(props, context) { return () => Vue.h('aside', {}, [Vue.h('strong', {}, props.title as string ?? ''), Vue.h('p', {}, context.slots.description?.() ?? props.description as string ?? '')]) }
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
    assert.ok(findButton(page.root, 'Novo convite'))
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
    const invitationLink = findButton(page.root, 'Convidar responsável')
    assert.ok(invitationLink)
    const destination = invitationLink.props.to as { query: Record<string, unknown> }
    assert.deepEqual(destination.query, { cliente: 77, pagina: 2, q: 'Caribe' })

    const form = harness('pages/gestao-erp/clientes/novo.vue', {
      routeQuery: destination.query as Record<string, string | number>,
      api: async (path, init) => {
        if (path === '/erp-commercial/plans') return testOffers()
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
    assert.equal(controlFor(form.root, 'client_id')?.props.value, 77)
    page.app.unmount()
    form.app.unmount()
  })

  it('retains the selected immutable offer revision while searching another offer page', async () => {
    const page = harness('pages/gestao-erp/clientes/novo.vue', { api: async (path, init) => {
      if (path === '/erp-commercial/plans' && (init?.params as Record<string, unknown> | undefined)?.q === 'Serviços') {
        return {
          data: [{ id: 9, name: 'ERP Serviços', description: null, status: 'active', archived_at: null, current_revision: { id: 99, revision_number: 2, name: 'ERP Serviços', description: null, price: '99.00', currency: 'BRL', interval_months: 1, due_in_days: 7, module_keys: [], published_at: '2026-10-24T10:00:00Z', published: true } }],
          meta: { current_page: 1, last_page: 2, per_page: 25, total: 27 }
        }
      }
      return path === '/erp-commercial/plans' ? testOffers() : testClients()
    } })
    await flush()
    selectOption(page.root, 'revision_id', '34')
    updateInput(page.root, 'offer-search', 'Serviços')
    await flush()
    assert.equal(controlFor(page.root, 'revision_id')?.props.value, 34)
    const latestOfferRequest = page.apiCalls.filter(call => call.path === '/erp-commercial/plans').at(-1)
    assert.equal((latestOfferRequest?.options?.params as Record<string, unknown>)?.q, 'Serviços')
    page.app.unmount()
  })

  it('reviews the selected published revision, preserves owner fields through unknown recovery, and requires explicit reissue for a new link', async () => {
    const command = createCommand()
    command.executeResults.push(
      { status: 'unknown', key: '7cbbdbb6-7f88-4479-a78a-471e4cb16c78' },
      { status: 'confirmed', data: { invitation_id: 92, expires_at: '2026-11-01 12:00:00', status: 'pending', url: `http://localhost:3346/erp/ativar#token=${'a'.repeat(64)}` } },
      { status: 'confirmed', data: { invitation_id: 93, expires_at: '2026-11-01 12:00:00', status: 'pending', url: `https://attacker.test/erp/ativar#token=${'b'.repeat(64)}` } }
    )
    command.recoveryResult = { status: 'confirmed', data: { data: { invitation_id: 91, expires_at: '2026-11-01 12:00:00', status: 'pending' } } }
    const page = harness('pages/gestao-erp/clientes/novo.vue', { command })
    await flush()

    selectOption(page.root, 'client_id', '18')
    selectOption(page.root, 'revision_id', '34')
    updateInput(page.root, 'owner.full_name', 'Ana Souza')
    updateInput(page.root, 'owner.email', 'ana@example.test')
    await flush()
    ;(findButton(page.root, 'Revisar termos')?.props.onClick as () => void)()
    await flush()

    const previewCall = page.apiCalls.find(item => item.path === '/erp-commercial/clients/18/onboarding-preview')
    assert.deepEqual(previewCall?.options?.body, { revision_id: 34, owner: { full_name: 'Ana Souza', email: 'ana@example.test' } })
    assert.match(textOf(page.root), /R\$\s?249,90/)
    assert.match(textOf(page.root), /A cada 3 meses/)
    assert.match(textOf(page.root), /12 dias/)
    assert.match(textOf(page.root), /Escritório 8/)
    assert.match(textOf(page.root), /Ana Souza/)
    assert.doesNotMatch(textOf(page.root), /Rascunho interno/)

    ;(findButton(page.root, 'Enviar convite')?.props.onClick as () => void)()
    await flush()
    assert.equal(command.executeCalls.length, 1)
    assert.equal(command.executeCalls[0]?.[0], 'invitation.issue')
    assert.equal(command.executeCalls[0]?.[1], '/erp-commercial/clients/18/invitations')
    assert.deepEqual(command.executeCalls[0]?.[2], { revision_id: 34, owner: { full_name: 'Ana Souza', email: 'ana@example.test' } })
    assert.match(textOf(page.root), /Verificar resultado/)
    assert.doesNotMatch(textOf(page.root), /one-time-secret/)

    ;(findButton(page.root, 'Verificar resultado')?.props.onClick as () => void)()
    await flush()
    assert.deepEqual(command.recoverCalls, ['7cbbdbb6-7f88-4479-a78a-471e4cb16c78'])
    assert.equal(command.executeCalls.length, 1)
    assert.match(textOf(page.root), /Convite emitido/)
    assert.doesNotMatch(textOf(page.root), /one-time-secret/)
    assert.ok(findButton(page.root, 'Gerar novo link'))
    assert.equal(controlFor(page.root, 'owner.full_name')?.props.value, 'Ana Souza')
    assert.equal(controlFor(page.root, 'owner.email')?.props.value, 'ana@example.test')

    ;(findButton(page.root, 'Gerar novo link')?.props.onClick as () => void)()
    await flush()
    assert.equal(command.executeCalls.length, 2)
    assert.equal(command.executeCalls[1]?.[0], 'invitation.issue')
    assert.equal(command.executeCalls[1]?.[1], '/erp-commercial/invitations/91/reissue')
    const activationLink = findAll(page.root, node => node.tag === 'a')[0]
    assert.equal(activationLink?.props.href, `http://localhost:3346/erp/ativar#token=${'a'.repeat(64)}`)
    assert.equal(page.stateKeys.includes('office-erp-invitation-token'), false)
    ;(findButton(page.root, 'Gerar novo link')?.props.onClick as () => void)()
    await flush()
    assert.doesNotMatch(textOf(page.root), /attacker\.test|token=b{64}/)
    page.app.unmount()
    assert.doesNotMatch(textOf(page.root), /one-time-secret/)
  })

  it('invalidates the review when owner fields change and does not send a stale issue', async () => {
    const command = createCommand()
    const page = harness('pages/gestao-erp/clientes/novo.vue', { command })
    await flush()
    selectOption(page.root, 'client_id', '18')
    selectOption(page.root, 'revision_id', '34')
    updateInput(page.root, 'owner.full_name', 'Ana Souza')
    updateInput(page.root, 'owner.email', 'ana@example.test')
    await flush()
    ;(findButton(page.root, 'Revisar termos')?.props.onClick as () => void)()
    await flush()
    assert.match(textOf(page.root), /Confirme os termos antes de enviar/)
    updateInput(page.root, 'owner.email', 'outra@example.test')
    await flush()
    assert.doesNotMatch(textOf(page.root), /Confirme os termos antes de enviar/)
    assert.equal(findButton(page.root, 'Enviar convite'), undefined)
    assert.equal(command.executeCalls.length, 0)
    page.app.unmount()
  })

  it('does not treat an unrelated recovered commercial resource as an invitation', async () => {
    const command = createCommand('7cbbdbb6-7f88-4479-a78a-471e4cb16c78')
    command.recoveryResult = { status: 'confirmed', data: { data: { id: 7, name: 'Oferta Varejo' } } }
    const page = harness('pages/gestao-erp/clientes/novo.vue', { command })
    await flush()
    assert.equal(findButton(page.root, 'Verificar resultado')?.props.disabled, false)
    selectOption(page.root, 'client_id', '18')
    selectOption(page.root, 'revision_id', '34')
    updateInput(page.root, 'owner.full_name', 'Ana Souza')
    updateInput(page.root, 'owner.email', 'ana@example.test')
    await flush()
    assert.equal(findButton(page.root, 'Revisar termos')?.props.disabled, true)
    assert.equal(typeof findButton(page.root, 'Revisar termos')?.props.disabled, 'boolean')
    ;(findButton(page.root, 'Verificar resultado')?.props.onClick as () => void)()
    await flush()
    assert.match(textOf(page.root), /resultado não corresponde a um convite/)
    assert.doesNotMatch(textOf(page.root), /Convite emitido/)
    assert.doesNotMatch(textOf(page.root), /Oferta Varejo/)
    assert.equal(command.executeCalls.length, 0)
    page.app.unmount()
  })

  it('shows a denied capacity preview without offering an issue action', async () => {
    const page = harness('pages/gestao-erp/clientes/novo.vue', { api: async (path) => {
      if (path.includes('onboarding-preview')) return { data: { ...testPreview().data, capacity: { ...testPreview().data.capacity, can_issue: false, reason: 'Capacidade esgotada.' } } }
      return path === '/erp-commercial/plans' ? testOffers() : testClients()
    } })
    await flush()
    selectOption(page.root, 'client_id', '18')
    selectOption(page.root, 'revision_id', '34')
    updateInput(page.root, 'owner.full_name', 'Ana Souza')
    updateInput(page.root, 'owner.email', 'ana@example.test')
    await flush()
    ;(findButton(page.root, 'Revisar termos')?.props.onClick as () => void)()
    await flush()
    assert.match(textOf(page.root), /Capacidade esgotada/)
    assert.equal(findButton(page.root, 'Enviar convite'), undefined)
    assert.equal(page.command.executeCalls.length, 0)
    page.app.unmount()
  })

  it('revokes through the journal command and displays its confirmed data result', async () => {
    const command = createCommand()
    command.executeResults.push(
      { status: 'confirmed', data: { invitation_id: 92, expires_at: '2026-11-01 12:00:00', status: 'pending' } },
      { status: 'confirmed', data: { invitation_id: 92, expires_at: '2026-11-01 12:00:00', status: 'revoked' } }
    )
    const page = harness('pages/gestao-erp/clientes/novo.vue', { command })
    await flush()
    selectOption(page.root, 'client_id', '18')
    selectOption(page.root, 'revision_id', '34')
    updateInput(page.root, 'owner.full_name', 'Ana Souza')
    updateInput(page.root, 'owner.email', 'ana@example.test')
    await flush()
    ;(findButton(page.root, 'Revisar termos')?.props.onClick as () => void)()
    await flush()
    ;(findButton(page.root, 'Enviar convite')?.props.onClick as () => void)()
    await flush()
    updateInput(page.root, 'revoke.reason', 'Solicitação do responsável')
    await flush()
    ;(findButton(page.root, 'Revogar convite')?.props.onClick as () => void)()
    await flush()
    assert.equal(command.executeCalls[1]?.[0], 'invitation.revoke')
    assert.equal(command.executeCalls[1]?.[1], '/erp-commercial/invitations/92/revoke')
    assert.deepEqual(command.executeCalls[1]?.[2], { reason: 'Solicitação do responsável' })
    assert.match(textOf(page.root), /Convite revogado/)
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
})

describe('Office ERP seller navigation', () => {
  it('adds a real active portfolio-client child beside offers', () => {
    assert.deepEqual(officeErpSidebarItem('/gestao-erp/clientes').children, [
      { label: 'Ofertas', to: '/gestao-erp/planos', exact: true, active: false },
      { label: 'Clientes da carteira', to: '/gestao-erp/clientes', exact: true, active: true }
    ])
  })
})
