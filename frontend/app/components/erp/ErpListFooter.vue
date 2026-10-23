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
      aria-label="Paginação de registros"
      :page="page"
      :total="meta.total"
      :items-per-page="meta.per_page"
      :disabled="pending"
      :sibling-count="1"
      :ui="{ list: 'flex-wrap' }"
      @update:page="changePage"
    >
      <template #first>
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-chevrons-left"
          aria-label="Primeira página"
        />
      </template>
      <template #prev>
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-chevron-left"
          aria-label="Página anterior"
        />
      </template>
      <template #item="{ item, page: currentPage }">
        <UButton
          v-if="item.type === 'page'"
          :color="currentPage === item.value ? 'primary' : 'neutral'"
          :variant="currentPage === item.value ? 'solid' : 'outline'"
          :label="String(item.value)"
          :aria-label="`Página ${item.value}`"
          :ui="{ label: 'min-w-5 text-center' }"
          square
        />
      </template>
      <template #next>
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-chevron-right"
          aria-label="Próxima página"
        />
      </template>
      <template #last>
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-chevrons-right"
          aria-label="Última página"
        />
      </template>
    </UPagination>
  </div>
</template>
