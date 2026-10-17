<script setup lang="ts">
import { erpModuleActive, erpModuleNavbarTitle, erpVisibleNavModules } from '~/utils/erpModulesNav'

const route = useRoute()
const { currentCompany } = useAuth()
const open = ref(false)

const companyGrants = computed(() => currentCompany.value?.permissions)

const links = computed(() => [[
  ...erpVisibleNavModules(companyGrants.value).map(module => ({
    label: module.label,
    icon: module.icon,
    to: module.to,
    active: erpModuleActive(route.path, module),
    onSelect: () => { open.value = false }
  }))
]])

const navbarTitle = computed(() => {
  if (currentCompany.value?.name)
    return currentCompany.value.name
  return erpModuleNavbarTitle(route.path)
})
</script>

<template>
  <UDashboardGroup unit="rem">
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

    <UDashboardPanel>
      <template #header>
        <UDashboardNavbar :title="navbarTitle">
          <template #leading>
            <UDashboardSidebarToggle />
          </template>
        </UDashboardNavbar>
      </template>
      <slot />
    </UDashboardPanel>
  </UDashboardGroup>
</template>
