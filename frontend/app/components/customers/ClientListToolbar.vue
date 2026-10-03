<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'
import type { DataTableFilterModel } from '~/components/data-table/filter-model'
import { sheetToolbarUi } from '~/components/data-table/sheet'

defineProps<{
  statusTabs: NavigationMenuItem[][]
  documentStatus: string
  search: string
  filterModels: DataTableFilterModel[]
  canManageClients: boolean
}>()

const emit = defineEmits<{
  'apply-saved-filter': [value: { q: string, filters: DataTableFilterModel[] }]
  'open-tags': [intent: 'selection' | 'catalog', focusCreate?: boolean]
}>()
</script>

<template>
  <UDashboardToolbar
    class="hidden min-w-0 md:flex"
    :ui="sheetToolbarUi"
  >
    <template #left>
      <UNavigationMenu
        :items="statusTabs"
        highlight
        class="-mx-1 min-w-0 flex-1"
        :ui="{ root: 'min-w-0', list: 'min-w-0' }"
      >
        <template #item-leading="{ item }">
          <UIcon
            v-if="item.icon"
            :name="item.icon"
            class="size-5 shrink-0"
            :class="item.iconClass"
          />
        </template>
      </UNavigationMenu>
    </template>
    <template #right>
      <CustomersClientListMenu
        :search="search"
        :filters="filterModels"
        :show-saved="documentStatus === 'all'"
        :can-manage-tags="canManageClients"
        @apply="emit('apply-saved-filter', $event)"
        @open-tags="(intent, focusCreate) => emit('open-tags', intent, focusCreate)"
      />
    </template>
  </UDashboardToolbar>
</template>
