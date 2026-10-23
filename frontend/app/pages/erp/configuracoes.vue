<script setup lang="ts">
import { erpPageBodyClass } from '~/components/erp/erpPresentation'
import { erpWorkspaceSettingsGroups } from '~/utils/erpWorkspaceNav'

definePageMeta({ layout: 'erp', middleware: 'auth' })

const route = useRoute()
const { currentCompany } = useAuth()
const links = computed(() => erpWorkspaceSettingsGroups(route.path, currentCompany.value))
</script>

<template>
  <UDashboardPanel id="erp-configuracoes" :ui="{ body: 'p-0 sm:p-0' }">
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
      <div :class="['mx-auto w-full max-w-4xl', erpPageBodyClass]">
        <NuxtPage :key="route.fullPath" />
      </div>
    </template>
  </UDashboardPanel>
</template>
