<script setup lang="ts">
import { erpModuleActive, erpModuleNavbarTitle, erpNavModules } from '~/utils/erpModulesNav'
import { erpWorkspaceSearchGroups, erpWorkspaceSidebarItems } from '~/utils/erpWorkspaceNav'

const route = useRoute()
const { currentCompany, currentAccount } = useAuth()
const open = ref(false)
const mounted = ref(false)
const expandedModules = ref<string[]>([])
const { closeForNavigation } = useErpSidebarNavigation(open)
const workspaceKey = computed(() => `${currentAccount.value?.id ?? ''}:${currentCompany.value?.id ?? ''}:${currentCompany.value?.access_kind ?? ''}`)
const sidebarHash = computed(() => mounted.value ? route.hash : '')
// Operational modules, catalogs and settings provide their own native panel and header.
const pageOwnsPanel = computed(() => erpNavModules.some(module =>
  module.id !== 'overview' && erpModuleActive(route.path, module)
))

const links = computed(() => erpWorkspaceSidebarItems(route.path, currentCompany.value, closeForNavigation, sidebarHash.value))
const groups = computed(() => erpWorkspaceSearchGroups(links.value))

watch(
  () => [route.path, sidebarHash.value, workspaceKey.value],
  () => {
    const activeModule = links.value.flat().find(item => item.type === 'trigger' && item.active)
    const value = activeModule?.value
    if (typeof value === 'string' && !expandedModules.value.includes(value))
      expandedModules.value = [...expandedModules.value, value]
  },
  { immediate: true }
)

onMounted(() => {
  mounted.value = true
})

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
      <template #header="{ collapsed }">
        <WorkspaceContextMenu :collapsed="collapsed" context="company" />
      </template>

      <template #default="{ collapsed }">
        <UDashboardSearchButton :collapsed="collapsed" label="Pesquisar…" class="bg-transparent ring-default" />

        <UNavigationMenu
          v-model="expandedModules"
          :collapsed="collapsed"
          :items="links[0]"
          orientation="vertical"
          tooltip
          popover
          aria-label="Navegação do ERP"
        />

        <UNavigationMenu
          v-if="links[1]?.length"
          v-model="expandedModules"
          :collapsed="collapsed"
          :items="links[1]"
          orientation="vertical"
          tooltip
          popover
          class="mt-auto"
          aria-label="Administração do ERP"
        />
      </template>

      <template #footer="{ collapsed }">
        <UserMenu :collapsed="collapsed" />
      </template>
    </UDashboardSidebar>

    <UDashboardSearch :groups="groups" placeholder="Pesquisar no ERP…" />

    <slot v-if="pageOwnsPanel" :key="workspaceKey" />
    <UDashboardPanel v-else :ui="{ body: 'p-0 sm:p-0' }">
      <template #header>
        <UDashboardNavbar
          :title="navbarTitle"
          :ui="{ left: 'min-w-0 flex-1', title: 'min-w-0 truncate', right: 'min-w-0 shrink-0' }"
        >
          <template #toggle>
            <UDashboardSidebarToggle aria-label="Abrir menu ERP" data-erp-sidebar-trigger />
          </template>
          <template #leading>
            <UDashboardSidebarCollapse aria-label="Recolher menu ERP" />
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
