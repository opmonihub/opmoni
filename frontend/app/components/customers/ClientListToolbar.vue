<script setup lang="ts">
import type { DropdownMenuItem, NavigationMenuItem } from '@nuxt/ui'
import type { DataTableFilterModel } from '~/components/data-table/Filter.vue'
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

const tagMenuItems = [[{
  label: 'Criar tag',
  icon: 'i-lucide-plus',
  onSelect: () => emit('open-tags', 'catalog', true)
}, {
  label: 'Gerenciar tags',
  icon: 'i-lucide-library',
  onSelect: () => emit('open-tags', 'catalog')
}]] satisfies DropdownMenuItem[][]
</script>

<template>
  <UDashboardToolbar
    class="hidden min-w-0 md:flex"
    :ui="sheetToolbarUi"
  >
    <template #left>
      <div class="flex min-w-0 flex-1 items-center gap-1.5">
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
        <CustomersSavedFilters
          v-if="documentStatus === 'all'"
          :search="search"
          :filters="filterModels"
          @apply="emit('apply-saved-filter', $event)"
        />
      </div>
    </template>
    <template #right>
      <UDropdownMenu
        v-if="canManageClients"
        :items="tagMenuItems"
        :content="{ align: 'end' }"
      >
        <UButton
          label="Tags"
          icon="i-lucide-tags"
          trailing-icon="i-lucide-chevron-down"
          color="neutral"
          variant="ghost"
          aria-label="Opções de tags"
        />
      </UDropdownMenu>
    </template>
  </UDashboardToolbar>
</template>
