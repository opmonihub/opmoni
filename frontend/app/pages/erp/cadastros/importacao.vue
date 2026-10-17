<script setup lang="ts">
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'
import { apiFailure } from '#shared/utils/access'

definePageMeta({ layout: 'erp', middleware: 'auth' })
const { api, refreshCsrf } = useAccessApi()
const preview = ref<{ token: string, rows: unknown[], errors: { line: number, message: string }[] } | null>(null)
const busy = ref(false)
const message = ref('')

async function previewProducts(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file)
    return
  busy.value = true
  message.value = ''
  try {
    await refreshCsrf()
    const body = new FormData()
    body.append('file', file)
    preview.value = (await api<{ data: typeof preview.value }>(erpCatalogRoutes.importProductsPreview, { method: 'POST', body })).data
  } catch (err) {
    message.value = apiFailure(err).message
  } finally {
    busy.value = false
    input.value = ''
  }
}
</script>

<template>
  <ErpCatalogPageShell title="Importação" description="Pré-visualização com detecção de duplicados; confirmação explícita na API.">
    <UAlert
      color="neutral"
      variant="subtle"
      title="CSV"
      description="Cabeçalho: name, sku, sale_price (produtos)."
    />
    <input
      type="file"
      accept=".csv,text/csv"
      :disabled="busy"
      @change="previewProducts"
    >
    <UAlert v-if="message" color="error" :title="message" />
    <UCard v-if="preview">
      <template #header>
        Pré-visualização ({{ preview.rows.length }} linhas)
      </template>
      <ul v-if="preview.errors.length" class="text-error text-sm">
        <li v-for="err in preview.errors" :key="err.line">
          Linha {{ err.line }}: {{ err.message }}
        </li>
      </ul>
      <pre class="text-xs">{{ JSON.stringify(preview.rows, null, 2) }}</pre>
    </UCard>
  </ErpCatalogPageShell>
</template>
