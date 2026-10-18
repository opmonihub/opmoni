<script setup lang="ts">
import type { ErpPaginated } from '#shared/types/erpCatalogs'

defineProps<{
  meta?: ErpPaginated<unknown>['meta']
  pending: boolean
  error?: string
  loaded: boolean
  hasRows: boolean
  stale: boolean
  searchPending: boolean
  authorityBlocked: boolean
  search: string
}>()
const page = defineModel<number>('page', { required: true })
defineEmits<{ retry: [] }>()
</script>

<template>
  <div class="space-y-3">
    <p v-if="pending && !loaded" role="status" class="text-sm text-muted">
      Carregando registros...
    </p>
    <p v-else-if="searchPending" role="status" class="text-sm text-muted">
      Aguardando a busca atual; mostrando os resultados confirmados anteriores.
    </p>
    <p v-else-if="loaded && pending" role="status" class="text-sm text-muted">
      Atualizando lista; mostrando a última leitura confirmada.
    </p>
    <p v-else-if="loaded && stale && error" role="status" class="text-sm text-warning">
      Lista desatualizada; mostrando os dados da última leitura confirmada.
    </p>
    <p v-else-if="loaded && !hasRows && !stale && !error" role="status" class="text-sm text-muted">
      {{ search.trim() ? 'Nenhum registro corresponde à busca.' : 'Nenhum registro encontrado.' }}
    </p>
    <UAlert v-if="error" color="error" :title="error">
      <template #actions>
        <UButton
          :label="authorityBlocked ? 'Revalidar acesso' : 'Tentar novamente'"
          color="error"
          variant="soft"
          :loading="pending"
          @click="$emit('retry')"
        />
      </template>
    </UAlert>
    <div v-if="meta && meta.last_page > 1" class="flex justify-end">
      <UPagination
        v-model:page="page"
        :total="meta.total"
        :items-per-page="meta.per_page"
        :disabled="pending"
      />
    </div>
  </div>
</template>
