<script setup lang="ts">
import { erpModuleActive, erpModuleNavbarTitle, erpNavModules, erpVisibleNavModules } from '~/utils/erpModulesNav'

const route = useRoute()
const { currentCompany, currentAccount } = useAuth()
const open = ref(false)
const { closeForNavigation } = useErpSidebarNavigation(open)
const workspaceKey = computed(() => `${currentAccount.value?.id ?? ''}:${currentCompany.value?.id ?? ''}:${currentCompany.value?.access_kind ?? ''}`)
// Operational modules, catalogs and settings provide their own native panel and header.
const pageOwnsPanel = computed(() => erpNavModules.some(module =>
  module.id !== 'overview' && erpModuleActive(route.path, module)
))

const companyGrants = computed(() => currentCompany.value?.permissions)

const links = computed(() => [[
  ...erpVisibleNavModules(companyGrants.value).map(module => ({
    label: module.label,
    icon: module.icon,
    to: module.to,
    active: erpModuleActive(route.path, module),
    onSelect: closeForNavigation
  }))
]])

const navbarTitle = computed(() => {
  if (currentCompany.value?.name)
    return currentCompany.value.name
  return erpModuleNavbarTitle(route.path)
})
</script>

<template>
  <UDashboardGroup unit="rem" data-erp-workspace>
    <UDashboardSidebar
      id="erp"
      v-model:open="open"
      collapsible
      resizable
      class="bg-elevated/25"
      :ui="{ footer: 'lg:border-t lg:border-default' }"
    >
      <template #header="{ collapsed, collapse }">
        <UButton
          v-if="collapsed"
          icon="i-lucide-factory"
          :aria-label="currentCompany?.name || 'Selecionar empresa'"
          color="neutral"
          variant="ghost"
          @click="collapse(false)"
        />
        <WorkspaceContextMenu v-else class="w-full min-w-0" />
      </template>

      <template #default="{ collapsed }">
        <UNavigationMenu
          :collapsed="collapsed"
          :items="links[0]"
          orientation="vertical"
          :tooltip="collapsed"
          popover
        />
      </template>

      <template #footer="{ collapsed }">
        <UserMenu :collapsed="collapsed" />
      </template>
    </UDashboardSidebar>

    <slot v-if="pageOwnsPanel" :key="workspaceKey" />
    <UDashboardPanel v-else :ui="{ body: 'p-0 sm:p-0' }">
      <template #header>
        <UDashboardNavbar
          :title="navbarTitle"
          :ui="{ left: 'min-w-0', title: 'hidden truncate lg:block', right: 'min-w-0' }"
        >
          <template #toggle>
            <UDashboardSidebarToggle aria-label="Abrir menu ERP" data-erp-sidebar-trigger />
          </template>
          <template #right>
            <CompanyMenu class="min-w-0 max-w-40 lg:hidden" />
            <UserMenu collapsed class="lg:hidden" />
          </template>
        </UDashboardNavbar>
      </template>
      <template #body>
        <slot :key="workspaceKey" />
      </template>
    </UDashboardPanel>
  </UDashboardGroup>
</template>
