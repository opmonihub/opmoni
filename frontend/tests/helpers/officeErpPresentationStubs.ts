import { cloneVNode, h, ref, type App, type VNode } from 'vue'
import type { DataTableFilterModel } from '../../app/components/data-table/filter-model.ts'

/** Presentation-only Nuxt UI components; operating controls keep each suite's own stubs. */
export function registerOfficeErpPresentationStubs(app: App) {
  app.component('UIcon', { setup: () => () => h('span') })
  app.component('UDropdownMenu', { setup: (_props, { slots }) => () => h('div', slots.default?.()) })
  app.component('UCollapsible', {
    props: ['defaultOpen', 'disabled'],
    setup(props, { slots }) {
      const open = ref(props.defaultOpen === true)
      return () => {
        const trigger = slots.default?.()
        const children = (Array.isArray(trigger) ? trigger : trigger ? [trigger] : []) as VNode[]
        const first = children[0]
        const triggerWithState = first
          ? cloneVNode(first, {
              'aria-expanded': open.value,
              'onClick': () => { if (!props.disabled) open.value = !open.value }
            })
          : null
        return h('div', [triggerWithState, ...(open.value ? slots.content?.() ?? [] : [])])
      }
    }
  })
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
