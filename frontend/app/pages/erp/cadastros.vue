<script setup lang="ts">
import { erpWorkspaceDirectLinks } from '~/utils/erpWorkspaceNav'

definePageMeta({ layout: 'erp', middleware: 'auth' })

const route = useRoute()
const { currentCompany } = useAuth()
const links = computed(() => erpWorkspaceDirectLinks('cadastros', route.path, currentCompany.value))
const title = computed(() => links.value.find(link => link.active)?.label ?? 'Cadastros')
</script>

<template>
  <ErpCatalogPageShell :title="title" :navigation="links">
    <NuxtPage />
  </ErpCatalogPageShell>
</template>
