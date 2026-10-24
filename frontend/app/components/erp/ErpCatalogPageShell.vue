<script setup lang="ts">
import { erpPageBodyClass } from '~/components/erp/erpPresentation'
import type { NavigationMenuItem } from '@nuxt/ui'

defineProps<{
  title: string
  description?: string
  embedded?: boolean
  navigation?: NavigationMenuItem[]
}>()
</script>

<template>
  <section v-if="embedded" class="min-w-0 space-y-5" :aria-label="title">
    <div v-if="description || $slots.actions" class="flex min-w-0 items-start justify-between gap-3">
      <p v-if="description" class="text-muted text-sm">
        {{ description }}
      </p>
      <div v-if="$slots.actions" class="flex shrink-0 items-center gap-1">
        <slot name="actions" />
      </div>
    </div>
    <slot />
  </section>
  <UDashboardPanel v-else :ui="{ body: 'p-0 sm:p-0' }">
    <template #header>
      <UDashboardNavbar
        :title="title"
        :toggle="false"
        :ui="{ left: 'min-w-0 flex-1', title: 'min-w-0 truncate', right: 'min-w-0 shrink-0 gap-1' }"
      >
        <template #leading>
          <UDashboardSidebarCollapse aria-label="Recolher menu ERP" />
          <UDashboardSidebarToggle aria-label="Abrir menu ERP" data-erp-sidebar-trigger />
        </template>
        <template #right>
          <slot name="actions" />
          <CompanyMenu class="min-w-0 max-w-40 lg:hidden" />
          <UserMenu collapsed class="lg:hidden" />
        </template>
      </UDashboardNavbar>
      <ErpWorkspaceDestinationToolbar v-if="navigation" :items="navigation" aria-label="Navegação de cadastros" />
      <UDashboardToolbar v-else-if="$slots.toolbar">
        <slot name="toolbar" />
      </UDashboardToolbar>
    </template>
    <template #body>
      <div :class="erpPageBodyClass">
        <p v-if="description" class="text-muted text-sm">
          {{ description }}
        </p>
        <slot />
      </div>
    </template>
  </UDashboardPanel>
</template>
