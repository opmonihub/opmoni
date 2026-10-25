<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const { currentAccount } = useAuth()
const isInvoicePrintRoute = computed(() => /^\/gestao-erp\/faturas\/[1-9]\d*\/imprimir$/.test(route.path))
const pageTabs: NavigationMenuItem[][] = [[{
  label: 'Visão geral',
  icon: 'i-lucide-layout-dashboard',
  to: '/gestao-erp',
  active: route.path === '/gestao-erp'
}, {
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
}, {
  label: 'Faturas',
  icon: 'i-lucide-receipt',
  to: '/gestao-erp/faturas',
  active: route.path === '/gestao-erp/faturas' || route.path.startsWith('/gestao-erp/faturas/')
}, {
  label: 'Recebimentos',
  icon: 'i-lucide-banknote',
  to: '/gestao-erp/recebimentos',
  active: route.path === '/gestao-erp/recebimentos'
}, {
  label: 'Configurações',
  icon: 'i-lucide-settings',
  to: '/gestao-erp/configuracoes',
  active: route.path === '/gestao-erp/configuracoes'
}]]
</script>

<template>
  <NuxtPage v-if="isInvoicePrintRoute" />
  <main v-else aria-label="Gestão comercial ERP" class="flex min-h-0 min-w-0 flex-1">
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
            <UNavigationMenu
              as="nav"
              aria-label="Navegação comercial ERP"
              :items="pageTabs"
              highlight
              class="-mx-1 min-w-0 flex-1"
            />
          </template>
        </UDashboardToolbar>
      </template>

      <template #body>
        <NuxtPage />
      </template>
    </UDashboardPanel>
  </main>
</template>
