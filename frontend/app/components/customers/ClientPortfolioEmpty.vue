<script setup lang="ts">
import type { ClientPortfolioDocument, DeadlineStatus } from '~/types/client'
import { customerListPath } from '~/utils/customerRoutes'

const props = defineProps<{
  document: ClientPortfolioDocument
  status: DeadlineStatus | 'all'
  portfolioTotal?: number
  search: string
  hasFilters: boolean
  canManageClients: boolean
}>()

const emit = defineEmits<{
  'create': []
  'clear-filters': []
  'clear-search': []
  'refresh': []
}>()

const hasSearch = computed(() => !!props.search)
const filtered = computed(() => hasSearch.value || props.hasFilters)
const portfolioEmpty = computed(() => !filtered.value && (props.portfolioTotal === 0 || props.status === 'all'))

const statusTitles: Record<ClientPortfolioDocument, Record<DeadlineStatus, string>> = {
  certificate: {
    expiring: 'Nenhum certificado A1 a vencer',
    expired: 'Nenhum certificado A1 vencido',
    valid: 'Nenhum certificado A1 válido',
    missing: 'Nenhum cliente sem certificado A1'
  },
  poa: {
    expiring: 'Nenhuma procuração e-CAC a vencer',
    expired: 'Nenhuma procuração e-CAC vencida',
    valid: 'Nenhuma procuração e-CAC válida',
    missing: 'Nenhum cliente sem procuração e-CAC'
  }
}

const presentation = computed(() => {
  if (filtered.value) {
    return {
      title: 'Nenhum cliente encontrado',
      description: 'Ajuste a busca ou limpe os filtros.'
    }
  }

  if (portfolioEmpty.value) {
    return {
      title: 'Sua carteira ainda está vazia',
      description: props.canManageClients
        ? 'Cadastre seu primeiro cliente para acompanhar certificados A1 e procurações e-CAC em um só lugar.'
        : 'Os clientes cadastrados aparecerão aqui, com seus certificados A1 e procurações e-CAC.'
    }
  }

  return {
    title: statusTitles[props.document][props.status as DeadlineStatus]
  }
})
</script>

<template>
  <div
    role="status"
    aria-live="polite"
    class="min-w-0"
  >
    <UEmpty
      v-bind="presentation"
      variant="naked"
      size="md"
      class="w-full gap-2 py-6 sm:py-8 lg:py-8"
      :ui="{
        header: 'max-w-lg gap-1',
        title: 'text-sm font-medium text-toned',
        description: 'text-sm text-muted',
        actions: 'gap-2'
      }"
    >
      <template #actions>
        <template v-if="filtered">
          <UButton
            v-if="hasFilters"
            label="Limpar filtros"
            icon="i-lucide-filter-x"
            color="neutral"
            variant="outline"
            @click="emit('clear-filters')"
          />
          <UButton
            v-if="hasSearch"
            label="Limpar busca"
            icon="i-lucide-search-x"
            color="neutral"
            variant="outline"
            @click="emit('clear-search')"
          />
        </template>
        <UButton
          v-else-if="!portfolioEmpty"
          label="Ver todos os clientes"
          color="neutral"
          variant="ghost"
          size="sm"
          :to="customerListPath(document)"
        />
        <UButton
          v-else-if="canManageClients"
          label="Novo cliente"
          icon="i-lucide-plus"
          @click="emit('create')"
        />
        <UButton
          v-else
          label="Atualizar"
          icon="i-lucide-refresh-cw"
          color="neutral"
          variant="outline"
          @click="emit('refresh')"
        />
      </template>
    </UEmpty>
  </div>
</template>
