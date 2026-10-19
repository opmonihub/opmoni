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
        :ui="{ left: 'min-w-0', title: 'truncate', right: 'min-w-0 gap-1' }"
      >
        <template #leading>
          <UDashboardSidebarToggle aria-label="Abrir menu ERP" data-erp-sidebar-trigger />
        </template>
        <template #right>
          <CompanyMenu class="min-w-0 max-w-40 lg:hidden" />
          <UserMenu collapsed class="lg:hidden" />
        </template>
      </UDashboardNavbar>
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
