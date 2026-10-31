import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { defineComponent, h, onMounted, resolveComponent, useTemplateRef } from 'vue'
import { compileVueComponent, mountVueComponent } from './helpers/compileVueComponent.ts'

const component = compileVueComponent('components/customers/ClientPortfolioEmpty.vue')
const EmptyBoundary = defineComponent({
  props: ['title', 'description', 'icon'],
  setup: (props, { slots }) => () => h('section', [
    h('h2', String(props.title)), h('p', String(props.description)), slots.actions?.()
  ])
})

function mount(props: Record<string, unknown> = {}) {
  return mountVueComponent(component, { UEmpty: EmptyBoundary }, {
    document: 'certificate', status: 'expiring', portfolioTotal: 1,
    search: '', hasFilters: false, canManageClients: true, ...props
  })
}

it('renders the portfolio empty content beneath the same table headers with selection disabled', (t) => {
  const table = compileVueComponent('components/customers/ClientPortfolioTable.vue', {
    h, onMounted, resolveComponent, useTemplateRef
  }, {
    '@vueuse/core': { useInfiniteScroll() {} }
  })
  // Nuxt UI owns the empty spanning row; keep that boundary while exercising
  // the real portfolio component's headers and forwarding of its empty slot.
  const EmptyTableBoundary = defineComponent({
    props: ['columns', 'data'],
    setup: (props, { slots }) => () => h('table', [
      h('thead', h('tr', (props.columns as Array<{ header?: unknown }>).map(column => h('th', typeof column.header === 'function' ? column.header() : String(column.header ?? ''))))),
      h('tbody', h('tr', h('td', { colspan: props.columns.length }, slots.empty?.())))
    ])
  })
  const Parent = defineComponent({
    setup: () => () => h(table, {
      rows: [], isLoading: false, loadingMore: false, canManageClients: true,
      selectedCount: 0, canLoadMore: false, columnVisibility: {}, rowSelection: {},
      headerState: false, selectingAll: false, matchingTotal: 0,
      sort: 'name', direction: 'asc', rowActions: () => []
    }, { empty: () => h(component, {
      document: 'certificate', status: 'expiring', portfolioTotal: 1,
      search: '', hasFilters: false, canManageClients: true
    }) })
  })
  const CellBoundary = defineComponent({ setup: () => () => h('span') })
  const view = mountVueComponent(Parent, {
    UTable: EmptyTableBoundary,
    UEmpty: EmptyBoundary,
    DataTableIdentity: CellBoundary,
    CustomersClientTags: CellBoundary,
    CustomersDocumentStatus: CellBoundary,
    DataTableRowActionsMenu: CellBoundary
  })
  t.after(view.unmount)
  assert.equal(view.nodes(node => node.tag === 'table').length, 1)
  assert.equal(view.nodes(node => node.tag === 'th').length, 8)
  assert.ok(view.nodes(node => node.props.label === 'Nome/Razão social').length)
  const cell = view.nodes(node => node.tag === 'td')[0]
  assert.equal(cell?.props.colspan, 8)
  assert.match(view.text(), /Nenhum certificado A1 a vencer/)
  const selectAll = view.nodes(node => node.props.role === 'checkbox')[0]
  assert.equal(selectAll?.props.disabled, true)
})

describe('client portfolio empty results', () => {
  it('announces confirmed empty results and links to the current document portfolio', (t) => {
    const view = mount()
    t.after(view.unmount)
    assert.equal(view.nodes(node => node.props.role === 'status').length, 1)
    assert.match(view.text(), /Nenhum certificado A1 a vencer/)
    const action = view.nodes(node => node.props.label === 'Ver todos os clientes')[0]
    assert.ok(action)
    assert.equal(action.props.to, '/customers/certificados')
    assert.equal(action.props.variant, 'ghost')
    assert.equal(view.nodes(node => node.props.label === 'Novo cliente').length, 0)
  })

  it('keeps an empty power-of-attorney view linked to its own document list', (t) => {
    const view = mount({ document: 'poa', status: 'expired' })
    t.after(view.unmount)
    assert.match(view.text(), /Nenhuma procuração e-CAC vencida/)
    assert.equal(view.nodes(node => node.props.label === 'Ver todos os clientes')[0]?.props.to, '/customers/procuracao')
  })

  it('offers independent working search and filter recovery rather than client creation', (t) => {
    let searches = 0
    let filters = 0
    const view = mount({
      search: 'Acme', hasFilters: true,
      onClearSearch: () => { searches++ },
      onClearFilters: () => { filters++ }
    })
    t.after(view.unmount)
    assert.match(view.text(), /Nenhum cliente encontrado/)
    for (const label of ['Limpar busca', 'Limpar filtros']) {
      const action = view.nodes(node => node.tag === 'button' && node.props.label === label)[0]
      assert.ok(action)
      ;(action.props.onClick as () => void)()
    }
    assert.equal(searches, 1)
    assert.equal(filters, 1)
    assert.equal(view.nodes(node => node.props.label === 'Novo cliente').length, 0)
  })

  it('lets a writer create the first client when the portfolio is actually empty', (t) => {
    let created = 0
    const view = mount({
      portfolioTotal: 0,
      onCreate: () => { created++ }
    })
    t.after(view.unmount)
    assert.match(view.text(), /Sua carteira ainda está vazia/)
    const action = view.nodes(node => node.tag === 'button' && node.props.label === 'Novo cliente')[0]
    assert.ok(action)
    ;(action.props.onClick as () => void)()
    assert.equal(created, 1)
  })

  it('offers refresh instead of creation to a read-only member', (t) => {
    let refreshed = 0
    const view = mount({
      portfolioTotal: 0,
      canManageClients: false,
      onRefresh: () => { refreshed++ }
    })
    t.after(view.unmount)
    assert.equal(view.nodes(node => node.props.label === 'Novo cliente').length, 0)
    const action = view.nodes(node => node.tag === 'button' && node.props.label === 'Atualizar')[0]
    assert.ok(action)
    ;(action.props.onClick as () => void)()
    assert.equal(refreshed, 1)
  })
})
