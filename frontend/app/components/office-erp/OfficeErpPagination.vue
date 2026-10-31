<script setup lang="ts">
withDefaults(defineProps<{ total: number, label: string, loading?: boolean }>(), { loading: false })
const page = defineModel<number>('page', { required: true })
const perPage = defineModel<25 | 50 | 100>('perPage', { required: true })
</script>

<template>
  <footer class="flex flex-col gap-2 pt-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
    <p class="min-w-0 text-sm text-muted" aria-live="polite" :aria-busy="loading">
      <template v-if="loading && total === 0">
        Carregando resultados…
      </template>
      <template v-else>
        {{ total }} {{ label }} · página {{ page }} de {{ Math.max(1, Math.ceil(total / perPage)) }}
      </template>
    </p>
    <div class="flex flex-wrap items-center justify-between gap-3 sm:justify-end">
      <USelect
        v-if="total > 25 || perPage !== 25"
        v-model="perPage"
        :items="[{ label: '25 por página', value: 25 }, { label: '50 por página', value: 50 }, { label: '100 por página', value: 100 }]"
        aria-label="Registros por página"
        :disabled="loading"
        size="sm"
        class="w-36"
      />
      <UPagination
        v-model:page="page"
        :total="total"
        :items-per-page="perPage"
        :disabled="loading"
        :sibling-count="0"
        :show-edges="false"
        size="sm"
      />
    </div>
  </footer>
</template>
