import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { renderToString } from 'vue/server-renderer'
import { compileVueComponent, evaluateVueBindings, vueSource } from './helpers/compileVueComponent.ts'

test('client registration keeps Clients selected in both navigation presentations', () => {
  const result = evaluateVueBindings(vueSource('pages/gestao-erp.vue'), ['pageTabs'], {
    computed: Vue.computed,
    route: { path: '/gestao-erp/clientes/novo' }
  })
  const items = (result.pageTabs as Vue.ComputedRef<Array<Array<{ to: string, active: boolean }>>>).value.flat()
  assert.deepEqual(items.filter(item => item.active).map(item => item.to), ['/gestao-erp/clientes'])
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
