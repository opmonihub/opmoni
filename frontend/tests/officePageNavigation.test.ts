import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { evaluateVueBindings, vueSource } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

for (const [path, names, columns] of [
  ['pages/work/tarefas.vue', ['navigation', 'search', 'filterModels', 'sorting', 'tablePage', 'viewMode', 'scopeMode'], 'status'],
  ['pages/work/clientes.vue', ['navigation', 'search', 'filterModels', 'sorting'], 'department'],
  ['pages/work/processos/index.vue', ['navigation', 'search', 'filterModels', 'sorting'], 'template'],
  ['pages/fiscal/clientes.vue', ['navigation', 'search', 'filterModels', 'currentPage', 'pageSize'], 'modelo'],
  ['pages/equipe/index.vue', ['navigation', 'search', 'departmentFilter'], null],
  ['pages/equipe/departamentos.vue', ['navigation', 'search'], null],
  ['components/monitoring/MonitoringSheet.vue', ['navigation', 'search', 'tagFilter', 'page'], null]
] as [string, string[], string | null][]) {
  test(`${path} restores its real criteria bindings and clears them on session change`, () => {
    const states = new Map<string, Vue.Ref>()
    const useState = (key: string, initial: () => unknown) => {
      if (!states.has(key)) states.set(key, Vue.ref(initial()))
      return states.get(key)
    }
    const foundation = loadAppComposable<Record<string, (...args: unknown[]) => unknown>>('composables/useListNavigationState.ts', { useState })
    const office = loadAppComposable<Record<string, (...args: unknown[]) => unknown>>('composables/useOfficeTableNavigation.ts', { ...foundation, computed: Vue.computed })
    const bindings = { ...foundation, ...office, computed: Vue.computed, props: { obligation: { slug: 'declaracoes/dctf' }, situacao: null } }
    const open = () => evaluateVueBindings(vueSource(path), names, bindings) as Record<string, Vue.Ref>
    const firstScope = Vue.effectScope()
    const first = firstScope.run(open)!
    first.search!.value = 'Fechamento'
    if (columns) first.filterModels!.value = [{ columnId: columns, operator: 'is not', values: ['1'] }]
    if (first.tablePage) first.tablePage.value = 3
    if (first.currentPage) first.currentPage.value = 3
    if (first.pageSize) first.pageSize.value = 50
    if (first.departmentFilter) first.departmentFilter.value = 12
    if (first.tagFilter) first.tagFilter.value = [12]
    if (first.viewMode) first.viewMode.value = 'table'
    firstScope.stop()
    const nextScope = Vue.effectScope()
    try {
      const next = nextScope.run(open)!
      assert.equal(next.search!.value, 'Fechamento')
      if (columns) assert.deepEqual(next.filterModels!.value, [{ columnId: columns, operator: 'is not', values: ['1'] }])
      if (next.tablePage) assert.equal(next.tablePage.value, 3)
      if (next.currentPage) assert.equal(next.currentPage.value, 3)
      if (next.pageSize) assert.equal(next.pageSize.value, 50)
      if (next.departmentFilter) assert.equal(next.departmentFilter.value, 12)
      if (next.tagFilter) assert.deepEqual(next.tagFilter.value, [12])
      if (next.viewMode) assert.equal(next.viewMode.value, 'table')
      states.get('access-session-generation')!.value++
      assert.equal(next.search!.value, '')
      if (columns) assert.deepEqual(next.filterModels!.value, [])
      if (next.tablePage) assert.equal(next.tablePage.value, 1)
      if (next.currentPage) assert.equal(next.currentPage.value, 1)
      if (next.departmentFilter) assert.equal(next.departmentFilter.value, null)
      if (next.tagFilter) assert.deepEqual(next.tagFilter.value, [])
      if (next.viewMode) assert.equal(next.viewMode.value, 'board')
    } finally { nextScope.stop() }
  })
}
