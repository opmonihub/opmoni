<script setup lang="ts">
import { equipeTabs } from '~/utils/equipeNav'

definePageMeta({ middleware: 'auth' })

const route = useRoute()

const navbarTitle = computed(() => route.path.startsWith('/equipe/departamentos') ? 'Departamentos' : 'Membros')
const links = computed(() => equipeTabs(route.path))
</script>

<template>
  <UDashboardPanel id="equipe" :ui="{ body: 'lg:py-12' }">
    <template #header>
      <UDashboardNavbar :title="navbarTitle">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <UNavigationMenu :items="links" highlight class="-mx-1 min-w-0 flex-1" />
      </UDashboardToolbar>
    </template>

    <template #body>
      <div class="mx-auto flex w-full flex-col gap-4 sm:gap-6 lg:max-w-2xl lg:gap-12">
        <NuxtPage />
      </div>
    </template>
  </UDashboardPanel>
</template>
