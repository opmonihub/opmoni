<script setup lang="ts">
import { apiFailure } from '#shared/utils/access'

const { api } = useAccessApi()
const pending = ref(false)
const error = ref('')
type XmlPreview = {
  chave: string
  emit: { name: string, cpf_cnpj: string }
  dest: { name: string, cpf_cnpj: string }
  det: Array<{ codigo: string, xProd: string, qCom: string, uCom: string, vUnCom: string }>
}
const preview = ref<XmlPreview | null>(null)
let generation = 0
onScopeDispose(() => {
  generation++
})

async function inspect(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || pending.value) return
  const version = ++generation
  pending.value = true
  error.value = ''
  preview.value = null
  try {
    if (file.size > 5 * 1024 * 1024) {
      error.value = 'Selecione um XML de até 5 MB.'
      return
    }
    const xml = await file.text()
    if (version !== generation) return
    const response = await api<{ data: XmlPreview }>('/erp/purchases/xml-preview', { method: 'POST', body: { xml } })
    if (version === generation) preview.value = response.data
  } catch (failure) {
    if (version === generation) error.value = apiFailure(failure).message
  } finally {
    if (version === generation) pending.value = false
    input.value = ''
  }
}
</script>

<template>
  <UCard class="space-y-4">
    <p class="text-muted text-sm">
      Selecione o XML de entrada para conferir o destinatário e os dados. Esta prévia não lança a compra.
    </p>
    <UFormField label="XML da compra" name="xml">
      <input
        type="file"
        accept=".xml,application/xml,text/xml"
        :disabled="pending"
        @change="inspect"
      >
    </UFormField>
    <p v-if="pending" role="status" class="text-muted text-sm">
      Conferindo XML…
    </p>
    <UAlert v-if="error" color="error" :title="error" />
    <div v-if="preview" class="space-y-3">
      <UAlert color="success" title="Prévia validada" />
      <dl class="grid gap-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-muted">
            Fornecedor
          </dt><dd>{{ preview.emit.name }} · {{ preview.emit.cpf_cnpj }}</dd>
        </div>
        <div>
          <dt class="text-muted">
            Destinatário
          </dt><dd>{{ preview.dest.name }} · {{ preview.dest.cpf_cnpj }}</dd>
        </div>
        <div class="sm:col-span-2">
          <dt class="text-muted">
            Chave da nota
          </dt><dd class="break-all">
            {{ preview.chave }}
          </dd>
        </div>
      </dl>
      <UTable
        :data="preview.det"
        :columns="[
          { accessorKey: 'codigo', header: 'Código' },
          { accessorKey: 'xProd', header: 'Produto' },
          { accessorKey: 'qCom', header: 'Quantidade' },
          { accessorKey: 'uCom', header: 'Unidade' },
          { accessorKey: 'vUnCom', header: 'Preço unitário' }
        ]"
      />
    </div>
  </UCard>
</template>
