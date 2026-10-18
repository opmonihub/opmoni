<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'
import { erpCompanyHasPermission } from '~/utils/erpModulesNav'

definePageMeta({ layout: 'erp', middleware: 'auth' })

const route = useRoute()
const { currentCompany } = useAuth()
const showOwnerControls = computed(() => currentCompany.value?.status === 'active'
  && currentCompany.value.access_kind === 'direct'
  && currentCompany.value.role === 'owner')
const showEstablishmentControl = computed(() => currentCompany.value?.status === 'active'
  && erpCompanyHasPermission(currentCompany.value.permissions, 'erp.establishments.index'))

const links = computed(() => [[
  { label: 'Empresa', icon: 'i-lucide-building-2', to: '/erp/configuracoes/empresa', exact: true },
  ...(showEstablishmentControl.value
    ? [
        { label: 'Estabelecimentos', icon: 'i-lucide-map-pin-house', to: '/erp/configuracoes/estabelecimentos' }
      ]
    : []),
  ...(showOwnerControls.value
    ? [
        { label: 'Membros', icon: 'i-lucide-users-round', to: '/erp/configuracoes/membros' },
        { label: 'Escritório', icon: 'i-lucide-building', to: '/erp/configuracoes/escritorio' }
      ]
    : []),
  { label: 'Taxas', icon: 'i-lucide-percent', to: '/erp/configuracoes/taxas' },
  { label: 'Produto', icon: 'i-lucide-package', to: '/erp/configuracoes/produto' },
  { label: 'Vendas', icon: 'i-lucide-shopping-cart', to: '/erp/configuracoes/vendas' },
  { label: 'Compras', icon: 'i-lucide-truck', to: '/erp/configuracoes/compras' },
  { label: 'Sistema', icon: 'i-lucide-sliders-horizontal', to: '/erp/configuracoes/sistema' },
  { label: 'Prefixos', icon: 'i-lucide-hash', to: '/erp/configuracoes/prefixos' },
  { label: 'E-mail', icon: 'i-lucide-mail', to: '/erp/configuracoes/email' },
  { label: 'SMS', icon: 'i-lucide-message-square', to: '/erp/configuracoes/sms' },
  { label: 'Pontos', icon: 'i-lucide-gift', to: '/erp/configuracoes/pontos' },
  { label: 'Módulos', icon: 'i-lucide-puzzle', to: '/erp/configuracoes/modulos' },
  { label: 'Rótulos', icon: 'i-lucide-tags', to: '/erp/configuracoes/rotulos' }
]] satisfies NavigationMenuItem[][])
</script>

<template>
  <UDashboardPanel id="erp-configuracoes">
    <template #header>
      <UDashboardNavbar title="Configurações da empresa">
        <template #toggle>
          <UDashboardSidebarToggle aria-label="Abrir menu ERP" />
        </template>
      </UDashboardNavbar>
      <UDashboardToolbar>
        <UNavigationMenu :items="links" highlight class="-mx-1 flex-1 overflow-x-auto" />
      </UDashboardToolbar>
    </template>
    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <NuxtPage :key="route.fullPath" />
      </div>
    </template>
  </UDashboardPanel>
</template>
