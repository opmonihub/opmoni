<script setup lang="ts">
import type { DataTableFilterModel } from '~/components/data-table/filter-model'
import type { FilterPanelColumn } from '~/utils/filterPanel'
import { pageScrollClass } from '~/utils/pageShell'

/**
 * Tela só para desenvolver o painel de filtros.
 * As listas do produto usam o mesmo componente.
 */
useHead({ title: 'Filtro' })

const search = ref('')
const model = ref<DataTableFilterModel[]>([])

const columns: FilterPanelColumn[] = [
  {
    id: 'model',
    label: 'Modelo',
    icon: 'i-lucide-file-text',
    control: 'multi',
    options: [
      { label: 'NF-e', value: 'nfe' },
      { label: 'CT-e', value: 'cte' }
    ]
  },
  {
    id: 'kind',
    label: 'Tipo',
    icon: 'i-lucide-tags',
    control: 'select',
    options: [
      { label: 'Documento', value: 'document' },
      { label: 'Evento', value: 'event' }
    ]
  },
  {
    id: 'status',
    label: 'Situação',
    icon: 'i-lucide-circle-dot',
    control: 'select',
    operators: true,
    options: [
      { label: 'A fazer', value: 'todo' },
      { label: 'Concluída', value: 'done' }
    ]
  },
  {
    id: 'issuer',
    label: 'Emitente',
    icon: 'i-lucide-building-2',
    control: 'text',
    validate: draft => /^\d*$/.test(draft.text.trim()) ? null : 'Só dígitos.'
  },
  {
    id: 'issued',
    label: 'Emissão',
    icon: 'i-lucide-calendar-range',
    control: 'date-range'
  },
  {
    id: 'amount',
    label: 'Valor',
    icon: 'i-lucide-banknote',
    control: 'number-range'
  }
]

const summary = computed(() => JSON.stringify(model.value, null, 2))
</script>

<template>
  <div :class="pageScrollClass">
    <DataTableFilterPanel v-model="model" :columns="columns">
      <p class="shrink-0 text-sm leading-none font-medium text-highlighted">
        Painel de filtros
      </p>
      <UInput
        v-model="search"
        icon="i-lucide-search"
        placeholder="Buscar nº, chave ou cliente…"
        class="w-full min-w-0 flex-1"
      />
      <template #trailing>
        <UButton
          label="Ação da página"
          color="neutral"
          variant="outline"
          class="shrink-0"
        />
      </template>
    </DataTableFilterPanel>

    <p class="text-sm text-muted">
      Tela só para desenvolver o componente. A busca é da página: Limpar, dentro de Filtros, não mexe nela.
      <span v-if="search.trim()">Busca atual: {{ search.trim() }}.</span>
    </p>

    <pre class="overflow-x-auto rounded-lg bg-elevated p-3 text-xs text-muted ring ring-default">{{ summary }}</pre>
  </div>
</template>
