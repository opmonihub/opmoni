<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { processingList } = useFiscal()
const rows = ref<Awaited<ReturnType<typeof processingList>>['data']>([])
const summary = ref({ active: 0, deferred: 0, attention: 0 })
const pending = ref(true)
const error = ref<string | null>(null)

async function refresh() {
  pending.value = true
  error.value = null
  try {
    const response = await processingList()
    rows.value = response.data
    summary.value = response.summary
  } catch {
    error.value = 'Não foi possível atualizar o processamento.'
  } finally {
    pending.value = false
  }
}

let timer: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  refresh()
  if (import.meta.client) {
    timer = setInterval(() => {
      if (document.visibilityState === 'visible') {
        refresh()
      }
    }, 30_000)
  }
})

onBeforeUnmount(() => {
  if (timer !== undefined) {
    clearInterval(timer)
  }
})
</script>

<template>
  <div class="space-y-4 p-4">
    <div class="flex items-center justify-between gap-4">
      <h1 class="text-xl font-semibold">
        Processamento fiscal
      </h1>
      <NuxtLink to="/fiscal/documentos" class="text-sm underline">
        Documentos prontos
      </NuxtLink>
    </div>

    <p class="text-sm text-muted">
      A ciência da emissão (210210) não confirma a operação. O destinatário permanece responsável por manifestação conclusiva no prazo legal aplicável.
    </p>

    <div v-if="error" class="text-sm text-red-600">
      {{ error }}
    </div>

    <div class="flex gap-3 text-sm">
      <span>Ativos: {{ summary.active }}</span>
      <span>Adiados: {{ summary.deferred }}</span>
      <span>Atenção: {{ summary.attention }}</span>
    </div>

    <div v-if="pending">
      Carregando…
    </div>
    <ul v-else class="divide-y rounded border">
      <li v-for="row in rows" :key="String(row.chave_acesso)" class="p-3 text-sm">
        <div class="font-medium">
          {{ row.client_name ?? 'Cliente' }}
        </div>
        <div class="break-all text-muted">
          {{ row.chave_acesso }}
        </div>
        <div>
          {{ row.processing_state ?? row.storage_state }} — {{ row.processing_reason ?? '—' }}
        </div>
      </li>
      <li v-if="rows.length === 0" class="p-3 text-sm text-muted">
        Nenhum item em processamento.
      </li>
    </ul>
  </div>
</template>
