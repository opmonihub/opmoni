<script setup lang="ts">
import { fiscalTabs } from '~/utils/fiscalNav'

definePageMeta({ middleware: 'auth' })

const route = useRoute()

/**
 * O shell carrega a navegação, nunca o dado. Quem busca o resumo e a lista são
 * as páginas filhas: uma página que busca no shell busca de novo a cada troca
 * de aba, e o painel voltaria a ser o do Documentos.
 */
const title = computed(() => {
  const current = fiscalTabs(route.path)[0]?.find(item => item.active)
  return current?.label ?? 'Fiscal'
})

const pageTabs = computed(() => fiscalTabs(route.path))
</script>

<template>
  <UDashboardPanel id="fiscal" :ui="{ body: 'min-h-0 flex-1 gap-0 overflow-hidden p-0 sm:p-0' }">
    <template #header>
      <UDashboardNavbar :title="title">
        <template #leading>
          <UDashboardSidebarCollapse />
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
