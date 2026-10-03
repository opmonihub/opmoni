<script setup lang="ts">
import type { CommandPaletteGroup, CommandPaletteItem, NavigationMenuItem } from '@nuxt/ui'
import { adminPages, adminSidebarChildren } from '~/utils/adminNav'
import { equipeSidebarChildren } from '~/utils/equipeNav'
import { fiscalSidebarChildren } from '~/utils/fiscalNav'
import { monitoringSidebarChildren } from '~/utils/monitoringNav'
import { settingsSidebarChildren } from '~/utils/settingsNav'
import { sidebarOpenGroupFromPath } from '~/utils/sidebarNav'
import { workSidebarChildren } from '~/utils/workNav'

const route = useRoute()
const toast = useToast()
const { isSuperAdmin, canManageMembers } = useAuth()
const inSupportMode = useSupportMode()

const open = ref(false)
const sidebarOpenGroup = ref(sidebarOpenGroupFromPath(route.path))

watch(() => route.path, (path) => {
  sidebarOpenGroup.value = sidebarOpenGroupFromPath(path)
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
    if (item.label === 'Clientes') {
      return {
        ...item,
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
    if (item.label === 'Equipe') {
      return {
        ...item,
        children: equipeSidebarChildren(route.path).map(child => ({
          ...child,
          onSelect: close
        }))
      }
    }
    if (item.label === 'Monitoramento') {
      return {
        ...item,
        children: monitoringSidebarChildren(route.path).map(child => ({
          ...child,
          onSelect: close
        }))
      }
    }
    if (item.label === 'Fiscal') {
      return {
        ...item,
        children: fiscalSidebarChildren(route.path).map(child => ({
          ...child,
          onSelect: close
        }))
      }
    }
    if (item.label === 'Work') {
      return {
        ...item,
        children: workSidebarChildren(route.path).map(child => ({
          ...child,
          onSelect: close
        }))
      }
    }
    if (item.label === 'Configurações') {
      return {
        ...item,
        children: settingsSidebarChildren(canManageMembers.value).map(child => ({
          ...child,
          onSelect: close
        }))
      }
    }
    return item
  })
  if (isSuperAdmin.value) {
    main.push({
      label: 'Admin',
      icon: 'i-lucide-shield-check',
      to: '/admin',
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
          v-model="sidebarOpenGroup"
          type="single"
          :collapsed="collapsed"
          :items="navLinks[0]"
          orientation="vertical"
          tooltip
          popover
        />

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
