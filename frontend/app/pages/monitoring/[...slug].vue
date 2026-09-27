<script setup lang="ts">
import MonitoringSheet from '~/components/monitoring/MonitoringSheet.vue'
import { parseMonitoringSlug } from '~/utils/monitoringNav'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const listing = computed(() => parseMonitoringSlug(route.params.slug))

if (!listing.value) {
  throw createError({ statusCode: 404, statusMessage: 'Página não encontrada' })
}

watch(listing, (value) => {
  if (!value) showError(createError({ statusCode: 404, statusMessage: 'Página não encontrada' }))
})
</script>

<template>
  <MonitoringSheet
    v-if="listing"
    :obligation="listing.obligation"
    :situacao="listing.situacao"
  />
</template>
