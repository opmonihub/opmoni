<script setup lang="ts">
import type { CommandPaletteGroup, CommandPaletteItem, NavigationMenuItem } from '@nuxt/ui'
import { adminSidebarItem } from '~/utils/adminNav'
import { clientsSidebarItem } from '~/utils/clientsNav'
import { settingsSidebarChildren } from '~/utils/settingsNav'
import { fiscalSidebarChildren } from '~/utils/fiscalNav'
import { foldedSidebarChildren, sidebarOpenGroupFromPath, sidebarParentActive } from '~/utils/sidebarNav'
import { workSidebarChildren } from '~/utils/workNav'
import { equipeSidebarChildren } from '~/utils/equipeNav'
import { monitoringSidebarChildren } from '~/utils/monitoringNav'

const route = useRoute()
const { user, canManageMembers } = useAuth()

const open = ref(false)
const routeGroup = computed(() => sidebarOpenGroupFromPath(route.path))
const sidebarOpenGroup = ref<string | undefined>(routeGroup.value || undefined)
const groupFolded = ref(false)

// Only a module change resets a manual fold, not navigation within the module.
watch(routeGroup, (group) => {
  groupFolded.value = false
  sidebarOpenGroup.value = group || undefined
})

const links = computed(() => [[{
  label: 'Dashboard',
  icon: 'i-lucide-house',
  to: '/',
  onSelect: () => {
    open.value = false
  }
}, {
  label: 'Inbox',
  icon: 'i-lucide-inbox',
  to: '/inbox',
  badge: '4',
  onSelect: () => {
    open.value = false
  }
}, {
  ...clientsSidebarItem(route.path),
  children: clientsSidebarItem(route.path).children.map(child => ({ ...child, onSelect: () => { open.value = false } }))
}, ...[
  { label: 'Fiscal', value: 'fiscal', icon: 'i-lucide-file-text', to: '/fiscal', children: fiscalSidebarChildren(route.path) },
  { label: 'Monitoramento', value: 'monitoramento', icon: 'i-lucide-radar', to: '/monitoring', children: monitoringSidebarChildren(route.path) },
  { label: 'Work', value: 'work', icon: 'i-lucide-list-checks', to: '/work', children: workSidebarChildren(route.path) },
  { label: 'Equipe', value: 'equipe', icon: 'i-lucide-users-round', to: '/equipe', children: equipeSidebarChildren(route.path) },
  { label: 'Settings', value: 'configuracoes', icon: 'i-lucide-settings', to: '/settings', children: [
    ...settingsSidebarChildren(route.path, canManageMembers.value),
    { label: 'Members', to: '/settings/members', active: sidebarParentActive('/settings/members', route.path) }
  ] }
].map(item => ({
  ...item,
  type: 'trigger' as const,
  active: sidebarParentActive(item.to, route.path),
  defaultOpen: route.path === item.to || route.path.startsWith(`${item.to}/`),
  children: item.children.map(child => ({ ...child, onSelect: () => { open.value = false } }))
}))
], [{
  label: 'Feedback',
  icon: 'i-lucide-message-circle',
  to: 'https://github.com/nuxt-ui-templates/dashboard',
  target: '_blank'
}, {
  label: 'Help & Support',
  icon: 'i-lucide-info',
  to: 'https://github.com/nuxt-ui-templates/dashboard',
  target: '_blank'
}]] satisfies NavigationMenuItem[][])

// A collapsed Clients root discloses its popover; only its children navigate.
const collapsedMainLinks = computed(() => (links.value[0] ?? []).map(item => 'value' in item && item.value === 'clientes'
  ? { ...item, 'to': undefined, 'href': undefined, 'aria-label': item.label }
  : item))

const platformLinks = computed<NavigationMenuItem[]>(() => {
  if (!user.value?.is_super_admin) return []

  const item = adminSidebarItem(route.path)
  return [{
    ...item,
    children: item.children?.map(child => ({ ...child, onSelect: () => { open.value = false } }))
  }]
})

function keepsActiveChild(item: NavigationMenuItem) {
  return !!item.value && item.value === routeGroup.value && !!item.children?.some(child => child.active)
}

function expandedEntries(items: NavigationMenuItem[]) {
  return items.map((item, index) => {
    const pinned = keepsActiveChild(item) && (groupFolded.value || sidebarOpenGroup.value !== item.value)
    return {
      key: item.value ?? item.label ?? String(index),
      source: item,
      menuItem: {
        ...item,
        // Only the current child is highlighted when labels are visible; icon menus use the catalogue.
        active: item.children?.length ? false : item.active,
        value: item.value ?? `leaf-${index}`,
        children: foldedSidebarChildren(item.children, pinned),
        // Content remains open for the active child, but the chevron shows a folded list.
        // Keep native aria-expanded=true: a real, reachable child is still disclosed.
        ui: pinned ? { ...item.ui, linkTrailingIcon: 'group-data-[state=open]:!rotate-0' } : item.ui
      },
      // Reka treats an initial undefined value as uncontrolled; '' explicitly closes the group.
      modelValue: keepsActiveChild(item) ? item.value : sidebarOpenGroup.value ?? ''
    }
  })
}

const expandedMainEntries = computed(() => expandedEntries(links.value[0] ?? []))
const expandedPlatformEntries = computed(() => expandedEntries(platformLinks.value))

function onGroupToggle(item: NavigationMenuItem, value: string | undefined) {
  if (keepsActiveChild(item)) {
    const fullyOpen = !groupFolded.value && sidebarOpenGroup.value === item.value
    groupFolded.value = fullyOpen
    sidebarOpenGroup.value = item.value
    return
  }
  groupFolded.value = false
  sidebarOpenGroup.value = value
}

const groups = computed<CommandPaletteGroup<CommandPaletteItem>[]>(() => [{
  id: 'links',
  label: 'Go to',
  items: [...links.value.flat(), ...platformLinks.value.flatMap(item => item.children ?? [])]
    .flatMap((item: NavigationMenuItem) => [item, ...(item.children ?? [])])
    .map(item => ({ label: item.label ?? '', icon: item.icon, to: item.to }))
}, {
  id: 'code',
  label: 'Code',
  items: [{
    id: 'source',
    label: 'View page source',
    icon: 'i-simple-icons-github',
    to: `https://github.com/nuxt-ui-templates/dashboard/blob/main/app/pages${route.path === '/' ? '/index' : route.path}.vue`,
    target: '_blank'
  }]
}])
</script>

<template>
  <UDashboardGroup unit="rem">
    <UDashboardSidebar
      id="default"
      v-model:open="open"
      collapsible
      resizable
      class="bg-elevated/25"
      :ui="{ footer: 'lg:border-t lg:border-default' }"
    >
      <template #header="{ collapsed, collapse }">
        <UButton
          v-if="collapsed"
          icon="i-lucide-building-2"
          :aria-label="user?.current_account?.name || 'Selecionar escritório'"
          color="neutral"
          variant="ghost"
          @click="collapse(false)"
        />
        <WorkspaceContextMenu v-else-if="user?.current_account || user?.companies?.length" class="w-full min-w-0" />
      </template>

      <template #default="{ collapsed }">
        <UDashboardSearchButton :collapsed="collapsed" class="bg-transparent ring-default" />

        <UNavigationMenu
          v-if="collapsed"
          :collapsed="true"
          :items="collapsedMainLinks"
          orientation="vertical"
          tooltip
          popover
        />
        <div v-else class="flex flex-col gap-1.5">
          <UNavigationMenu
            v-for="entry in expandedMainEntries"
            :key="entry.key"
            :model-value="entry.modelValue"
            type="single"
            :items="[entry.menuItem]"
            orientation="vertical"
            tooltip
            popover
            @update:model-value="(value) => onGroupToggle(entry.source, value as string | undefined)"
          />
        </div>

        <template v-if="platformLinks.length">
          <UNavigationMenu
            v-if="collapsed"
            :collapsed="true"
            :items="platformLinks"
            orientation="vertical"
            tooltip
            popover
          />
          <div v-else class="flex flex-col gap-1.5">
            <UNavigationMenu
              v-for="entry in expandedPlatformEntries"
              :key="entry.key"
              :model-value="entry.modelValue"
              type="single"
              :items="[entry.menuItem]"
              orientation="vertical"
              tooltip
              popover
              @update:model-value="(value) => onGroupToggle(entry.source, value as string | undefined)"
            />
          </div>
        </template>

        <UNavigationMenu
          :collapsed="collapsed"
          :items="links[1]"
          orientation="vertical"
          tooltip
          class="mt-auto"
        />
      </template>

      <template #footer="{ collapsed }">
        <UserMenu :collapsed="collapsed" />
      </template>
    </UDashboardSidebar>

    <UDashboardSearch :groups="groups" />

    <slot />

    <NotificationsSlideover />
  </UDashboardGroup>
</template>
