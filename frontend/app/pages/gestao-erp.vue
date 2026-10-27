<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const isInvoicePrintRoute = computed(() => /^\/gestao-erp\/faturas\/[1-9]\d*\/imprimir$/.test(route.path))
const pageTabs = computed<NavigationMenuItem[][]>(() => [[{
  label: 'Visão geral',
  icon: 'i-lucide-layout-dashboard',
  to: '/gestao-erp',
  active: route.path === '/gestao-erp'
}, {
  label: 'Planos',
  icon: 'i-lucide-tags',
  to: '/gestao-erp/planos',
  active: route.path === '/gestao-erp/planos'
}, {
  label: 'Clientes ERP',
  icon: 'i-lucide-users',
  to: '/gestao-erp/clientes',
  active: route.path === '/gestao-erp/clientes' || route.path.startsWith('/gestao-erp/clientes/')
}, {
  label: 'Assinaturas',
  icon: 'i-lucide-file-key',
  to: '/gestao-erp/assinaturas',
  active: route.path === '/gestao-erp/assinaturas' || route.path.startsWith('/gestao-erp/assinaturas/')
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
}]])
</script>

<template>
  <NuxtPage v-if="isInvoicePrintRoute" />
  <main v-else aria-label="Gestão comercial ERP" class="flex min-h-0 min-w-0 flex-1">
    <UDashboardPanel id="gestao-erp" :ui="{ body: 'min-h-0 min-w-0 flex-1 gap-0 overflow-y-auto p-4 sm:p-6 lg:p-8' }">
      <template #header>
        <UDashboardNavbar title="Gestão ERP">
          <template #leading>
            <UDashboardSidebarCollapse />
          </template>
          <template #right>
            <UButton
              v-if="!['/gestao-erp/clientes', '/gestao-erp/clientes/novo'].includes(route.path)"
              to="/gestao-erp/clientes/novo"
              icon="i-lucide-plus"
              size="sm"
            >
              Cadastrar cliente
            </UButton>
          </template>
        </UDashboardNavbar>
        <UDashboardToolbar :ui="{ left: 'min-w-0 flex-1', root: 'min-w-0' }">
          <template #left>
            <USelect
              :model-value="String(pageTabs.flat().find(item => item.active)?.to ?? '')"
              :items="pageTabs.flat().map(item => ({ label: item.label, value: String(item.to), icon: item.icon }))"
              aria-label="Seção da gestão ERP"
              class="w-full xl:hidden"
              @update:model-value="value => navigateTo(value)"
            />
            <UNavigationMenu
              as="nav"
              aria-label="Navegação comercial ERP"
              :items="pageTabs"
              highlight
              class="-mx-1 hidden min-w-0 flex-1 xl:flex"
            />
          </template>
        </UDashboardToolbar>
      </template>

      <template #body>
        <div class="mx-auto w-full max-w-[100rem]">
          <NuxtPage />
        </div>
      </template>
    </UDashboardPanel>
  </main>
</template>
