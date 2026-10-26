import { h, type App } from 'vue'
import type { DataTableFilterModel } from '../../app/components/data-table/filter-model.ts'

/** Presentation-only Nuxt UI components; operating controls keep each suite's own stubs. */
export function registerOfficeErpPresentationStubs(app: App) {
  app.component('UIcon', { setup: () => () => h('span') })
  app.component('UDropdownMenu', { setup: (_props, { slots }) => () => h('div', slots.default?.()) })
  app.component('UCollapsible', { setup: (_props, { slots }) => () => h('div', [slots.default?.(), slots.content?.()]) })
  app.component('DataTableFilterPanel', {
    props: ['columns', 'modelValue'],
    emits: ['update:modelValue'],
    setup: (props, { slots, emit }) => () => h('div', {
      'data-filter-panel': true,
      'filterColumns': props.columns,
      'onApplyFilters': (filters: DataTableFilterModel[]) => emit('update:modelValue', filters)
    }, [slots.default?.(), slots.trailing?.()])
  })
}
