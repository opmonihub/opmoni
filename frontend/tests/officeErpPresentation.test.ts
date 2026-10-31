import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { renderToString } from 'vue/server-renderer'
import { compileVueComponent, evaluateVueBindings, mountVueComponent, vueSource } from './helpers/compileVueComponent.ts'
import { officeErpSidebarItem } from '../app/utils/sidebarNav.ts'

test('Company registration keeps Empresas selected in sidebar navigation', () => {
  const items = officeErpSidebarItem('/gestao-erp/empresas/nova').children
  assert.deepEqual(items.filter(item => item.active).map(item => item.to), ['/gestao-erp/empresas'])
})

test('column visibility preserves identity and actions while allowing secondary fields to be restored', async () => {
  const table = compileVueComponent('components/office-erp/OfficeErpDataTable.vue')
  let options: Array<{ label: string, checked: boolean, onUpdateChecked: (checked: boolean) => void }> = []
  const app = Vue.createSSRApp(table, {
    data: [{ id: 1, name: 'Cliente de teste', tax_id: 'synthetic' }],
    columns: [{ accessorKey: 'name', header: 'Cliente' }, { accessorKey: 'tax_id', header: 'CNPJ' }, { id: 'actions', header: '' }]
  })
  app.component('UDropdownMenu', Vue.defineComponent({
    props: ['items'],
    setup(props) {
      options = props.items
      return () => Vue.h('div')
    }
  }))
  app.component('UTable', Vue.defineComponent({ setup: () => () => Vue.h('table') }))
  app.component('UButton', Vue.defineComponent({ setup: () => () => Vue.h('button') }))
  app.component('UIcon', Vue.defineComponent({ setup: () => () => Vue.h('span') }))
  await renderToString(app)
  assert.deepEqual(options.map(option => option.label), ['CNPJ'])
  assert.equal(options[0]!.checked, true)
  assert.doesNotThrow(() => options[0]!.onUpdateChecked(false))
  assert.doesNotThrow(() => options[0]!.onUpdateChecked(true))
})

test('compact mobile tables retain sortable columns and essential billing terms', async (t) => {
  const component = compileVueComponent('components/office-erp/OfficeErpDataTable.vue', {}, {
    '@vueuse/core': { useMediaQuery: () => Vue.ref(true) }
  })
  let visibility: Record<string, boolean> = {}
  const view = mountVueComponent(component, {
    UTable: Vue.defineComponent({
      props: ['columnVisibility'],
      setup: props => () => {
        visibility = props.columnVisibility
        return Vue.h('table')
      }
    })
  }, {
    data: [{ id: 1 }], compact: true, mobileColumns: ['name', 'actions'],
    columns: [
      { accessorKey: 'name', header: 'Empresa', enableSorting: true },
      { accessorKey: 'registered_at', header: 'Cadastro', enableSorting: true },
      { accessorKey: 'status', header: 'Situação', enableSorting: true },
      { id: 'price', header: 'Valor e ciclo', enableHiding: false },
      { id: 'notes', header: 'Notas' }, { id: 'actions', header: '' }
    ]
  })
  t.after(view.unmount)
  await Vue.nextTick()
  assert.equal(visibility.registered_at, true, 'mobile sorting remains in the table header')
  assert.equal(visibility.status, true)
  assert.equal(visibility.price, true, 'essential billing data cannot disappear on mobile')
  assert.equal(visibility.notes, false)
})

test('expandable commercial rows use the supplied stable domain identity', async () => {
  const table = compileVueComponent('components/office-erp/OfficeErpDataTable.vue')
  let getRowId: ((row: { id: number }, index: number) => string) | undefined
  const app = Vue.createSSRApp(table, {
    data: [{ id: 804, name: 'Cliente de teste' }],
    columns: [{ accessorKey: 'name', header: 'Cliente' }],
    expandable: true,
    getRowId: (row: { id: number }) => String(row.id)
  })
  app.component('UDropdownMenu', Vue.defineComponent({ setup: () => () => Vue.h('div') }))
  app.component('UTable', Vue.defineComponent({
    props: ['getRowId'],
    setup(props) {
      getRowId = props.getRowId as typeof getRowId
      return () => Vue.h('table')
    }
  }))
  app.component('UButton', Vue.defineComponent({ setup: () => () => Vue.h('button') }))
  app.component('UIcon', Vue.defineComponent({ setup: () => () => Vue.h('span') }))
  await renderToString(app)
  assert.equal(getRowId?.({ id: 804 }, 0), '804')
})

test('office panel keeps contextual help beside its title rather than below the results', async () => {
  const panel = compileVueComponent('components/office-erp/OfficeErpPanel.vue')
  const app = Vue.createSSRApp({ render: () => Vue.h(panel, { title: 'Empresas', companyList: true }, {
    title: () => [Vue.h('span', 'Empresas'), Vue.h('button', { 'aria-label': 'Sobre empresas e assinaturas' }, '?')],
    default: () => Vue.h('table')
  }) })
  app.component('UPageCard', Vue.defineComponent({
    props: ['title', 'description'],
    setup: (props, { slots }) => () => Vue.h('section', [
      props.title ? Vue.h('h2', slots.title?.() ?? props.title) : null,
      slots.header?.(), slots.default?.()
    ])
  }))
  app.component('UButton', Vue.defineComponent({ setup: () => () => Vue.h('button') }))
  app.component('UPopover', Vue.defineComponent({ setup: (_props, { slots }) => () => Vue.h('div', slots.default?.()) }))
  const html = await renderToString(app)
  assert.match(html, /<h2\b[^>]*>[\s\S]*aria-label="Sobre empresas e assinaturas"[\s\S]*<\/h2>/)
})

test('list heading groups search and actions together before its results', async () => {
  const panel = compileVueComponent('components/office-erp/OfficeErpPanel.vue')
  const app = Vue.createSSRApp({ render: () => Vue.h(panel, { title: 'Assinaturas', list: true }, {
    toolbar: () => Vue.h('input', { 'aria-label': 'Buscar empresa' }),
    action: () => Vue.h('button', 'Nova assinatura'),
    default: () => Vue.h('table')
  }) })
  const html = await renderToString(app)
  const heading = html.match(/<header[^>]*>([\s\S]*?)<\/header>/)?.[1] ?? ''
  assert.match(heading, /Buscar empresa/)
  assert.match(heading, /Nova assinatura/)
  assert.match(heading, /Assinaturas/)
})

test('company header aligns title, search and main action without a separate toolbar row', async () => {
  const panel = compileVueComponent('components/office-erp/OfficeErpPanel.vue')
  const app = Vue.createSSRApp({ render: () => Vue.h(panel, { title: 'Empresas', companyList: true }, {
    toolbar: () => Vue.h('input', { 'aria-label': 'Buscar empresa' }),
    action: () => Vue.h('button', 'Novo cliente'),
    default: () => Vue.h('table')
  }) })
  const html = await renderToString(app)
  const controls = html.match(/<div[^>]*aria-label="Controles da lista"[^>]*>([\s\S]*?)<\/header>/)?.[1] ?? ''
  assert.match(controls, /Buscar empresa/)
  assert.match(controls, /Novo cliente/)
  assert.doesNotMatch(html, /col-span-2|items-stretch/)
})

test('form callers retain their existing panel-card composition and description', async () => {
  const panel = compileVueComponent('components/office-erp/OfficeErpPanel.vue')
  const app = Vue.createSSRApp({ render: () => Vue.h(panel, { title: 'Nova empresa', description: 'O cadastro não cria assinatura.' }, {
    action: () => Vue.h('button', 'Salvar cadastro'), default: () => Vue.h('form')
  }) })
  app.component('UPageCard', Vue.defineComponent({
    props: ['title', 'description'],
    setup: (props, { slots }) => () => Vue.h('article', [props.title ? Vue.h('h2', props.title) : null, props.description ? Vue.h('p', props.description) : null, slots.default?.()])
  }))
  app.component('UButton', Vue.defineComponent({ setup: () => () => Vue.h('button') }))
  app.component('UPopover', Vue.defineComponent({ setup: (_props, { slots }) => () => Vue.h('div', slots.default?.()) }))
  const html = await renderToString(app)
  assert.equal((html.match(/<article\b/g) ?? []).length, 2)
  assert.match(html, /O cadastro não cria assinatura\./)
  assert.match(html, /Salvar cadastro/)
  assert.match(html, /<form/)
})

test('initial pagination loading does not announce a fabricated empty result', async () => {
  const pagination = compileVueComponent('components/office-erp/OfficeErpPagination.vue')
  const app = Vue.createSSRApp(pagination, { total: 0, label: 'empresas', loading: true, page: 1, perPage: 25 })
  app.component('USelect', Vue.defineComponent({ setup: () => () => Vue.h('select') }))
  app.component('UPagination', Vue.defineComponent({ setup: () => () => Vue.h('nav') }))
  const html = await renderToString(app)
  assert.match(html, /Carregando resultados/)
  assert.doesNotMatch(html, /0 empresas/)
})

test('plan columns preserve cycle and revision access in compact tables', () => {
  const result = evaluateVueBindings(vueSource('pages/gestao-erp/planos.vue'), ['columns'], {})
  const columns = result.columns as Array<{ id?: string, accessorKey?: string, enableSorting?: boolean, enableHiding?: boolean }>
  assert.equal(columns.find(column => column.id === 'price')?.enableHiding, false)
  assert.equal(columns.find(column => column.accessorKey === 'status')?.enableSorting, true)
})

test('the Company list names its legal-name field', () => {
  const result = evaluateVueBindings(vueSource('pages/gestao-erp/empresas/index.vue'), ['columns'], {})
  const name = (result.columns as Array<{ accessorKey?: string, header: string }>).find(column => column.accessorKey === 'name')
  assert.equal(name?.header, 'Empresa')
})
