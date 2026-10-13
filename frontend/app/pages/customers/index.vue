<script setup lang="ts">
import { customerListPathFromQuery } from '~/utils/customerRoutes'

definePageMeta({ middleware: 'auth' })

const route = useRoute()

await navigateTo(customerListPathFromQuery(route.query) ?? '/customers/painel', { replace: true })
</script>

<template>
  <span class="sr-only">Abrindo clientes</span>
</template>
