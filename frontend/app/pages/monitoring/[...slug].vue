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
  <!--
    The key is the list's identity, obligation **and** situation: both are route
    segments, and both are what `listKey` inside the sheet varies on. Without it
    the sheet is reused across an obligation change, and `useAsyncData` seeds the
    new key with the old key's data (`asyncData.js`: `initialValue = ... ? _asyncData[oldKey].data.value : cachedData`).
    So the new obligation would render the previous one's counters and clients
    under its own heading for the whole request, the skeleton would not cover it
    (`rows.length` is non-zero), and a message row clicked in that window would
    reach `readMessage` with the **new** slug and the **old** obligation's
    message id. A remount re-runs the fetch with its `default` applied, so
    `rows` starts empty and the skeleton shows.
  -->
  <MonitoringSheet
    v-if="listing"
    :key="`${listing.obligation.slug}-${listing.situacao ?? 'todas'}`"
    :obligation="listing.obligation"
    :situacao="listing.situacao"
  />
</template>
