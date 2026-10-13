<script setup lang="ts">
import { adminNavbarTitle, adminTabs } from '~/utils/adminNav'

definePageMeta({
  middleware: ['auth', 'super-admin']
})

const route = useRoute()

const navbarTitle = computed(() => adminNavbarTitle(route.path))
const links = computed(() => adminTabs(route.path))
</script>

<template>
  <NuxtPage v-if="route.path === '/admin/contas'" />
  <UDashboardPanel v-else id="admin" :ui="{ body: 'min-h-0 flex-1 gap-0 overflow-hidden p-4 sm:p-6' }">
    <template #header>
      <UDashboardNavbar :title="navbarTitle">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <UNavigationMenu :items="links" highlight class="-mx-1 min-w-0 flex-1" />
      </UDashboardToolbar>
    </template>

    <template #body>
      <NuxtPage />
    </template>
  </UDashboardPanel>
</template>
