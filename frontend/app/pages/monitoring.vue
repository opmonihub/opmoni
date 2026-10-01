<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'
import { monitoringGroups, monitoringListPath, monitoringObligationUnserved, monitoringTabs, parseMonitoringSlug } from '~/utils/monitoringNav'
import { monitoringActions } from '~/utils/monitoringPresentation'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const router = useRouter()
const { canManageClients } = useAuth()

/**
 * The navbar owns the page actions; the child page owns the work. A counter
 * rather than a boolean, so two clicks are two requests, and the child watches
 * it without `immediate` — a value left over from another page never fires.
 */
const refreshRequest = useState('monitoring-refresh', () => 0)
const associateRequest = useState('monitoring-associate', () => 0)
const refreshing = useState('monitoring-refreshing', () => false)
const detailTitle = useState<string | null>('monitoring-detail-title', () => null)

const listing = computed(() => parseMonitoringSlug(route.params.slug))

const currentGroup = computed(() => {
  const obligation = listing.value?.obligation
  if (!obligation) return null
  return monitoringGroups.find(group => group.pages.some(page => page.slug === obligation.slug)) ?? null
})

const onRunDetail = computed(() => /^\/monitoring\/execucoes\/[^/]+/.test(route.path))

const title = computed(() => {
  if (onRunDetail.value) return detailTitle.value || 'Execução'
  if (currentGroup.value) return currentGroup.value.label
  if (route.path.startsWith('/monitoring/execucoes')) return 'Execuções de sincronização'
  return 'Monitoramento'
})

watch(onRunDetail, (value) => {
  if (!value) detailTitle.value = null
})

const unserved = computed(() => !!listing.value && monitoringObligationUnserved(listing.value.obligation))
const canAssociate = computed(() => canManageClients.value && !!listing.value && !unserved.value)
const canRefresh = computed(() => !unserved.value)

const tabs = computed(() => monitoringTabs(route.path))

/**
 * The second level: the obligations that share a group.
 *
 * Only the four multi-obligation groups get this row — a group of one has no
 * siblings to move between, and an obligation page with no module tabs above it
 * would leave the operator with nowhere in the module to go. `highlightColor`
 * is neutral so the module row above keeps the primary green to itself.
 */
const obligationTabs = computed<NavigationMenuItem[][] | null>(() => {
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

async function goBack() {
  if (import.meta.client && window.history.length > 1) {
    router.back()
    return
  }
  await navigateTo('/monitoring/execucoes')
}
</script>

<template>
  <UDashboardPanel id="monitoring" :ui="{ body: 'min-h-0 flex-1 gap-0 overflow-hidden p-0 sm:p-0' }">
    <template #header>
      <UDashboardNavbar :title="title">
        <template #leading>
          <UDashboardSidebarCollapse />
          <UButton
            v-if="onRunDetail"
            icon="i-lucide-arrow-left"
            color="neutral"
            variant="ghost"
            square
            aria-label="Voltar"
            @click="goBack"
          />
        </template>

        <template #right>
          <UButton
            v-if="canRefresh"
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="ghost"
            :loading="refreshing"
            :aria-label="monitoringActions.refresh"
            class="sm:hidden"
            @click="refreshRequest++"
          />
          <UButton
            v-if="canRefresh"
            :label="monitoringActions.refresh"
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="outline"
            :loading="refreshing"
            class="hidden sm:inline-flex"
            @click="refreshRequest++"
          />
          <UButton
            v-if="canAssociate"
            icon="i-lucide-user-plus"
            color="primary"
            :aria-label="monitoringActions.associate"
            class="sm:hidden"
            @click="associateRequest++"
          />
          <UButton
            v-if="canAssociate"
            :label="monitoringActions.associate"
            icon="i-lucide-user-plus"
            color="primary"
            class="hidden sm:inline-flex"
            @click="associateRequest++"
          />
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar v-if="!onRunDetail">
        <UNavigationMenu :items="tabs" highlight class="-mx-1 min-w-0 flex-1" />
      </UDashboardToolbar>

      <UDashboardToolbar v-if="!onRunDetail && obligationTabs">
        <UNavigationMenu
          :items="obligationTabs"
          highlight
          highlight-color="neutral"
          class="-mx-1 min-w-0 flex-1"
        />
      </UDashboardToolbar>
    </template>

    <template #body>
      <NuxtPage />
    </template>
  </UDashboardPanel>
</template>
