<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'
import { workNav } from '~/utils/workNav'
import { isWorkMonthScopedPath } from '~/utils/workReferenceMonth'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const {
  referenceMonth,
  label,
  isMonthScoped,
  prevMonth,
  nextMonth
} = useWorkReferenceMonth()

const title = computed(() => {
  const current = workNav.find(item => route.path === item.to || route.path.startsWith(`${item.to}/`))
  return current?.label ?? 'Work'
})

const pageTabs = computed<NavigationMenuItem[][]>(() => [[
  ...workNav.map((item) => {
    const monthScoped = isWorkMonthScopedPath(item.to)
    return {
      label: item.label,
      icon: item.icon,
      to: monthScoped
        ? { path: item.to, query: { reference_month: referenceMonth.value } }
        : item.to,
      active: route.path === item.to || route.path.startsWith(`${item.to}/`)
    }
  })
]])
</script>

<template>
  <UDashboardPanel id="work" :ui="{ body: 'min-h-0 flex-1 gap-0 overflow-hidden p-0 sm:p-0' }">
    <template #header>
      <UDashboardNavbar :title="title">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <template #left>
          <UNavigationMenu :items="pageTabs" highlight class="-mx-1 min-w-0 flex-1" />
        </template>

        <template #right>
          <div class="flex items-center gap-1.5">
            <div id="work-toolbar-actions" class="flex items-center gap-1" />
            <WorkReferenceMonthPicker
              v-if="isMonthScoped"
              v-model="referenceMonth"
              :label="label"
              @prev="prevMonth"
              @next="nextMonth"
            />
          </div>
        </template>
      </UDashboardToolbar>
    </template>

    <template #body>
      <NuxtPage />
    </template>
  </UDashboardPanel>
</template>
