<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'
import { adminPages, adminSidebarChildren } from '~/utils/adminNav'
import { equipeSidebarChildren } from '~/utils/equipeNav'
import { monitoringSidebarChildren } from '~/utils/monitoringNav'
import { workSidebarChildren } from '~/utils/workNav'

const route = useRoute()
const toast = useToast()
const { isSuperAdmin } = useAuth()
const inSupportMode = useSupportMode()

const open = ref(false)

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
  defaultOpen: true,
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
  type: 'trigger',
  onSelect: () => {
    open.value = false
  }
}, {
  label: 'Monitoramento',
  icon: 'i-lucide-activity',
  to: '/monitoring',
  type: 'trigger',
  onSelect: () => {
    open.value = false
  }
}, {
  label: 'Work',
  icon: 'i-lucide-clipboard-list',
  to: '/work',
  type: 'trigger',
  onSelect: () => {
    open.value = false
  }
}, {
  label: 'Configurações',
  to: '/settings',
  icon: 'i-lucide-settings',
  defaultOpen: true,
  type: 'trigger',
  children: [{
    label: 'Geral',
    to: '/settings',
    exact: true,
    onSelect: () => {
      open.value = false
    }
  }, {
    label: 'Notificações',
    to: '/settings/notifications',
    onSelect: () => {
      open.value = false
    }
  }, {
    label: 'Segurança',
    to: '/settings/security',
    onSelect: () => {
      open.value = false
    }
  }]
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
        defaultOpen: route.path.startsWith('/equipe'),
        children: equipeSidebarChildren(route.path).map(child => ({
          ...child,
          onSelect: close
        }))
      }
    }
    if (item.label === 'Monitoramento') {
      return {
        ...item,
        defaultOpen: route.path.startsWith('/monitoring'),
        children: monitoringSidebarChildren(route.path).map(child => ({
          ...child,
          onSelect: close
        }))
      }
    }
    if (item.label === 'Work') {
      return {
        ...item,
        defaultOpen: route.path.startsWith('/work'),
        children: workSidebarChildren(route.path).map(child => ({
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
      defaultOpen: route.path.startsWith('/admin'),
      type: 'trigger',
      children: adminSidebarChildren(route.path).map(child => ({
        ...child,
        onSelect: close
      }))
    })
  }
  return [main, links[1] ?? []]
})

const groups = computed(() => [{
  id: 'links',
  label: 'Ir para',
  items: [...links.flat(), ...(isSuperAdmin.value ? adminPages : [])]
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
