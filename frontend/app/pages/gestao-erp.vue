<script setup lang="ts">
import { pageListMeasureClass } from '~/utils/pageShell'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const isOverviewRoute = computed(() => route.path === '/gestao-erp')
const isInvoicePrintRoute = computed(() => /^\/gestao-erp\/faturas\/[1-9]\d*\/imprimir$/.test(route.path))
</script>

<template>
  <NuxtPage v-if="isInvoicePrintRoute" />
  <main v-else aria-label="Gestão comercial ERP" class="flex min-h-0 min-w-0 flex-1">
    <UDashboardPanel id="gestao-erp" :ui="{ body: isOverviewRoute ? 'min-h-0 min-w-0 flex-1 gap-0 overflow-y-auto p-4 sm:p-6' : 'min-h-0 min-w-0 flex-1 gap-0 overflow-y-auto p-4 sm:p-6 lg:p-8' }">
      <template #header>
        <UDashboardNavbar title="Gestão ERP" :ui="{ title: 'text-base font-semibold' }">
          <template #leading>
            <UDashboardSidebarCollapse />
          </template>
          <template #right>
            <UButton
              v-if="!['/gestao-erp/empresas', '/gestao-erp/empresas/nova'].includes(route.path)"
              to="/gestao-erp/empresas/nova"
              icon="i-lucide-plus"
              color="neutral"
              size="sm"
            >
              Nova empresa
            </UButton>
          </template>
        </UDashboardNavbar>
      </template>

      <template #body>
        <div :class="isOverviewRoute ? 'w-full min-w-0' : pageListMeasureClass">
          <NuxtPage />
        </div>
      </template>
    </UDashboardPanel>
  </main>
</template>
