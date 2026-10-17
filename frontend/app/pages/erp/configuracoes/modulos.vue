<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { $api } = useNuxtApp()
const modules = ref<string[]>([])
const status = ref<string | null>(null)
const error = ref(false)

onMounted(async () => {
  try {
    const response = await $api<{ data: { status: string, plan: { modules: Array<{ module_key: string }> } } }>('/erp/subscription')
    status.value = response.data.status
    modules.value = response.data.plan.modules.map(m => m.module_key)
  } catch {
    error.value = true
  }
})
</script>

<template>
  <div class="space-y-3">
    <p v-if="error" class="text-muted text-sm">
      Selecione uma empresa com contrato ERP para ver os módulos do plano.
    </p>
    <template v-else>
      <p class="text-muted text-sm">
        Módulos incluídos no plano comercial (status: {{ status ?? '…' }}).
      </p>
      <ul class="list-inside list-disc text-sm">
        <li v-for="mod in modules" :key="mod">
          {{ mod }}
        </li>
      </ul>
    </template>
  </div>
</template>
