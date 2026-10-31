<script setup lang="ts">
import type { PageCardProps } from '@nuxt/ui'
import { panelCardUi, panelToolbarClass } from '~/components/data-table/panel'
import { pageHeadingActionsClass, pageHeadingClass } from '~/utils/pageShell'

/**
 * The panel list page shell: a naked horizontal header card (title, description,
 * one page-level action) above a bordered `UPageCard` whose header slot is the
 * list toolbar — search, filters, tabs.
 *
 * Six Admin lists and the two Equipe lists each spelled this out inline, which is
 * why the header card, the `:ui` override and the toolbar row had to be kept
 * aligned by hand across eight files. The header card in particular is a
 * DESIGN.md "card-page-subtle" with `mb-4`, and it was being re-declared with a
 * slightly different action-button alignment (`w-fit lg:ms-auto`) each time.
 *
 * The default slot is the panel body *unpadded*: the padded body is
 * `panelBodyClass` from `./panel`, applied by the page, because some panels
 * (Admin › Suporte) swap a list for a table and own their own padding.
 *
 * ```vue
 * <DataTablePanelList title="Contas" description="Empresas que usam o opmoni.">
 *   <template #action><UButton label="Nova conta" icon="i-lucide-plus" @click="open" /></template>
 *   <template #toolbar><UInput v-model="q" class="max-w-sm" icon="i-lucide-search" /></template>
 *   <div :class="panelBodyClass">
 *     <UTable :data="rows" :columns="columns" :ui="panelTableUi" />
 *   </div>
 * </DataTablePanelList>
 * ```
 */
withDefaults(defineProps<{
  title: string
  description?: string
  hideHeading?: boolean
  ui?: {
    heading?: PageCardProps['ui']
    card?: PageCardProps['ui']
    toolbar?: string
  }
}>(), {
  description: undefined,
  hideHeading: false
})
</script>

<template>
  <UPageCard
    v-if="!hideHeading || $slots.action"
    :title="hideHeading ? undefined : title"
    :description="hideHeading ? undefined : description"
    variant="naked"
    orientation="horizontal"
    :ui="{ root: pageHeadingClass, ...ui?.heading }"
  >
    <template v-if="$slots.title" #title>
      <slot name="title" />
    </template>
    <div v-if="$slots.action" :class="pageHeadingActionsClass">
      <slot name="action" />
    </div>
  </UPageCard>

  <UPageCard
    variant="subtle"
    :ui="{ ...panelCardUi, ...ui?.card }"
  >
    <template v-if="$slots.toolbar" #header>
      <div :class="ui?.toolbar ?? panelToolbarClass">
        <slot name="toolbar" />
      </div>
    </template>

    <slot />
  </UPageCard>
</template>
