<script setup lang="ts">
import type { CommandPaletteGroup, CommandPaletteItem, NavigationMenuItem } from '@nuxt/ui'
import { adminPages, adminSidebarChildren } from '~/utils/adminNav'
import { equipeSidebarChildren } from '~/utils/equipeNav'
import { fiscalSidebarChildren } from '~/utils/fiscalNav'
import { monitoringSidebarChildren } from '~/utils/monitoringNav'
import { settingsSidebarChildren } from '~/utils/settingsNav'
import { collapsedSidebarItems, foldedSidebarChildren, sidebarOpenGroupFromPath } from '~/utils/sidebarNav'
import { workSidebarChildren } from '~/utils/workNav'

const route = useRoute()
const toast = useToast()
const { isSuperAdmin, canManageMembers } = useAuth()
const inSupportMode = useSupportMode()

const open = ref(false)
const routeGroup = computed(() => sidebarOpenGroupFromPath(route.path))
const sidebarOpenGroup = ref<string | undefined>(routeGroup.value || undefined)
const groupFolded = ref(false)

watch(routeGroup, (group) => {
  groupFolded.value = false
  sidebarOpenGroup.value = group || undefined
})

const links = [[{
  label: 'Início',
  icon: 'i-lucide-house',
  to: '/',
  onSelect: () => {
    open.value = false
  }
}, {
  label: 'Caixa de entrada',
  icon: 'i-lucide-inbox',
  to: '/inbox',
  badge: '4',
  onSelect: () => {
    open.value = false
  }
}, {
  label: 'Clientes',
  icon: 'i-lucide-building',
  to: '/customers',
  value: 'clientes',
  type: 'trigger',
  children: [{
    label: 'Painel',
    to: '/customers/painel',
    exact: true,
    onSelect: () => {
      open.value = false
    }
  }, {
    label: 'Meus clientes',
    to: '/customers/certificados',
    onSelect: () => {
      open.value = false
    }
  }]
}, {
  label: 'Equipe',
  icon: 'i-lucide-users-round',
  to: '/equipe',
  value: 'equipe',
  type: 'trigger',
  onSelect: () => {
    open.value = false
  }
}, {
  label: 'Monitoramento',
  icon: 'i-lucide-activity',
  to: '/monitoring',
  value: 'monitoramento',
  type: 'trigger',
  onSelect: () => {
    open.value = false
  }
}, {
  label: 'Fiscal',
  icon: 'i-lucide-receipt-text',
  to: '/fiscal',
  value: 'fiscal',
  type: 'trigger',
  onSelect: () => {
    open.value = false
  }
}, {
  label: 'Work',
  icon: 'i-lucide-clipboard-list',
  to: '/work',
  value: 'work',
  type: 'trigger',
  onSelect: () => {
    open.value = false
  }
}, {
  label: 'Configurações',
  to: '/settings',
  icon: 'i-lucide-settings',
  value: 'configuracoes',
  type: 'trigger',
  onSelect: () => {
    open.value = false
  }
}], [{
  label: 'Feedback',
  icon: 'i-lucide-message-circle',
  to: 'https://github.com/nuxt-ui-templates/dashboard',
  target: '_blank'
}, {
  label: 'Ajuda',
  icon: 'i-lucide-life-buoy',
  to: 'https://github.com/nuxt-ui-templates/dashboard',
  target: '_blank'
}]] satisfies NavigationMenuItem[][]

const navLinks = computed<NavigationMenuItem[][]>(() => {
  const close = () => {
    open.value = false
  }
  const main: NavigationMenuItem[] = (links[0] ?? []).map((item) => {
    const base = 'type' in item && item.type === 'trigger' ? { ...item, exact: true } : item
    if (base.label === 'Clientes') {
      return {
        ...base,
        children: [{
          label: 'Painel',
          to: '/customers/painel',
          exact: true,
          active: route.path === '/customers/painel',
          onSelect: close
        }, {
          label: 'Meus clientes',
          to: '/customers/certificados',
          active: route.path.startsWith('/customers/certificados') || route.path.startsWith('/customers/procuracao'),
          onSelect: close
        }]
      }
    }
    if (base.label === 'Equipe') {
      return {
        ...base,
        children: equipeSidebarChildren(route.path).map(child => ({
          ...child,
          onSelect: close
        }))
      }
    }
    if (base.label === 'Monitoramento') {
      return {
        ...base,
        children: monitoringSidebarChildren(route.path).map(child => ({
          ...child,
          onSelect: close
        }))
      }
    }
    if (base.label === 'Fiscal') {
      return {
        ...base,
        children: fiscalSidebarChildren(route.path).map(child => ({
          ...child,
          onSelect: close
        }))
      }
    }
    if (base.label === 'Work') {
      return {
        ...base,
        children: workSidebarChildren(route.path).map(child => ({
          ...child,
          onSelect: close
        }))
      }
    }
    if (base.label === 'Configurações') {
      return {
        ...base,
        children: settingsSidebarChildren(route.path, canManageMembers.value).map(child => ({
          ...child,
          onSelect: close
        }))
      }
    }
    return base
  })
  if (isSuperAdmin.value) {
    main.push({
      label: 'Admin',
      icon: 'i-lucide-shield-check',
      to: '/admin',
      exact: true,
      value: 'admin',
      type: 'trigger',
      children: adminSidebarChildren(route.path).map(child => ({
        ...child,
        onSelect: close
      }))
    })
  }
  return [main, links[1] ?? []]
})

function keepsActiveChild(item: NavigationMenuItem) {
  return !!item.value && item.value === routeGroup.value && !!item.children?.some(child => child.active)
}

function pinnedClosed(item: NavigationMenuItem) {
  return keepsActiveChild(item) && (groupFolded.value || sidebarOpenGroup.value !== item.value)
}

const expandedEntries = computed(() => {
  return (navLinks.value[0] ?? []).map((item, index) => {
    const pinned = pinnedClosed(item)
    const menuItem: NavigationMenuItem = {
      ...(item.value ? item : { ...item, value: `leaf-${index}` }),
      children: foldedSidebarChildren(item.children, pinned),
      ui: pinned
        ? { ...item.ui, linkTrailingIcon: 'group-data-[state=open]:!rotate-0' }
        : item.ui
    }
    return {
      key: item.value ?? item.label ?? String(index),
      source: item,
      menuItem,
      modelValue: keepsActiveChild(item) ? item.value : sidebarOpenGroup.value
    }
  })
})

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
  label: 'Ir para',
  items: [...navLinks.value.flat(), ...(isSuperAdmin.value ? adminPages : [])].map((item) => {
    const { chip } = item as NavigationMenuItem
    // NavigationMenuItem aceita `chip: false`, que CommandPaletteItem não aceita
    return { ...item, chip: typeof chip === 'boolean' ? undefined : chip }
  })
}])

onMounted(async () => {
  const cookie = useCookie('cookie-consent')
  if (cookie.value === 'accepted') {
    return
  }

  toast.add({
    title: 'Usamos cookies próprios para melhorar sua experiência.',
    duration: 0,
    close: false,
    actions: [{
      label: 'Aceitar',
      color: 'neutral',
      variant: 'outline',
      onClick: () => {
        cookie.value = 'accepted'
      }
    }, {
      label: 'Recusar',
      color: 'neutral',
      variant: 'ghost'
    }]
  })
})
</script>

<template>
  <SupportBanner />

  <UDashboardGroup unit="rem" :class="[inSupportMode && 'pt-12']">
    <UDashboardSidebar
      id="default"
      v-model:open="open"
      collapsible
      resizable
      class="bg-elevated/25"
      :ui="{ footer: 'lg:border-t lg:border-default' }"
    >
      <template #header="{ collapsed }">
        <TeamsMenu :collapsed="collapsed" />
      </template>

      <template #default="{ collapsed }">
        <UDashboardSearchButton :collapsed="collapsed" label="Buscar..." class="bg-transparent ring-default" />

        <UNavigationMenu
          v-if="collapsed"
          collapsed
          :items="collapsedSidebarItems(navLinks[0] ?? [], route.path)"
          orientation="vertical"
          tooltip
          popover
        />

        <div v-else class="flex flex-col gap-1.5">
          <div v-for="entry in expandedEntries" :key="entry.key" class="min-w-0">
            <UNavigationMenu
              :model-value="entry.modelValue"
              type="single"
              :items="[entry.menuItem]"
              orientation="vertical"
              tooltip
              popover
              @update:model-value="(value) => onGroupToggle(entry.source, value as string | undefined)"
            />
          </div>
        </div>

        <UNavigationMenu
          :collapsed="collapsed"
          :items="navLinks[1]"
          orientation="vertical"
          tooltip
          class="mt-auto"
        />
      </template>

      <template #footer="{ collapsed }">
        <UserMenu :collapsed="collapsed" />
      </template>
    </UDashboardSidebar>

    <UDashboardSearch placeholder="Buscar..." :groups="groups" />

    <slot />

    <NotificationsSlideover />
  </UDashboardGroup>
</template>
