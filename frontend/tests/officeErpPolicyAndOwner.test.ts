import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import type { OfficeErpCapabilities } from '../shared/types/officeErpCommercial.ts'

type Node = { tag?: string, text?: string, props: Record<string, unknown>, children: Node[], parent?: Node | null }
const testAccount = Vue.ref({ id: 8, status: 'active', role: 'admin' })
const testUser = Vue.ref({ id: 5 })
const sessionGeneration = Vue.ref(2)
const defaultCapabilities: OfficeErpCapabilities = {
  capability_key: 'office-capability-1',
  can_read: true,
  can_maintain: true,
  can_configure: true,
  can_sell_new: true,
  can_provision: true,
  can_start_trial: false,
  can_manage_trial_link: true,
  capability_reasons: {
    can_configure: [],
    can_sell_new: [],
    can_provision: [],
    can_start_trial: [],
    can_manage_trial_link: []
  },
  reasons: {},
  allowance: { enabled: true, licence_limit: null, module_keys: [] },
  usage: { reserved: 0, occupied: 0 }
}
const capability = Vue.ref<OfficeErpCapabilities>(structuredClone(defaultCapabilities))

function setCapability(overrides: Partial<OfficeErpCapabilities> = {}) {
  capability.value = { ...structuredClone(defaultCapabilities), ...overrides }
}

function renderText(node: Node): string {
  return `${node.text ?? ''}${node.children.map(renderText).join('')}`
}

function find(node: Node, predicate: (candidate: Node) => boolean): Node[] {
  return [...(predicate(node) ? [node] : []), ...node.children.flatMap(child => find(child, predicate))]
}

function mount(component: Vue.Component) {
  const root: Node = { props: {}, children: [] }
  const renderer = Vue.createRenderer({
    patchProp(node: Node, key, _previous, next) { node.props[key] = next },
    insert(node: Node, parent: Node, anchor?: Node) {
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
    },
    remove(node: Node) {
      if (!node.parent) return
      const index = node.parent.children.indexOf(node)
      if (index >= 0) node.parent.children.splice(index, 1)
    },
    createElement(tag) { return { tag, props: {}, children: [], parent: null } },
    createText(text) { return { text, props: {}, children: [], parent: null } },
    createComment(text) { return { text, props: {}, children: [], parent: null } },
    setText(node: Node, text) { node.text = text },
    setElementText(node: Node, text) {
      node.children = []
      node.text = text
    },
    parentNode(node: Node) { return node.parent ?? null },
    nextSibling(node: Node) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  const app = renderer.createApp(component)
  const components: Record<string, Vue.Component> = {
    NuxtLink: { props: ['to'], setup: (props, context) => () => Vue.h('a', { href: props.to }, context.slots.default?.()) },
    UButton: { props: ['disabled', 'loading', 'type'], setup: (props, context) => () => Vue.h('button', { ...context.attrs, 'disabled': props.disabled, 'data-loading': props.loading }, context.slots.default?.()) },
    UAlert: { props: ['title', 'description'], setup: (props, context) => () => Vue.h('aside', {}, [String(props.title ?? ''), String(props.description ?? ''), context.slots.default?.(), context.slots.description?.()]) },
    UCard: { setup: (_props, context) => () => Vue.h('section', {}, [context.slots.header?.(), context.slots.default?.()]) },
    UModal: { props: ['open'], setup: (props, context) => () => props.open ? Vue.h('section', { role: 'dialog' }, [context.slots.body?.(), context.slots.footer?.()]) : null },
    UBadge: { setup: (_props, context) => () => Vue.h('span', {}, context.slots.default?.()) },
    UFormField: { props: ['name', 'label', 'error'], setup: (props, context) => () => Vue.h('div', { 'data-field': props.name, 'aria-label': props.label }, [context.slots.default?.(), props.error ? String(props.error) : '']) },
    UInput: { props: ['modelValue', 'type', 'disabled'], setup: (props, context) => () => Vue.h('input', { ...context.attrs, ...props, 'onUpdate:modelValue': context.emit.bind(null, 'update:modelValue') }) },
    USelect: { props: ['modelValue', 'items', 'disabled'], setup: (props, context) => () => Vue.h('select', { ...context.attrs, 'modelValue': props.modelValue, 'disabled': props.disabled, 'onUpdate:modelValue': context.emit.bind(null, 'update:modelValue') }) },
    USelectMenu: { props: ['modelValue', 'items', 'multiple'], setup: (props, context) => () => Vue.h('select', { ...context.attrs, 'modelValue': props.modelValue, 'multiple': props.multiple, 'onUpdate:modelValue': context.emit.bind(null, 'update:modelValue') }) },
    UTextarea: { props: ['modelValue'], setup: (props, context) => () => Vue.h('textarea', { ...context.attrs, 'onUpdate:modelValue': context.emit.bind(null, 'update:modelValue') }, String(props.modelValue ?? '')) },
    USwitch: { props: ['modelValue', 'disabled'], setup: (props, context) => () => Vue.h('button', { ...context.attrs, 'role': 'switch', 'aria-checked': String(props.modelValue), 'disabled': props.disabled, 'onUpdate:modelValue': context.emit.bind(null, 'update:modelValue') }) },
    USkeleton: { setup: () => () => Vue.h('div', { 'aria-label': 'Carregando' }) },
    UTable: {
      props: ['data', 'columns'],
      setup: (props, context) => () => Vue.h('div', { ...context.attrs }, [Vue.h('table', {}, (props.data as Array<Record<string, unknown>>).map((row, index) => Vue.h('tr', { key: index }, Object.values(row).map(value => Vue.h('td', {}, String(value))))))])
    },
    UPagination: { props: ['page', 'total'], setup: (props, context) => () => Vue.h('nav', { 'data-total': props.total, 'data-page': props.page, 'onUpdate:page': context.emit.bind(null, 'update:page') }) }
  }
  for (const [name, component] of Object.entries(components)) app.component(name, component)
  app.mount(root)
  return { app, root }
}

function flush() {
  return Vue.nextTick().then(() => new Promise<void>(resolve => setImmediate(resolve))).then(() => Vue.nextTick())
}

function fixture(options: { account?: { id: number, status: string, role: string }, read?: () => Promise<unknown>, preview?: (body: Record<string, unknown>) => Promise<unknown>, execute?: (...args: unknown[]) => Promise<unknown>, recover?: (key: string) => Promise<unknown>, trialLink?: () => Promise<unknown> } = {}) {
  testAccount.value = options.account ?? { id: 8, status: 'active', role: 'admin' }
  testUser.value = { id: 5 }
  sessionGeneration.value = 2
  setCapability()
  const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
  const executeCalls: unknown[][] = []
  const recoverCalls: string[] = []
  const toasts: Array<Record<string, unknown>> = []
  const context = {
    capabilities: capability,
    capture: () => ({ key: JSON.stringify([testUser.value.id, sessionGeneration.value, testAccount.value.id, testAccount.value.status, testAccount.value.role, capability.value.capability_key]), generation: sessionGeneration.value }),
    isCurrent(token: { key: string, generation: number }) {
      return token.key === JSON.stringify([testUser.value.id, sessionGeneration.value, testAccount.value.id, testAccount.value.status, testAccount.value.role, capability.value.capability_key]) && token.generation === sessionGeneration.value
    },
    refresh: async () => {}
  }
  const command = {
    pending: Vue.ref(false),
    recoveryKey: Vue.ref<string | null>(null),
    async execute(...args: unknown[]) {
      executeCalls.push(args)
      const result = options.execute ? await options.execute(...args) : { status: 'confirmed', data: {} }
      if ((result as { status?: string }).status === 'unknown') {
        command.recoveryKey.value = (result as { key: string }).key
        command.pending.value = true
      } else if ((result as { status?: string }).status === 'confirmed') {
        command.pending.value = false
      }
      return result
    },
    async recover(key: string) {
      recoverCalls.push(key)
      const result = options.recover ? await options.recover(key) : { status: 'confirmed', data: {} }
      if ((result as { status?: string }).status === 'confirmed') {
        command.recoveryKey.value = null
        command.pending.value = false
      } else if ((result as { status?: string }).status === 'unknown') {
        command.pending.value = true
      }
      return result
    }
  }
  const component = compileVueComponent('pages/gestao-erp/configuracoes.vue', {
    onMounted: (callback: () => void) => { void callback() },
    onScopeDispose: () => {},
    useAuth: () => ({ user: testUser, currentAccount: testAccount }),
    useState: () => sessionGeneration,
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => command,
    useAccessApi: () => ({ api: async (path: string, request?: Record<string, unknown>) => {
      calls.push({ path, options: request })
      if (path === '/erp-commercial/settings/preview' && options.preview) return options.preview(request?.body as Record<string, unknown>)
      if (path === '/erp-commercial/settings' && options.read) return options.read()
      if (path === '/erp-commercial/trial-link' && options.trialLink) return options.trialLink()
      return { data: { mode: 'manual', grace_days: 0, billing_timezone: 'America/Sao_Paulo' } }
    } }),
    useToast: () => ({ add(value: Record<string, unknown>) { toasts.push(value) } }),
    useHead() {},
    definePageMeta() {}
  })
  return { ...mount(component), calls, executeCalls, recoverCalls, toasts, command, context }
}

function ownerContractFixture(api: (path: string) => Promise<unknown>) {
  const currentCompany = Vue.ref({ id: 12 })
  const currentAccount = Vue.ref<{ id: number } | null>(null)
  const sessionGeneration = Vue.ref(1)
  const calls: string[] = []
  const component = compileVueComponent('pages/erp/contrato.vue', {
    definePageMeta() {},
    onMounted: (callback: () => void) => { void callback() },
    onScopeDispose: () => {},
    useAuth: () => ({ user: testUser, currentCompany, currentAccount }),
    useHead() {},
    useState: () => sessionGeneration,
    useNuxtApp: () => ({ $api: async (path: string) => {
      calls.push(path)
      return api(path)
    } })
  })

  return { ...mount(component), currentCompany, currentAccount, sessionGeneration, calls }
}

function trialContractFixture(api: (path: string) => Promise<unknown>, execute: (...args: unknown[]) => Promise<unknown>) {
  const currentAccount = Vue.ref({ id: 8, status: 'active', role: 'admin' })
  const sessionGeneration = Vue.ref(1)
  const capabilities = Vue.ref({
    capability_key: 'office-capability-1', can_read: true, can_configure: true, can_maintain: true,
    allowance: { module_keys: ['purchases'] }
  })
  const calls: string[] = []
  const executeCalls: unknown[][] = []
  const context = {
    capabilities,
    capture: () => ({ key: `${sessionGeneration.value}:${currentAccount.value.id}`, generation: sessionGeneration.value }),
    isCurrent: (token: { key: string, generation: number }) => token.key === `${sessionGeneration.value}:${currentAccount.value.id}` && token.generation === sessionGeneration.value,
    refresh: async () => {}
  }
  const command = {
    pending: Vue.ref(false),
    recoveryKey: Vue.ref<string | null>(null),
    async execute(...args: unknown[]) {
      executeCalls.push(args)
      return execute(...args)
    },
    async recover() { return { status: 'unknown' } }
  }
  const component = compileVueComponent('pages/gestao-erp/assinaturas/[id].vue', {
    onScopeDispose: () => {},
    useRoute: () => ({ params: { id: '31' } }),
    useAuth: () => ({ user: testUser, currentAccount }),
    useState: () => sessionGeneration,
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => command,
    useAccessApi: () => ({ api: async (path: string) => {
      calls.push(path)
      return api(path)
    } }),
    useToast: () => ({ add() {} }),
    useHead() {},
    definePageMeta() {}
  })
  return { ...mount(component), calls, executeCalls }
}

describe('Company ERP contract page', () => {
  it('clears previously loaded retail terms after refresh denial and restores them on retry', async () => {
    let subscriptionRequests = 0
    const view = ownerContractFixture(async (path) => {
      if (path === '/erp/subscription') {
        subscriptionRequests++
        if (subscriptionRequests === 2)
          throw new Error('Acesso negado')

        const recovered = subscriptionRequests > 2
        return { data: {
          status: recovered ? 'active' : 'pending',
          plan: { name: 'Plano ERP', modules: [] },
          company: { name: recovered ? 'Empresa recuperada' : 'Empresa anterior', status: 'active' },
          retail_contract: {
            agreement_status: recovered ? 'effective' : 'accepted',
            terms: { price: recovered ? '200.00' : '125.00', currency: 'BRL', interval_months: 1, due_in_days: 10, module_keys: [] },
            service_anchor_on: null,
            billing_timezone: 'America/Sao_Paulo'
          }
        } }
      }

      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
    })
    await flush()

    assert.match(renderText(view.root), /Empresa anterior/)
    assert.match(renderText(view.root), /125,00/)
    const refreshButton = find(view.root, node => node.tag === 'button' && renderText(node).includes('Atualizar'))[0]!
    ;(refreshButton.props.onClick as () => void)()
    await flush()

    assert.match(renderText(view.root), /Não foi possível carregar o contrato/)
    assert.doesNotMatch(renderText(view.root), /Empresa anterior|125,00|Contrato de revenda/)
    const retryButton = find(view.root, node => node.tag === 'button' && renderText(node).includes('Tentar novamente'))[0]!
    ;(retryButton.props.onClick as () => void)()
    await flush()

    assert.match(renderText(view.root), /Empresa recuperada/)
    assert.match(renderText(view.root), /200,00/)
    assert.doesNotMatch(renderText(view.root), /Não foi possível carregar o contrato/)
    view.app.unmount()
  })

  it('renders persistent pending status, accepted retail terms and only the current Company invoices without activation', async () => {
    const view = ownerContractFixture(async (path) => {
      if (path === '/erp/subscription') {
        return { data: {
          status: 'pending',
          plan: { name: 'Plano técnico legado', modules: [{ module_key: 'purchases' }] },
          company: { name: 'Empresa do owner', status: 'active' },
          retail_contract: {
            agreement_status: 'accepted',
            terms: { price: '125.00', currency: 'BRL', interval_months: 1, due_in_days: 10, module_keys: ['purchases'] },
            service_anchor_on: null,
            billing_timezone: 'America/Sao_Paulo'
          }
        } }
      }

      if (path === '/erp/commercial-invoices/41') {
        return { data: {
          id: 41,
          kind: 'recurring',
          document_status: 'issued',
          number: 'ERP-2026-0001',
          issued_at: '2026-10-25T12:00:00.000Z',
          period_start: '2026-10-25',
          period_end: '2026-11-25',
          due_on: '2026-11-04',
          currency: 'BRL',
          items: [{ description: 'Licença ERP', amount: '125.00' }],
          original_total: '125.00',
          adjustments: [],
          balance: { original_total: '125.00', adjusted_charge: '125.00', effective_received: '0.00', outstanding: '125.00', settlement_status: 'unpaid', overdue: false }
        } }
      }

      return { data: [{
        id: 41,
        number: 'ERP-2026-0001',
        kind: 'recurring',
        document_status: 'issued',
        period_start: '2026-10-25',
        period_end: '2026-11-25',
        due_on: '2026-11-04',
        items: [{ description: 'Licença ERP', amount: '125.00' }],
        adjustments: [],
        original_total: '125.00',
        balance: { original_total: '125.00', adjusted_charge: '125.00', effective_received: '0.00', outstanding: '125.00', settlement_status: 'unpaid', overdue: false }
      }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }
    })
    await flush()

    assert.match(renderText(view.root), /Empresa do owner/)
    assert.match(renderText(view.root), /Pendente de habilitação/)
    assert.match(renderText(view.root), /Contrato de revenda/)
    assert.match(renderText(view.root), /125,00/)
    assert.match(renderText(view.root), /ERP-2026-0001/)
    assert.doesNotMatch(renderText(view.root), /Ativar contrato|Ativar agora/)
    const detailButton = find(view.root, node => node.tag === 'button' && renderText(node).includes('Ver detalhes'))[0]
    assert.ok(detailButton)
    ;(detailButton.props.onClick as () => void)()
    await flush()
    assert.match(renderText(view.root), /Detalhes de ERP-2026-0001/)
    assert.match(renderText(view.root), /Saldo em aberto\s+R\$ 125,00/)
    assert.match(renderText(view.root), /Licença ERP/)
    assert.deepEqual(view.calls, ['/erp/subscription', '/erp/commercial-invoices', '/erp/commercial-invoices/41'])
    view.app.unmount()
  })

  it('preserves the existing unlinked direct subscription presentation without requesting retail invoices', async () => {
    const view = ownerContractFixture(async () => ({ data: {
      status: 'active',
      plan: { name: 'Plano ERP legado', modules: [{ module_key: 'purchases' }] },
      company: { name: 'Empresa independente', status: 'active' }
    } }))
    await flush()

    assert.match(renderText(view.root), /Empresa independente/)
    assert.match(renderText(view.root), /Plano ERP legado/)
    assert.match(renderText(view.root), /Módulos do plano/)
    assert.doesNotMatch(renderText(view.root), /Contrato de revenda|Faturas do contrato/)
    assert.deepEqual(view.calls, ['/erp/subscription'])
    view.app.unmount()
  })

  it('renders the server trial situation and original end date for the owner', async () => {
    const view = ownerContractFixture(async () => ({ data: {
      status: 'trial_expired',
      plan: { name: 'Plano ERP de teste', modules: [{ module_key: 'purchases' }] },
      company: { name: 'Empresa em avaliação', status: 'active' },
      trial: {
        started_at: '2026-10-18T10:00:00Z',
        ends_at: '2026-10-25T10:00:00Z',
        converted_at: null,
        expired_at: '2026-10-25T10:00:00Z',
        situation: 'expired'
      }
    } }))
    await flush()

    assert.match(renderText(view.root), /Teste encerrado/)
    assert.match(renderText(view.root), /Fim original do teste/)
    assert.match(renderText(view.root), /Fim original do teste: 25 de outubro de 2026 às 07:00/)
    assert.doesNotMatch(renderText(view.root), /Ativar contrato|Converter teste/)
    assert.deepEqual(view.calls, ['/erp/subscription'])
    view.app.unmount()
  })

  it('discards a delayed contract response after the current Company changes', async () => {
    let resolveSubscription!: (value: unknown) => void
    const view = ownerContractFixture((path) => {
      if (path === '/erp/subscription') {
        return new Promise((resolve) => {
          resolveSubscription = resolve
        })
      }

      throw new Error('Faturas indisponíveis')
    })
    await flush()
    view.currentCompany.value = { id: 13 }
    resolveSubscription({ data: {
      status: 'pending',
      plan: { name: 'Plano atual', modules: [] },
      company: { name: 'Empresa anterior protegida', status: 'active' },
      retail_contract: { agreement_status: 'accepted', terms: { price: '125.00', currency: 'BRL', interval_months: 1, due_in_days: 10, module_keys: [] }, service_anchor_on: null, billing_timezone: 'America/Sao_Paulo' }
    } })
    await flush()

    assert.doesNotMatch(renderText(view.root), /Empresa anterior protegida|Faturas indisponíveis/)
    view.app.unmount()
  })

  it('discards delayed contract responses after the Account or authenticated session changes', async () => {
    for (const changeAuthority of [
      (view: ReturnType<typeof ownerContractFixture>) => { view.currentAccount.value = { id: 91 } },
      (view: ReturnType<typeof ownerContractFixture>) => { view.sessionGeneration.value++ }
    ]) {
      let resolveSubscription!: (value: unknown) => void
      const view = ownerContractFixture(() => new Promise((resolve) => {
        resolveSubscription = resolve
      }))
      await flush()
      changeAuthority(view)
      resolveSubscription({ data: {
        status: 'pending',
        plan: { name: 'Plano antigo', modules: [] },
        company: { name: 'Empresa antiga protegida', status: 'active' },
        retail_contract: { agreement_status: 'accepted', terms: { price: '125.00', currency: 'BRL', interval_months: 1, due_in_days: 10, module_keys: [] }, service_anchor_on: null, billing_timezone: 'America/Sao_Paulo' }
      } })
      await flush()

      assert.doesNotMatch(renderText(view.root), /Empresa antiga protegida/)
      view.app.unmount()
    }
  })

  it('keeps retail terms visible and reports an invoice error without inventing a balance', async () => {
    const view = ownerContractFixture(async (path) => {
      if (path === '/erp/subscription') {
        return { data: {
          status: 'pending',
          plan: { name: 'Plano atual', modules: [] },
          company: { name: 'Empresa atual', status: 'active' },
          retail_contract: { agreement_status: 'accepted', terms: { price: '125.00', currency: 'BRL', interval_months: 1, due_in_days: 10, module_keys: [] }, service_anchor_on: null, billing_timezone: 'America/Sao_Paulo' }
        } }
      }

      throw new Error('Faturas indisponíveis')
    })
    await flush()

    assert.match(renderText(view.root), /Contrato de revenda/)
    assert.match(renderText(view.root), /Não foi possível carregar as faturas/)
    assert.doesNotMatch(renderText(view.root), /0,00|0 contratos/)
    view.app.unmount()
  })
})

describe('Office ERP policy settings page', () => {
  it('loads existing settings when commercial resale is disabled', async () => {
    setCapability({
      can_sell_new: false,
      capability_reasons: {
        can_configure: [],
        can_sell_new: ['commercial_resale_disabled'],
        can_provision: [],
        can_start_trial: [],
        can_manage_trial_link: []
      },
      reasons: { commercial_resale_disabled: ['commercial_resale_disabled'] }
    })
    const view = fixture()
    await flush()
    assert.equal(view.calls.filter(call => call.path === '/erp-commercial/settings').length, 1)
    assert.match(renderText(view.root), /Automatizar suspensão por atraso/)
    assert.doesNotMatch(renderText(view.root), /Tentar novamente/)
    assert.equal(find(view.root, node => node.tag === 'button' && renderText(node).includes('Salvar configuração'))[0]?.props.disabled, false)
    view.app.unmount()
  })

  it('shows a definitive role or status denial without offering a retry', async () => {
    setCapability({ can_read: false, can_configure: false, can_manage_trial_link: false })
    const view = fixture({ account: { id: 8, status: 'suspended', role: 'admin' } })
    await flush()
    assert.equal(view.calls.filter(call => call.path === '/erp-commercial/settings').length, 0)
    assert.match(renderText(view.root), /escritório selecionado está suspenso/)
    assert.doesNotMatch(renderText(view.root), /Tentar novamente/)
    assert.doesNotMatch(renderText(view.root), /Política de inadimplência/)
    view.app.unmount()

    setCapability({ can_read: false, can_configure: false, can_manage_trial_link: false })
    const roleView = fixture({ account: { id: 8, status: 'active', role: 'operator' } })
    await flush()
    assert.equal(roleView.calls.filter(call => call.path === '/erp-commercial/settings').length, 0)
    assert.match(renderText(roleView.root), /Somente administradores do escritório/)
    assert.doesNotMatch(renderText(roleView.root), /Tentar novamente/)
    roleView.app.unmount()
  })

  it('does not invent zero impact when the initial settings request fails', async () => {
    const view = fixture({ read: async () => {
      throw new Error('Leitura indisponível')
    } })
    await flush()
    assert.match(renderText(view.root), /Leitura indisponível/)
    assert.match(renderText(view.root), /Tentar novamente/)
    assert.doesNotMatch(renderText(view.root), /0 contratos afetados/)
    view.app.unmount()
  })

  it('keeps the automatic switch as a draft and previews real affected contracts without writing', async () => {
    const view = fixture({ preview: async _body => ({ data: [{ id: 12, company_name: 'Comercial A', status: 'effective' }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 2 }, affected_count: 2 }) })
    await flush()
    const toggle = find(view.root, node => node.props.role === 'switch')[0]
    assert.ok(toggle)
    ;(toggle.props['onUpdate:modelValue'] as (value: boolean) => void)(true)
    await flush()
    assert.equal(view.executeCalls.length, 0)
    assert.equal(view.calls.filter(call => call.path === '/erp-commercial/settings/preview').length, 0)
    const preview = find(view.root, node => node.tag === 'button' && renderText(node).includes('Pré-visualizar consequências'))[0]
    assert.ok(preview)
    ;(preview.props.onClick as () => void)()
    await flush()
    const previewCall = view.calls.find(call => call.path === '/erp-commercial/settings/preview')
    assert.deepEqual(previewCall?.options?.body, { mode: 'automatic', grace_days: 0, billing_timezone: 'America/Sao_Paulo', page: 1 })
    assert.match(renderText(view.root), /2 contratos afetados/)
    assert.match(renderText(view.root), /Comercial A/)
    const scrollRegion = find(view.root, node => node.tag === 'div' && node.props['tabindex'] === '0')[0]
    assert.ok(scrollRegion, 'the preview table scroll region should be keyboard focusable')
    assert.equal(scrollRegion.props['aria-label'], 'Tabela de contratos afetados')
    assert.equal(view.executeCalls.length, 0)
    view.app.unmount()
  })

  it('requires explicit confirmation to save automatic policy and keeps a 422 draft visible', async () => {
    const view = fixture({
      preview: async () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 }, affected_count: 0 }),
      execute: async () => ({ status: 'failed', failure: { code: 'validation_failed', message: 'Revise os campos.', http_status: 422, errors: { grace_days: ['Informe uma tolerância válida.'] } } })
    })
    await flush()
    const toggle = find(view.root, node => node.props.role === 'switch')[0]!
    ;(toggle.props['onUpdate:modelValue'] as (value: boolean) => void)(true)
    await flush()
    const save = find(view.root, node => node.tag === 'button' && renderText(node).includes('Salvar configuração'))[0]
    assert.ok(save)
    ;(save.props.onClick as () => void)()
    await flush()
    assert.equal(view.executeCalls.length, 0)
    const preview = find(view.root, node => node.tag === 'button' && renderText(node).includes('Pré-visualizar consequências'))[0]!
    ;(preview.props.onClick as () => void)()
    await flush()
    const confirm = find(view.root, node => node.tag === 'button' && renderText(node).includes('Confirmar ativação automática'))[0]
    assert.ok(confirm)
    ;(confirm.props.onClick as () => void)()
    await flush()
    assert.equal(view.executeCalls.length, 1)
    assert.deepEqual(view.executeCalls[0], ['policy.configure', '/erp-commercial/settings', { mode: 'automatic', grace_days: 0, billing_timezone: 'America/Sao_Paulo', confirm_automatic: true }, 'PATCH'])
    assert.match(renderText(view.root), /Revise os campos/)
    assert.match(renderText(view.root), /tolerância válida/)
    view.app.unmount()
  })

  it('retains an unknown save key for one explicit recovery lookup without resending', async () => {
    const key = '7cbbdbb6-7f88-4479-a78a-471e4cb16c78'
    const view = fixture({ execute: async () => ({ status: 'unknown', key }), recover: async () => ({ status: 'unknown', key }) })
    await flush()
    const save = find(view.root, node => node.tag === 'button' && renderText(node).includes('Salvar configuração'))[0]!
    ;(save.props.onClick as () => void)()
    await flush()
    assert.equal(view.executeCalls.length, 1)
    assert.equal(view.command.recoveryKey.value, key)
    const duplicateSave = find(view.root, node => node.tag === 'button' && renderText(node).includes('Salvar configuração'))[0]!
    assert.equal(duplicateSave.props.disabled, true)
    ;(duplicateSave.props.onClick as () => void)()
    await flush()
    assert.equal(view.executeCalls.length, 1)
    const recover = find(view.root, node => node.tag === 'button' && renderText(node).includes('Verificar resultado'))[0]
    assert.ok(recover)
    assert.equal(recover.props.disabled, false)
    ;(recover.props.onClick as () => void)()
    await flush()
    assert.deepEqual(view.recoverCalls, [key])
    assert.equal(view.executeCalls.length, 1)
    assert.equal(view.command.recoveryKey.value, key)
    view.app.unmount()
  })

  it('discards a delayed preview after Account authority changes', async () => {
    let finish!: (value: unknown) => void
    const view = fixture({ preview: () => new Promise((resolve) => {
      finish = resolve
    }) })
    await flush()
    const preview = find(view.root, node => node.tag === 'button' && renderText(node).includes('Pré-visualizar consequências'))[0]
    assert.ok(preview)
    ;(preview.props.onClick as () => void)()
    await flush()
    testAccount.value = { id: 9, status: 'active', role: 'admin' }
    await flush()
    finish({ data: [{ id: 12, company_name: 'Conta anterior', status: 'effective' }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 }, affected_count: 1 })
    await flush()
    assert.doesNotMatch(renderText(view.root), /Conta anterior/)
    view.app.unmount()
  })

  it('invalidates the counted rows whenever the draft changes', async () => {
    const view = fixture({ preview: async () => ({ data: [{ contract_id: 12, client_name: 'Cliente A', company_name: 'Comercial A', current_status: 'active', resulting_status: 'past_due', outstanding: '100.00', overdue: true }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 }, affected_count: 1 }) })
    await flush()
    const preview = find(view.root, node => node.tag === 'button' && renderText(node).includes('Pré-visualizar consequências'))[0]!
    ;(preview.props.onClick as () => void)()
    await flush()
    assert.match(renderText(view.root), /Cliente A/)
    const toggle = find(view.root, node => node.props.role === 'switch')[0]!
    ;(toggle.props['onUpdate:modelValue'] as (value: boolean) => void)(true)
    await flush()
    assert.doesNotMatch(renderText(view.root), /Cliente A/)
    assert.doesNotMatch(renderText(view.root), /1 contratos afetados/)
    assert.equal(view.executeCalls.length, 0)
    view.app.unmount()
  })

  it('lets a replacement preview run after draft edits and keeps its loading state when the old request rejects', async () => {
    const pending: Array<{ body: Record<string, unknown>, reject: (error: Error) => void, resolve: (value: unknown) => void }> = []
    const view = fixture({
      preview: body => new Promise((resolve, reject) => {
        pending.push({ body, reject, resolve })
      })
    })
    await flush()

    const previewButton = () => find(view.root, node => node.tag === 'button' && renderText(node).includes('Pré-visualizar consequências'))[0]!
    ;(previewButton().props.onClick as () => void)()
    await flush()
    assert.equal(pending.length, 1)
    assert.equal(previewButton().props.disabled, true)

    const toggle = find(view.root, node => node.props.role === 'switch')[0]!
    ;(toggle.props['onUpdate:modelValue'] as (value: boolean) => void)(true)
    await flush()
    const graceDays = find(view.root, node => node.tag === 'input' && node.props.type === 'number')[0]!
    ;(graceDays.props['onUpdate:modelValue'] as (value: number) => void)(5)
    await flush()
    const timezone = find(view.root, node => node.tag === 'input' && node.props.type !== 'number')[0]!
    ;(timezone.props['onUpdate:modelValue'] as (value: string) => void)('America/Manaus')
    await flush()

    assert.equal(previewButton().props.disabled, false)
    ;(previewButton().props.onClick as () => void)()
    await flush()
    assert.equal(pending.length, 2)
    assert.deepEqual(pending[1]?.body, { mode: 'automatic', grace_days: 5, billing_timezone: 'America/Manaus', page: 1 })

    pending[0]?.reject(new Error('Prévia antiga indisponível'))
    await flush()
    assert.equal(previewButton().props.disabled, true)
    assert.doesNotMatch(renderText(view.root), /Prévia antiga indisponível/)

    pending[1]?.resolve({ data: [{ contract_id: 9, client_name: 'Cliente atual', company_name: 'Empresa atual', current_status: 'active', resulting_status: 'past_due', outstanding: '30.00', overdue: true }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 }, affected_count: 1 })
    await flush()
    assert.match(renderText(view.root), /Cliente atual/)

    const save = find(view.root, node => node.tag === 'button' && renderText(node).includes('Salvar configuração'))[0]!
    ;(save.props.onClick as () => void)()
    await flush()
    const confirm = find(view.root, node => node.tag === 'button' && renderText(node).includes('Confirmar ativação automática'))[0]
    assert.ok(confirm)
    assert.equal(confirm.props.disabled, false)
    view.app.unmount()
  })

  it('discards delayed preview callbacks after session or capability identity changes', async () => {
    for (const change of [
      () => { sessionGeneration.value++ },
      () => { capability.value = { ...capability.value, capability_key: 'office-capability-2' } }
    ]) {
      let finish!: (value: unknown) => void
      const view = fixture({ preview: () => new Promise((resolve) => {
        finish = resolve
      }) })
      await flush()
      const preview = find(view.root, node => node.tag === 'button' && renderText(node).includes('Pré-visualizar consequências'))[0]!
      ;(preview.props.onClick as () => void)()
      await flush()
      change()
      await flush()
      finish({ data: [{ contract_id: 2, client_name: 'Conta anterior', company_name: 'Empresa antiga', current_status: 'active', resulting_status: 'past_due', outstanding: '1.00', overdue: true }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 }, affected_count: 1 })
      await flush()
      assert.doesNotMatch(renderText(view.root), /Conta anterior/)
      view.app.unmount()
    }
  })

  it('renders and copies the office trial link, then rotates it through the keyed command', async () => {
    const resource = {
      configured: true,
      enabled: true,
      revision: 4,
      url: 'https://app.example.test/erp/testar/link-atual',
      erp_plan_id: 73,
      plan: { name: 'ERP Essencial', module_keys: ['purchases'] },
      plans: [{ id: 73, name: 'ERP Essencial', module_keys: ['purchases'] }]
    }
    const copied: string[] = []
    const previousNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator')
    Object.defineProperty(globalThis, 'navigator', {
      configurable: true,
      value: { clipboard: { writeText: async (value: string) => { copied.push(value) } } }
    })
    const view = fixture({
      trialLink: async () => ({ data: resource }),
      execute: async (..._args: unknown[]) => ({ status: 'confirmed', data: { ...resource, revision: 5, url: 'https://app.example.test/erp/testar/link-novo' } })
    })
    try {
      await flush()
      assert.match(renderText(view.root), /Link de teste ERP/)
      assert.match(renderText(view.root), /ERP Essencial/)
      const copy = find(view.root, node => node.tag === 'button' && renderText(node).includes('Copiar link'))[0]
      assert.ok(copy)
      ;(copy.props.onClick as () => void)()
      await flush()
      assert.deepEqual(copied, [resource.url])

      const rotate = find(view.root, node => node.tag === 'button' && renderText(node).includes('Renovar endereço do link'))[0]
      assert.ok(rotate)
      ;(rotate.props.onClick as () => void)()
      await flush()
      assert.deepEqual(view.executeCalls[0], ['trial.link.rotate', '/erp-commercial/trial-link/rotate', {}, 'POST'])
      assert.match(renderText(view.root), /5/)
      assert.equal(view.calls.some(call => call.path === '/erp-commercial/trial-link'), true)
    } finally {
      view.app.unmount()
      if (previousNavigator)
        Object.defineProperty(globalThis, 'navigator', previousNavigator)
      else
        Reflect.deleteProperty(globalThis, 'navigator')
    }
  })

  it('preserves the loaded trial plan catalog when PUT and rotate responses omit plans', async () => {
    const resource = {
      configured: true,
      enabled: true,
      revision: 4,
      url: 'https://app.example.test/erp/testar/link-atual',
      erp_plan_id: 73,
      plan: { name: 'ERP Essencial', module_keys: ['purchases'] },
      plans: [{ id: 73, name: 'ERP Essencial', module_keys: ['purchases'] }]
    }
    const { plans: _plans, ...writeResult } = {
      ...resource,
      revision: 5,
      url: 'https://app.example.test/erp/testar/link-novo'
    }
    const view = fixture({
      trialLink: async () => ({ data: resource }),
      execute: async () => ({ status: 'confirmed', data: writeResult })
    })
    try {
      await flush()
      const save = find(view.root, node => node.tag === 'button' && renderText(node).includes('Salvar link'))[0]
      assert.ok(save)
      ;(save.props.onClick as () => void)()
      await flush()
      assert.match(renderText(view.root), /ERP Essencial/)
      assert.equal(find(view.root, node => node.tag === 'select').length, 1)

      const rotate = find(view.root, node => node.tag === 'button' && renderText(node).includes('Renovar endereço do link'))[0]
      assert.ok(rotate)
      ;(rotate.props.onClick as () => void)()
      await flush()
      assert.match(renderText(view.root), /Plano atual: ERP Essencial · revisão 5/)
      assert.equal(find(view.root, node => node.tag === 'select').length, 1)
      assert.equal(view.executeCalls.length, 2)
    } finally {
      view.app.unmount()
    }
  })

  it('loads eligible plans for office-only trial conversion and submits explicit paid terms', async () => {
    const detail = {
      id: 31,
      client: { id: 4, name: 'Cliente contábil' },
      company: { id: 12, name: 'Empresa em teste' },
      erp_plan_id: 73,
      trial: { id: 81, started_at: '2026-10-18T10:00:00Z', ends_at: '2026-10-25T10:00:00Z', converted_at: null, expired_at: null, situation: 'trialing' },
      agreement_status: 'accepted', licence_status: 'trialing', current_revision: null, pending_revision: null,
      service_anchor_on: null, next_period_month_offset: null, cancel_effective_on: null, cancellation_reason: null,
      manual_hold: false, balance: { outstanding: '0.00', invoice_count: 0 }, revisions: []
    }
    const view = trialContractFixture(async (path) => {
      if (path === '/erp-commercial/contracts/31') return { data: detail }
      if (path === '/erp-commercial/enrollment-plans?contract_id=31') return {
        data: [{ id: 73, slug: 'essencial', name: 'ERP Essencial', module_keys: ['purchases'] }],
        eligibility: {
          can_provision: false, reasons: ['licence_limit_reached'], licence_limit: 1, used_licences: 1, available_module_keys: ['purchases'],
          trial_conversion: { eligible: true, capacity_mode: 'occupied_trial', reason: null }
        }
      }
      if (path === '/erp-commercial/settings') return { data: { billing_timezone: 'America/Sao_Paulo' } }
      throw new Error(`Unexpected request ${path}`)
    }, async () => ({ status: 'confirmed', data: { contract_id: 31, invoice_id: 99 } }))
    try {
      await flush()
      assert.match(renderText(view.root), /Em teste/)
      assert.doesNotMatch(renderText(view.root), /Ativar e emitir primeira fatura/)
      assert.equal(find(view.root, node => node.tag === 'button' && renderText(node).includes('Ativar e emitir primeira fatura')).length, 0)
      assert.equal(view.executeCalls.length, 0, 'the accepted trial detail has no legacy activate action')
      const start = find(view.root, node => node.tag === 'button' && renderText(node).includes('Iniciar cobrança paga'))[0]
      assert.ok(start)
      ;(start.props.onClick as () => void)()
      await flush()
      assert.match(renderText(view.root), /Confirme o plano e os termos pagos/)

      const fields = find(view.root, node => node.tag === 'div' && node.props['aria-label'] === 'Plano ERP')
      const planSelect = find(fields[0]!, node => node.tag === 'select')[0]
      ;(planSelect.props['onUpdate:modelValue'] as (value: number) => void)(73)
      const priceField = find(view.root, node => node.tag === 'div' && node.props['aria-label'] === 'Preço em reais')[0]!
      const priceInput = find(priceField, node => node.tag === 'input')[0]!
      ;(priceInput.props['onUpdate:modelValue'] as (value: string) => void)('149,90')
      await flush()

      const confirm = find(view.root, node => node.tag === 'button' && renderText(node).includes('Confirmar conversão e emitir primeira fatura'))[0]
      assert.ok(confirm)
      ;(confirm.props.onClick as () => void)()
      await flush()
      assert.deepEqual(view.executeCalls[0], [
        'trial.convert', '/erp-commercial/contracts/31/convert-trial',
        { erp_plan_id: 73, price: '149.90', currency: 'BRL', interval_months: 1, due_in_days: 0, start_on: `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}-${String(new Date().getDate()).padStart(2, '0')}`, billing_timezone: 'America/Sao_Paulo' },
        'POST'
      ])
      assert.equal(view.calls.includes('/erp-commercial/enrollment-plans?contract_id=31'), true)
      assert.doesNotMatch(renderText(view.root), /capacidade ou plano elegível/)
    } finally {
      view.app.unmount()
    }
  })

  it('does not offer trial conversion when enrollment capacity is unavailable', async () => {
    const detail = {
      id: 31, client: { id: 4, name: 'Cliente' }, company: { id: 12, name: 'Empresa' }, erp_plan_id: 73,
      trial: { id: 81, started_at: '2026-10-18T10:00:00Z', ends_at: '2026-10-25T10:00:00Z', converted_at: null, expired_at: '2026-10-25T10:00:00Z', situation: 'expired' },
      agreement_status: 'effective', licence_status: 'trial_expired', current_revision: null, pending_revision: null,
      service_anchor_on: null, next_period_month_offset: null, cancel_effective_on: null, cancellation_reason: null,
      manual_hold: false, balance: { outstanding: '0.00', invoice_count: 0 }, revisions: []
    }
    const view = trialContractFixture(async (path) => {
      if (path === '/erp-commercial/contracts/31') return { data: detail }
      if (path === '/erp-commercial/enrollment-plans?contract_id=31') return {
        data: [{ id: 73, slug: 'essencial', name: 'ERP Essencial', module_keys: ['purchases'] }],
        eligibility: {
          can_provision: false, reasons: ['licence_limit_reached'], licence_limit: 1, used_licences: 1, available_module_keys: ['purchases'],
          trial_conversion: { eligible: false, capacity_mode: null, reason: 'licence_capacity_reached' }
        }
      }
      if (path === '/erp-commercial/settings') return { data: { billing_timezone: 'America/Sao_Paulo' } }
      throw new Error(`Unexpected request ${path}`)
    }, async () => ({ status: 'confirmed', data: {} }))
    try {
      await flush()
      const start = find(view.root, node => node.tag === 'button' && renderText(node).includes('Iniciar cobrança paga'))[0]!
      ;(start.props.onClick as () => void)()
      await flush()
      assert.match(renderText(view.root), /capacidade ou plano elegível/)
      assert.doesNotMatch(renderText(view.root), /Confirme o plano e os termos pagos/)
      assert.equal(view.executeCalls.length, 0)
    } finally {
      view.app.unmount()
    }
  })
})
