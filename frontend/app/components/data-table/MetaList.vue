<script setup lang="ts">
import { metaListGridClass, metaListStackClass, metaListToneClass } from '~/utils/metaList'

export type MetaListItem = {
  /** The fact's name, in the product's own words. */
  label: string
  value: string | number
  /** Tabular figures — dates, counts, ids, ratios. */
  mono?: boolean
  /** Overrides the value colour, e.g. a non-zero failure count. */
  tone?: keyof typeof metaListToneClass
  /** Clamp to one line, keeping the label out of the flex basis. */
  truncate?: boolean
  /** Drop the row from the flow instead of an `v-if` at the call site. */
  when?: boolean
}

const props = withDefaults(defineProps<{
  items: MetaListItem[]
  /**
   * `grid` puts the label above the value (facts read as a block);
   * `stack` puts them on one line, label left and value right (a summary you
   * scan down the edge of).
   */
  layout?: 'grid' | 'stack'
  /** Responsive track count for `grid`; ignored by `stack`. */
  columns?: string
}>(), {
  layout: 'grid',
  columns: 'grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2 xl:grid-cols-3'
})

const visible = computed(() => props.items.filter(item => item.when !== false))

/** Resolved here so the `default` fallback stays out of the class binding. */
function toneOf(item: MetaListItem) {
  return metaListToneClass[item.tone ?? 'default']
}
</script>

<template>
  <dl :class="[layout === 'grid' ? metaListGridClass : metaListStackClass, layout === 'grid' && columns]">
    <div
      v-for="item in visible"
      :key="item.label"
      :class="layout === 'grid' ? 'min-w-0' : metaListStackRowClass"
    >
      <dt :class="layout === 'grid' ? 'text-xs text-muted' : 'shrink-0 text-muted'">
        {{ item.label }}
      </dt>
      <dd
        :class="[
          layout === 'grid' ? 'mt-0.5 text-sm' : 'min-w-0 text-right',
          item.truncate && 'truncate',
          item.mono && 'tabular-nums',
          toneOf(item)
        ]"
      >
        {{ item.value }}
      </dd>
    </div>
  </dl>
</template>
