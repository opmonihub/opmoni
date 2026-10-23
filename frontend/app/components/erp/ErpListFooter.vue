<script setup lang="ts">
import type { ErpPaginated } from '#shared/types/erpCatalogs'

const props = withDefaults(defineProps<{
  meta: ErpPaginated<unknown>['meta']
  pending?: boolean
  summary?: string
}>(), { pending: false })
const page = defineModel<number>('page', { required: true })
const count = computed(() => `${props.meta.total.toLocaleString('pt-BR')} ${props.meta.total === 1 ? 'registro' : 'registros'}`)

function changePage(value: number) {
  if (props.pending || !Number.isInteger(value) || value < 1 || value > props.meta.last_page || value === page.value) return
  page.value = value
}
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-3 border-t border-default pt-4">
    <p class="text-sm text-muted" aria-live="polite">
      {{ summary ?? count }}
    </p>
    <UPagination
      v-if="meta.last_page > 1"
      :page="page"
      :total="meta.total"
      :items-per-page="meta.per_page"
      :disabled="pending"
      :sibling-count="1"
      :ui="{ list: 'flex-wrap' }"
      @update:page="changePage"
    />
  </div>
</template>
