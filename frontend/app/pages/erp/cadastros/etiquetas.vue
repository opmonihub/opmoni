<script setup lang="ts">
import type { ErpCatalogPrintSettings } from '#shared/types/erpCatalogs'
import { defaultErpCatalogPrintSettings, erpCatalogRoutes } from '#shared/utils/erpCatalogs'
import { apiFailure } from '#shared/utils/access'

definePageMeta({ layout: 'erp', middleware: 'auth' })
const { api, refreshCsrf } = useAccessApi()
const settings = ref<ErpCatalogPrintSettings>({ ...defaultErpCatalogPrintSettings })
const pending = ref(false)
const error = ref('')

onMounted(async () => {
  try {
    const response = await api<{ data: ErpCatalogPrintSettings }>(erpCatalogRoutes.printSettings)
    settings.value = response.data
  } catch (err) {
    error.value = apiFailure(err).message
  }
})

async function save() {
  pending.value = true
  error.value = ''
  try {
    await refreshCsrf()
    const response = await api<{ data: ErpCatalogPrintSettings }>(erpCatalogRoutes.printSettings, { method: 'PATCH', body: settings.value })
    settings.value = response.data
  } catch (err) {
    error.value = apiFailure(err).message
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <ErpCatalogPageShell title="Etiquetas e impressão" description="Configuração protegida de rótulos de produto.">
    <UAlert v-if="error" color="error" :title="error" />
    <form class="grid max-w-lg gap-3" @submit.prevent="save">
      <UFormField label="Largura (mm)">
        <UInput v-model.number="settings.label_width_mm" type="number" />
      </UFormField>
      <UFormField label="Altura (mm)">
        <UInput v-model.number="settings.label_height_mm" type="number" />
      </UFormField>
      <UCheckbox v-model="settings.show_price" label="Exibir preço" />
      <UCheckbox v-model="settings.show_barcode" label="Exibir código de barras" />
      <UButton type="submit" :loading="pending">
        Salvar
      </UButton>
    </form>
  </ErpCatalogPageShell>
</template>
