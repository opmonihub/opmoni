<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'
import { erpModuleActive, erpModuleNavbarTitle, erpNavModules } from '~/utils/erpModulesNav'
import { erpWorkspaceSearchGroups, erpWorkspaceSidebarItems } from '~/utils/erpWorkspaceNav'
import { foldedSidebarChildren } from '~/utils/sidebarNav'

const route = useRoute()
const { currentCompany, currentAccount } = useAuth()
const open = ref(false)
const mounted = ref(false)
const openModule = ref('')
const moduleFolded = ref(false)
const { closeForNavigation } = useErpSidebarNavigation(open)
const { key: authorityKey } = useErpAdministrationContext()
const workspaceKey = computed(() => `${currentAccount.value?.id ?? ''}:${currentCompany.value?.id ?? ''}:${currentCompany.value?.access_kind ?? ''}`)
const sidebarHash = computed(() => mounted.value ? route.hash : '')
// Operational modules, catalogs and settings provide their own native panel and header.
const pageOwnsPanel = computed(() => erpNavModules.some(module =>
  module.id !== 'overview' && erpModuleActive(route.path, module)
))

const links = computed(() => erpWorkspaceSidebarItems(route.path, currentCompany.value, closeForNavigation, sidebarHash.value))
const groups = computed(() => erpWorkspaceSearchGroups(links.value))
const activeModule = computed(() => {
  const item = links.value.flat().find(item => item.type === 'trigger' && item.active)
  return typeof item?.value === 'string' ? item.value : ''
})

// The owner or selected authority changes the disclosure; a hash within the same module does not.
watch([activeModule, authorityKey], ([module]) => {
  openModule.value = module
  moduleFolded.value = false
}, { immediate: true })

function keepsActiveChild(item: NavigationMenuItem) {
  return item.value === activeModule.value && !!item.children?.some(child => child.active)
}

function sidebarEntries(items: NavigationMenuItem[]) {
  return items.map((item, index) => {
    const pinned = keepsActiveChild(item) && (moduleFolded.value || openModule.value !== item.value)
    return {
      key: item.value ?? item.label ?? `leaf-${index}`,
      source: item,
      menuItem: item.children?.length
        ? {
            ...item,
            active: false,
            children: foldedSidebarChildren(item.children, pinned),
            // A pinned child remains disclosed; only the chevron shows the folded state.
            ui: pinned ? { ...item.ui, linkTrailingIcon: 'group-data-[state=open]:!rotate-0' } : item.ui
          }
        : item,
      // Nuxt UI's single AccordionRoot needs '' to close a group with defaultOpen.
      modelValue: pinned || openModule.value === item.value ? item.value : ''
    }
  })
}

const mainEntries = computed(() => sidebarEntries(links.value[0] ?? []))
const administrationEntries = computed(() => sidebarEntries(links.value[1] ?? []))

function onModuleToggle(item: NavigationMenuItem, value: string | undefined) {
  if (keepsActiveChild(item)) {
    const fullyOpen = !moduleFolded.value && openModule.value === item.value
    moduleFolded.value = fullyOpen
    openModule.value = String(item.value)
    return
  }
  moduleFolded.value = false
  openModule.value = value ?? ''
}

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
          v-if="collapsed"
          :collapsed="true"
          :items="links[0]"
          orientation="vertical"
          tooltip
          popover
          aria-label="Navegação do ERP"
        />
        <div v-else class="flex flex-col gap-1.5">
          <UNavigationMenu
            v-for="entry in mainEntries"
            :key="entry.key"
            :model-value="entry.modelValue"
            type="single"
            :items="[entry.menuItem]"
            orientation="vertical"
            tooltip
            popover
            :aria-label="`${entry.source.label} · Navegação do ERP`"
            @update:model-value="(value) => onModuleToggle(entry.source, value as string | undefined)"
          />
        </div>

        <template v-if="links[1]?.length">
          <UNavigationMenu
            v-if="collapsed"
            :collapsed="true"
            :items="links[1]"
            orientation="vertical"
            tooltip
            popover
            class="mt-auto"
            aria-label="Administração do ERP"
          />
          <div v-else class="mt-auto flex flex-col gap-1.5">
            <UNavigationMenu
              v-for="entry in administrationEntries"
              :key="entry.key"
              :model-value="entry.modelValue"
              type="single"
              :items="[entry.menuItem]"
              orientation="vertical"
              tooltip
              popover
              :aria-label="`${entry.source.label} · Administração do ERP`"
              @update:model-value="(value) => onModuleToggle(entry.source, value as string | undefined)"
            />
          </div>
        </template>
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
