<script setup lang="ts">
import type { ErpBrandRecord, ErpCatalogEditorMode } from '#shared/types/erpCatalogs'
import ErpCatalogField from '~/components/erp/catalogs/ErpCatalogField.vue'

const props = defineProps<{
  record: ErpBrandRecord | null
  mode: ErpCatalogEditorMode
  canSave: boolean
  busy: boolean
  fieldErrors?: Record<string, string[]>
}>()
const emit = defineEmits<{ save: [payload: Record<string, unknown>] }>()
const name = ref('')
const disabled = computed(() => props.mode === 'detail' || !props.canSave || props.busy)

watch(() => [props.record, props.mode] as const, ([record]) => {
  name.value = record?.name ?? ''
}, { immediate: true })

function submit() {
  if (!disabled.value)
    emit('save', { name: name.value })
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
    <fieldset :disabled="disabled">
      <ErpCatalogField label="Marca" required :error="fieldErrors?.name?.[0]">
        <UInput
          v-model="name"
          name="name"
          required
          maxlength="255"
          class="w-full"
        />
      </ErpCatalogField>
    </fieldset>
    <div v-if="mode !== 'detail'" class="flex justify-end">
      <UButton
        type="submit"
        :label="mode === 'create' ? 'Criar marca' : 'Salvar marca'"
        :disabled="disabled"
        :loading="busy"
      />
    </div>
  </form>
</template>
