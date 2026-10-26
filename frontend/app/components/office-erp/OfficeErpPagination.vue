<script setup lang="ts">
withDefaults(defineProps<{ total: number, label: string, loading?: boolean }>(), { loading: false })
const page = defineModel<number>('page', { required: true })
const perPage = defineModel<25 | 50 | 100>('perPage', { required: true })
</script>

<template>
  <footer class="flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
    <p class="text-sm text-muted" aria-live="polite">
      {{ total }} {{ label }} · página {{ page }} de {{ Math.max(1, Math.ceil(total / perPage)) }}
    </p>
    <div class="flex flex-wrap items-center justify-between gap-3 sm:justify-end">
      <USelect
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
