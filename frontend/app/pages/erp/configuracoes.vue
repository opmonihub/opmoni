<script setup lang="ts">
import { erpPageBodyClass } from '~/components/erp/erpPresentation'
import { erpWorkspaceDirectLinks } from '~/utils/erpWorkspaceNav'

definePageMeta({ layout: 'erp', middleware: 'auth' })

const route = useRoute()
const { currentCompany } = useAuth()
const links = computed(() => erpWorkspaceDirectLinks('configuracoes', route.path, currentCompany.value))
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
      <ErpWorkspaceDestinationToolbar :items="links" aria-label="Navegação de configurações" />
    </template>
    <template #body>
      <div :class="['mx-auto w-full max-w-4xl', erpPageBodyClass]">
        <NuxtPage :key="route.fullPath" />
      </div>
    </template>
  </UDashboardPanel>
</template>
