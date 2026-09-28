<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import { panelRowActionsClass, panelRowActionsTriggerClass } from '~/components/data-table/panel'

/**
 * `UDropdownMenu` takes a flat list or a list of groups; a second level is a
 * separator row and the padding that insets each group. InboxMail depends on
 * that grouping to break message actions from conversation actions, so the prop
 * accepts both shapes rather than flattening the one call site that needs them.
 */
export type RowActionsItems = DropdownMenuItem[] | DropdownMenuItem[][]

/**
 * The trailing row-overflow menu: an end-aligned `UDropdownMenu` behind a neutral
 * ghost ellipsis button.
 *
 * Eight call sites spelled this out, in two shapes — bare inside a list row, or
 * wrapped in `div.text-right` with `ml-auto` when it sits in a table cell. The
 * `aria-label` was required at the three Admin call sites and missing at the
 * others, which left the button announced only as "button"; making `label`
 * required here is the point of the component.
 *
 * `flush` wraps in the cell wrapper. `trigger` lets a page react to the open
 * (Carteira uses it to remember focus so the menu can hand it back on close).
 */
withDefaults(defineProps<{
  items: RowActionsItems
  /** Accessible name for the trigger. Say whose row it belongs to. */
  label: string
  /** `true` inside a table cell: pushes the trigger flush right. */
  flush?: boolean
}>(), {
  flush: false
})

defineEmits<{
  trigger: []
}>()
</script>

<template>
  <div v-if="flush" :class="panelRowActionsClass">
    <UDropdownMenu :items="items" :content="{ align: 'end' }">
      <UButton
        icon="i-lucide-ellipsis-vertical"
        color="neutral"
        variant="ghost"
        :class="panelRowActionsTriggerClass"
        :aria-label="label"
        @click="$emit('trigger')"
      />
    </UDropdownMenu>
  </div>

  <UDropdownMenu v-else :items="items" :content="{ align: 'end' }">
    <UButton
      icon="i-lucide-ellipsis-vertical"
      color="neutral"
      variant="ghost"
      :aria-label="label"
      @click="$emit('trigger')"
    />
  </UDropdownMenu>
</template>
