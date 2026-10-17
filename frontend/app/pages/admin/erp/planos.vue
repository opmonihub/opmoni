<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { panelBodyClass, panelTableUi } from '~/components/data-table/panel'

definePageMeta({ middleware: ['auth', 'super-admin'] })

interface ErpPlan {
  id: number
  slug: string
  name: string
  modules: Array<{ module_key: string, quota: number | null }>
}

const { $api } = useNuxtApp()
const plans = ref<ErpPlan[]>([])
const loading = ref(false)
const loadError = ref<unknown>(null)

const columns: TableColumn<ErpPlan>[] = [
  { accessorKey: 'name', header: 'Plano' },
  { accessorKey: 'slug', header: 'Slug' },
  { id: 'modules', header: 'Módulos' }
]

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const response = await $api<{ data: ErpPlan[] }>('/admin/erp/plans')
    plans.value = response.data
  } catch (err) {
    loadError.value = err
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div :class="panelBodyClass">
    <UAlert v-if="loadError" color="error" title="Não foi possível carregar os planos ERP">
      <template #description>
        <UButton variant="link" @click="load">
          Tentar novamente
        </UButton>
      </template>
    </UAlert>
    <UTable
      v-else
      :columns="columns"
      :data="plans"
      :loading="loading"
      :ui="panelTableUi"
    >
      <template #modules-cell="{ row }">
        <span class="text-muted text-xs">{{ row.original.modules.map(m => m.module_key).join(', ') }}</span>
      </template>
      <template #empty>
        Nenhum plano ERP cadastrado.
      </template>
    </UTable>
  </div>
</template>
