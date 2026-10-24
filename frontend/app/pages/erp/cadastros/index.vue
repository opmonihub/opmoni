<script setup lang="ts">
import { erpWorkspaceCatalogGroups } from '~/utils/erpWorkspaceNav'

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Cadastros · ERP' })

const route = useRoute()
const { currentCompany } = useAuth()
const groups = computed(() => erpWorkspaceCatalogGroups(route.path, currentCompany.value))
const hasDestinations = computed(() => groups.value.some(group => group[0]?.children?.length))
</script>

<template>
  <ErpCatalogPageShell
    embedded
    title="Cadastros"
    description="Catálogos comerciais e referências fiscais/logísticas da empresa."
  >
    <UAlert
      v-if="!hasDestinations"
      color="neutral"
      variant="subtle"
      title="Nenhum cadastro disponível"
      description="Esta empresa ainda não concedeu acesso a um catálogo."
    />
  </ErpCatalogPageShell>
</template>
