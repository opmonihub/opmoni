<script setup lang="ts">
import { erpCadastrosPages } from '#shared/utils/erpCatalogs'

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Cadastros · ERP' })

const sections = [
  { label: 'Contatos', to: erpCadastrosPages.contacts, icon: 'i-lucide-users' },
  { label: 'Grupos de clientes', to: erpCadastrosPages.customerGroups, icon: 'i-lucide-users-round' },
  { label: 'Produtos', to: erpCadastrosPages.products, icon: 'i-lucide-package' },
  { label: 'Unidades', to: erpCadastrosPages.units, icon: 'i-lucide-ruler' },
  { label: 'Marcas', to: erpCadastrosPages.brands, icon: 'i-lucide-tag' },
  { label: 'Categorias', to: erpCadastrosPages.categories, icon: 'i-lucide-folder-tree' },
  { label: 'Preços e descontos', to: erpCadastrosPages.pricing, icon: 'i-lucide-badge-percent' },
  { label: 'Fiscal', to: erpCadastrosPages.fiscal, icon: 'i-lucide-receipt' },
  { label: 'Logística', to: erpCadastrosPages.logistics, icon: 'i-lucide-truck' },
  { label: 'Importação', to: erpCadastrosPages.import, icon: 'i-lucide-upload' },
  { label: 'Etiquetas', to: erpCadastrosPages.labels, icon: 'i-lucide-barcode' }
]
</script>

<template>
  <ErpCatalogPageShell
    title="Cadastros"
    description="Catálogos comerciais e referências fiscais/logísticas da empresa."
  >
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <UButton
        v-for="item in sections"
        :key="item.to"
        :to="item.to"
        variant="subtle"
        color="neutral"
        class="justify-start"
        :icon="item.icon"
      >
        {{ item.label }}
      </UButton>
    </div>
  </ErpCatalogPageShell>
</template>
