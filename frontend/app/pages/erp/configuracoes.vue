<script setup lang="ts">
import { erpWorkspaceSettingsGroups } from '~/utils/erpWorkspaceNav'

definePageMeta({ layout: 'erp', middleware: 'auth' })

const route = useRoute()
const { currentCompany } = useAuth()
const links = computed(() => erpWorkspaceSettingsGroups(route.path, currentCompany.value))
</script>

<template>
  <UDashboardPanel id="erp-configuracoes">
    <template #header>
      <UDashboardNavbar title="Configurações da empresa">
        <template #leading>
          <UDashboardSidebarCollapse aria-label="Recolher menu ERP" />
        </template>
        <template #toggle>
          <UDashboardSidebarToggle aria-label="Abrir menu ERP" data-erp-sidebar-trigger />
        </template>
      </UDashboardNavbar>
      <UDashboardToolbar>
        <UNavigationMenu :items="links" highlight class="-mx-1 w-full flex-1 flex-wrap" />
      </UDashboardToolbar>
    </template>
    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <NuxtPage :key="route.fullPath" />
      </div>
    </template>
  </UDashboardPanel>
</template>
