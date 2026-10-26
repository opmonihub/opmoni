<script setup lang="ts">
import { erpWorkspaceDirectLinks } from '~/utils/erpWorkspaceNav'

definePageMeta({ layout: 'erp', middleware: 'auth' })

const route = useRoute()
const { currentCompany } = useAuth()
const links = computed(() => erpWorkspaceDirectLinks('configuracoes', route.path, currentCompany.value))
// Company reconciliation and tax-default review need room for compared facts.
const settingsWidth = computed(() => ['/erp/configuracoes/empresa', '/erp/configuracoes/sistema'].includes(route.path.replace(/\/$/, ''))
  ? 'max-w-5xl'
  : 'max-w-2xl')
</script>

<template>
  <UDashboardPanel id="erp-configuracoes" :ui="{ body: 'lg:py-12' }">
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
      <div :class="['mx-auto w-full space-y-5', settingsWidth]">
        <NuxtPage />
      </div>
    </template>
  </UDashboardPanel>
</template>
