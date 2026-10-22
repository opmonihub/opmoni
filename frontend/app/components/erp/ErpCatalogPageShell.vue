<script setup lang="ts">
defineProps<{
  title: string
  description?: string
}>()
</script>

<template>
  <UDashboardPanel :ui="{ body: 'p-0 sm:p-0' }">
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
      <UDashboardToolbar v-if="$slots.toolbar">
        <slot name="toolbar" />
      </UDashboardToolbar>
    </template>
    <template #body>
      <div class="space-y-5 p-4 sm:p-6">
        <p v-if="description" class="text-muted text-sm">
          {{ description }}
        </p>
        <slot />
      </div>
    </template>
  </UDashboardPanel>
</template>
