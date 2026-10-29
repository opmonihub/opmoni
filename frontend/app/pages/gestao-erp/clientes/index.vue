<script setup lang="ts">
import { officeErpCompanyListQuery } from '~/utils/officeErpCompanyNavigation'

definePageMeta({ middleware: 'auth' })
const route = useRoute()
await navigateTo({ path: '/gestao-erp/empresas', query: officeErpCompanyListQuery(route.query) }, { replace: true })
</script>

<template>
  <span />
</template>
