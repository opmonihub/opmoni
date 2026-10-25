<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const { currentAccount } = useAuth()
const pageTabs: NavigationMenuItem[][] = [[{
  label: 'Ofertas',
  icon: 'i-lucide-tags',
  to: '/gestao-erp/planos',
  active: route.path === '/gestao-erp/planos'
}, {
  label: 'Clientes da carteira',
  icon: 'i-lucide-users',
  to: '/gestao-erp/clientes',
  active: route.path === '/gestao-erp/clientes'
}, {
  label: 'Assinaturas',
  icon: 'i-lucide-file-key',
  to: '/gestao-erp/assinaturas',
  active: route.path.startsWith('/gestao-erp/assinaturas')
}]]

if (route.path === '/gestao-erp')
  void navigateTo('/gestao-erp/planos', { replace: true })
</script>

<template>
  <UDashboardPanel id="gestao-erp" :ui="{ body: 'min-h-0 flex-1 gap-0 overflow-y-auto p-3 sm:p-4 lg:p-5' }">
    <template #header>
      <UDashboardNavbar title="Gestão ERP">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <span v-if="currentAccount" class="hidden text-sm text-muted sm:inline">{{ currentAccount.name }}</span>
        </template>
      </UDashboardNavbar>
      <UDashboardToolbar>
        <template #left>
          <UNavigationMenu :items="pageTabs" highlight class="-mx-1 min-w-0 flex-1" />
        </template>
      </UDashboardToolbar>
    </template>

    <template #body>
      <NuxtPage />
    </template>
  </UDashboardPanel>
</template>
