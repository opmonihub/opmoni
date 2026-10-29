<script setup lang="ts">
import { officeErpCompanyListQuery } from '~/utils/officeErpCompanyNavigation'

definePageMeta({ middleware: 'auth' })
const route = useRoute()
const target = { path: '/gestao-erp/empresas/nova', query: { ...officeErpCompanyListQuery(route.query), ...(typeof route.query.cliente === 'string' ? { cliente: route.query.cliente } : {}) } }
await navigateTo(target, { replace: true })
</script>

<template>
  <span />
</template>
