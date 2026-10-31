import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent, mountVueComponent, type VueHostNode } from './helpers/compileVueComponent.ts'

const emptyComponent = Vue.defineComponent({ setup: () => () => null })
const passthrough = Vue.defineComponent({ setup: (_props, { slots }) => () => Vue.h('div', slots.default?.()) })
const text = (node: VueHostNode): string => `${node.text ?? ''}${node.children.map(text).join('')}`

const company = {
  id: 804, registration_id: 71, client_id: 52, name: 'Empresa de operação', cnpj: '12345678000195', registered_at: '2026-10-20T12:00:00Z',
  current_contract_id: 91, owner_setup_status: 'unclaimed',
  capabilities: { relationship: 'current', can_assign: true, can_edit_profile: true, can_manage_members: true, capability_key: 'company-804' },
  relationship: 'current', subscription: { id: 3, status: 'active', erp_plan: { id: 4, name: 'Plano contratado' } },
  current_contract: { id: 91, agreement_status: 'effective', next_billing_on: '2026-11-20', trial: null }
}
const offer = {
  id: 6, name: 'Plano operacional', description: 'Descrição do plano', status: 'active',
  current_revision: { revision_number: 2, published: true, price: '1234567890123.45', interval_months: 3, module_keys: ['purchases'] }
}
const fixtures: Record<string, unknown[]> = {
  'empresas/index.vue': [company],
  'assinaturas/index.vue': [company],
  'planos.vue': [offer],
  'faturas/index.vue': [{ id: 44, contract_id: 91, number: 'FAT-0044', document_status: 'issued', due_on: '2026-11-20', original_total: '1234567890123.45', balance: { adjusted_charge: '1234567890123.45', effective_received: '0.00', outstanding: '1234567890123.45' }, bill_to_snapshot: { client: { name: 'Empresa de operação' } } }],
  'recebimentos.vue': [{ id: 45, amount: '1234567890123.45', received_on: '2026-10-20', reference: 'Pagamento manual', proof_id: null, allocations: [{ invoice_id: 44, amount: '1234567890123.45' }], reversal: null }]
}

async function mountList(path: string, options: { rows?: unknown[], canRead?: boolean } = {}) {
  const calls: Array<{ path: string, params: Record<string, unknown> }> = []
  const navigation = Vue.ref<Record<string, string | number>>({ q: '', status: 'active', state: 'all', from: '', to: '', dueFrom: '', dueTo: '', contractId: '', page: 1, perPage: 25, sort: '', direction: 'asc' })
  const capabilities = Vue.ref({ can_read: options.canRead ?? true, can_sell_new: true, can_maintain: true, capability_key: 'lists', allowance: { module_keys: ['purchases'], licence_limit: 5 }, usage: { reserved: 0, occupied: 1 }, reasons: {} })
  const context = { capabilities, refresh: async () => {}, capture: () => ({ key: 'office-8' }), isCurrent: () => true }
  const bindings = {
    onMounted: Vue.onMounted, onBeforeUnmount: Vue.onBeforeUnmount,
    refDebounced: (value: Vue.Ref) => value,
    definePageMeta() {}, useRoute: () => ({ query: {} }), navigateTo: async () => {},
    useAuth: () => ({ user: Vue.ref({ id: 5 }), currentAccount: Vue.ref({ id: 8, status: 'active', role: 'admin' }) }),
    useState: (_key: string, initial: () => unknown) => Vue.ref(initial()),
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => ({ pending: Vue.ref(false), recoveryKey: Vue.ref(null) }),
    useToast: () => ({ add() {} }),
    useAccessApi: () => ({ api: async (url: string, requestOptions?: { params?: Record<string, unknown> }) => {
      calls.push({ path: url, params: requestOptions?.params ?? {} })
      const rows = options.rows ?? fixtures[path]!
      return { data: rows, meta: { total: rows.length, current_page: 1, last_page: 1, per_page: 25 } }
    } })
  }
  const mocks = {
    '~/composables/useOfficeErpListNavigation': {
      useOfficeErpListNavigation: () => navigation,
      officeErpAvailablePage: (meta: { current_page: number }) => meta.current_page
    }
  }
  const component = compileVueComponent(`pages/gestao-erp/${path}`, bindings, mocks)
  const mounted = mountVueComponent(component, {
    OfficeErpPanel: compileVueComponent('components/office-erp/OfficeErpPanel.vue'),
    OfficeErpDataTable: compileVueComponent('components/office-erp/OfficeErpDataTable.vue'),
    OfficeErpPagination: compileVueComponent('components/office-erp/OfficeErpPagination.vue'),
    DataTableRowActionsMenu: compileVueComponent('components/data-table/RowActionsMenu.vue'),
    OfficeErpPlanEditor: emptyComponent, OfficeErpReceiptModal: emptyComponent, OfficeErpReversalModal: emptyComponent,
    OfficeErpCompanyPortfolioModal: emptyComponent, OfficeErpCompanyInspectionModal: emptyComponent, OfficeErpCompanyAssignmentModal: emptyComponent,
    UButton: Vue.defineComponent({ props: ['label', 'to', 'disabled'], setup: (props, { attrs, slots }) => () => Vue.h('button', { ...attrs, to: props.to, disabled: props.disabled }, slots.default?.() ?? props.label) }),
    UModal: emptyComponent, UIcon: emptyComponent, UPopover: passthrough, UTooltip: passthrough,
    UPageCard: Vue.defineComponent({ props: ['title'], setup: (props, { slots }) => () => Vue.h('article', { 'data-page-card': true }, [props.title ? Vue.h('h2', slots.title?.() ?? props.title) : null, slots.header?.(), slots.default?.()]) }),
    NuxtLink: Vue.defineComponent({ props: ['to'], setup: (props, { slots }) => () => Vue.h('a', { href: props.to }, slots.default?.()) }),
    DataTableFilterPanel: passthrough,
    DataTableSortMenu: Vue.defineComponent({ props: ['fields', 'sort', 'direction'], emits: ['change'], setup: (props, { emit }) => () => Vue.h('select', { 'aria-label': 'Ordenar resultados', 'onChange': (value: { sort: string, direction: string }) => emit('change', value) }, (props.fields as Array<{ value: string, label: string }>).map(field => Vue.h('option', { value: field.value }, field.label))) }),
    DataTableSortButton: Vue.defineComponent({ props: ['label'], emits: ['toggle'], setup: (props, { emit }) => () => Vue.h('button', { onClick: () => emit('toggle') }, props.label) }),
    UDropdownMenu: Vue.defineComponent({ props: ['items'], setup: (props, { slots }) => () => Vue.h('div', { 'data-menu-items': props.items }, slots.default?.()) }),
    UCollapsible: Vue.defineComponent({ setup(_props, { slots }) {
      const open = Vue.ref(false)
      return () => Vue.h('div', [Vue.h('div', { onClick: () => {
        open.value = !open.value
      } }, slots.default?.()), open.value ? slots.content?.() : null])
    } }),
    UTable: Vue.defineComponent({ props: ['data', 'columns', 'columnVisibility'], setup: (props, { slots }) => () => Vue.h('table', [
      Vue.h('thead', Vue.h('tr', (props.columns as Array<{ id?: string, accessorKey?: string, header?: string }>).map(column => Vue.h('th', slots[`${column.id ?? column.accessorKey}-header`]?.() ?? column.header)))),
      Vue.h('tbody', (props.data as Array<Record<string, unknown>>).map(original => Vue.h('tr', (props.columns as Array<{ id?: string, accessorKey?: string }>).map(column => Vue.h('td', slots[`${column.id ?? column.accessorKey}-cell`]?.({ row: { original, getIsExpanded: () => false, toggleExpanded: () => {} } }) ?? String(original[column.accessorKey ?? ''] ?? ''))))))
    ]) })
  })
  await Vue.nextTick()
  await new Promise<void>(resolve => setImmediate(resolve))
  await Vue.nextTick()
  return { ...mounted, calls, navigation }
}

test('every operational list uses a compact unboxed heading, table and counted footer', async () => {
  for (const path of Object.keys(fixtures)) {
    const mounted = await mountList(path)
    try {
      assert.equal(mounted.nodes(node => node.props['data-page-card'] === true).length, 0, `${path} must not wrap the operational list in a card`)
      assert.equal(mounted.nodes(node => node.tag === 'h2').length, 1, `${path} has one list heading`)
      assert.equal(mounted.nodes(node => node.tag === 'table').length, 1, `${path} renders its operational rows as a table`)
      assert.equal(mounted.nodes(node => node.tag === 'footer').length, 1, `${path} retains authoritative result pagination`)
      assert.match(text(mounted.nodes(node => node.tag === 'footer')[0]!), /1 .*página 1 de 1/, `${path} uses the server total`)
    } finally { mounted.unmount() }
  }
})

test('company rows keep profile inspection visible and put permitted subscription actions in the row menu', async () => {
  const mounted = await mountList('empresas/index.vue')
  try {
    assert.ok(mounted.nodes(node => node.tag === 'button' && text(node).includes('Ver cadastro')).length)
    const menus = mounted.nodes(node => Array.isArray(node.props['data-menu-items']))
    const items = menus.flatMap(node => (node.props['data-menu-items'] as Array<unknown>).flat()) as Array<{ label: string, to?: string, onSelect?: () => void }>
    assert.ok(items.some(item => item.label === 'Ver assinatura' && item.to === '/gestao-erp/assinaturas/empresas/804'))
    assert.ok(items.some(item => item.label === 'Adicionar assinatura' && typeof item.onSelect === 'function'))
    for (const [label, section] of [['Gerenciar empresa', 'company'], ['Gerenciar assinatura', 'subscription'], ['Usuários e acessos', 'members'], ['Faturas da empresa', 'invoices'], ['Histórico', 'history']]) {
      assert.ok(items.some(item => item.label === label && item.to === `/gestao-erp/assinaturas/empresas/804?secao=${section}`), label)
    }
    assert.equal(mounted.nodes(node => node.tag === 'button' && /Ver assinatura|Adicionar assinatura/.test(text(node))).length, 0)
    assert.ok(mounted.nodes(node => node.props['aria-label'] === 'Identificação da empresa').length, 'company rows use a compact identity group like the members list')
    const profileButton = mounted.nodes(node => node.tag === 'button' && text(node).includes('Ver cadastro'))[0]!
    assert.equal(typeof profileButton.props.onClick, 'function')
    assert.ok(menus.some(node => (node.props['data-menu-items'] as Array<Array<{ label: string }>>).flat().some(item => item.label === 'Selecionar clientes da carteira')))
    assert.ok(menus.some(node => (node.props['data-menu-items'] as Array<Array<{ label: string }>>).flat().some(item => item.label === 'Cadastrar novo cliente')))
  } finally { mounted.unmount() }
})

test('company subscription menus never infer assignment permission from the relationship', async () => {
  for (const [relationship, canAssign, expected] of [
    ['unassigned', false, []],
    ['unassigned', true, ['Adicionar assinatura']],
    ['historical', false, ['Ver assinatura']],
    ['historical', true, ['Ver assinatura', 'Adicionar assinatura']]
  ] as const) {
    const mounted = await mountList('empresas/index.vue', { rows: [{ ...company, capabilities: { ...company.capabilities, relationship, can_assign: canAssign } }] })
    try {
      const items = mounted.nodes(node => Array.isArray(node.props['data-menu-items']))
        .flatMap(node => (node.props['data-menu-items'] as Array<unknown>).flat()) as Array<{ label: string }>
      assert.deepEqual(items.filter(item => ['Ver assinatura', 'Adicionar assinatura'].includes(item.label)).map(item => item.label), expected)
    } finally { mounted.unmount() }
  }
})

test('empty operational lists remain distinct from read denial and keep their search controls', async () => {
  for (const path of ['empresas/index.vue', 'assinaturas/index.vue', 'planos.vue', 'faturas/index.vue', 'recebimentos.vue']) {
    const mounted = await mountList(path, { rows: [] })
    try {
      assert.match(mounted.text(), /Nenhum/)
      if (path !== 'recebimentos.vue') assert.ok(mounted.nodes(node => node.tag === 'input').length, `${path} retains search after an empty result`)
      assert.equal(mounted.nodes(node => node.tag === 'table').length, 0)
    } finally { mounted.unmount() }
  }
  const denied = await mountList('empresas/index.vue', { canRead: false })
  try {
    assert.match(denied.text(), /não tem permissão/)
    assert.doesNotMatch(denied.text(), /Nenhuma empresa encontrada/)
    assert.equal(denied.calls.length, 0)
  } finally { denied.unmount() }
})

test('subscription ordering lives in table headers and resets server pagination', async () => {
  const mounted = await mountList('assinaturas/index.vue')
  try {
    assert.equal(mounted.nodes(node => node.props['aria-label'] === 'Ordenar resultados').length, 0)
    assert.doesNotMatch(mounted.text(), /Ver contratos/)
    const heading = mounted.nodes(node => node.tag === 'th' && text(node).includes('Empresa'))[0]!
    const ordering = mounted.nodes(node => node.tag === 'button' && node.parent === heading)[0]!
    assert.ok(ordering, 'sorting is available in the actual table header')
    mounted.navigation.value.page = 3
    ;(ordering.props.onClick as () => void)()
    await Vue.nextTick()
    await new Promise<void>(resolve => setImmediate(resolve))
    assert.deepEqual(mounted.calls.at(-1)?.params, { page: 1, per_page: 25, sort: 'name', direction: 'asc' })
    assert.match(mounted.text(), /20\/11\/2026/)
  } finally { mounted.unmount() }
})

test('subscription detail action uses a native single-line button and flexible table layout', async () => {
  const mounted = await mountList('assinaturas/index.vue')
  try {
    const details = mounted.nodes(node => node.tag === 'button' && text(node).includes('Ver detalhes'))[0]!
    assert.ok(details)
    assert.equal(details.props.size, 'sm')
    assert.equal(details.props.variant, 'ghost')
    assert.doesNotMatch(String(details.props.class), /min-h-|justify-center/)
    assert.equal(text(details), 'Ver detalhes')
    const table = mounted.nodes(node => node.tag === 'table')[0]!
    assert.doesNotMatch(String(table.props.ui), /table-fixed|border-separate/)
  } finally { mounted.unmount() }
})

test('operational lists keep sorting out of their toolbars', async () => {
  for (const path of Object.keys(fixtures)) {
    const mounted = await mountList(path)
    try {
      assert.equal(mounted.nodes(node => node.props['aria-label'] === 'Ordenar resultados').length, 0, path)
    } finally { mounted.unmount() }
  }
})

test('plan rows retain exact money, cycle, revision and module disclosure without per-plan cards', async () => {
  const mounted = await mountList('planos.vue')
  try {
    assert.equal(mounted.nodes(node => node.tag === 'table').length, 1)
    assert.match(mounted.text(), /1\.234\.567\.890\.123,45/)
    assert.match(mounted.text(), /3 meses/)
    assert.match(mounted.text(), /r2/)
    assert.doesNotMatch(mounted.text(), /Compras/)
    const trigger = mounted.nodes(node => node.tag === 'button' && node.props['aria-label'] === 'Ver módulos do plano')[0]
    assert.ok(trigger)
    assert.equal(typeof trigger.parent?.props.onClick, 'function')
    ;(trigger.parent!.props.onClick as () => void)()
    await Vue.nextTick()
    assert.match(mounted.text(), /Compras/)
  } finally { mounted.unmount() }
})
