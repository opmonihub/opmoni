<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'
import { monitoringGroups, monitoringListPath, parseMonitoringSlug } from '~/utils/monitoringNav'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const listing = computed(() => parseMonitoringSlug(route.params.slug))

const currentGroup = computed(() => {
  const obligation = listing.value?.obligation
  if (!obligation) return null
  return monitoringGroups.find(group => group.pages.some(page => page.slug === obligation.slug)) ?? null
})

const title = computed(() => currentGroup.value?.label ?? 'Monitoramento')

const pageTabs = computed<NavigationMenuItem[][] | null>(() => {
  const current = listing.value
  const group = currentGroup.value
  if (!current || !group || group.pages.length < 2) return null
  return [[
    ...group.pages.map(page => ({
      label: page.label,
      icon: page.icon,
      to: monitoringListPath(page, current.situacao ?? undefined),
      active: page.slug === current.obligation.slug
    }))
  ]]
})
</script>

<template>
  <UDashboardPanel id="monitoring" :ui="{ body: 'min-h-0 flex-1 gap-0 overflow-hidden p-0 sm:p-0' }">
    <template #header>
      <UDashboardNavbar :title="title">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar v-if="pageTabs">
        <UNavigationMenu :items="pageTabs" highlight class="-mx-1 min-w-0 flex-1" />
      </UDashboardToolbar>
    </template>

    <template #body>
      <NuxtPage />
    </template>
  </UDashboardPanel>
</template>
