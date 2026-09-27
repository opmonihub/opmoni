<script setup lang="ts">
import type { PortfolioAttentionItem } from '~/types/client'
import { customerListPath } from '~/utils/customerRoutes'
import { deadlineStatusAppearance, deadlineStatusLabel } from '~/utils/portfolioLabels'
import { formatDate } from '~/utils'
import { formatTaxId } from '~/utils/taxId'

const props = defineProps<{
  certificate: PortfolioAttentionItem[]
  poa: PortfolioAttentionItem[]
  loading?: boolean
}>()

const certificateQuery = ref('')
const poaQuery = ref('')

function filterItems(items: PortfolioAttentionItem[], query: string) {
  const term = query.trim().toLowerCase()
  if (!term) return items
  return items.filter((item) => {
    const taxId = item.tax_id ?? ''
    return item.name.toLowerCase().includes(term)
      || taxId.includes(term.replace(/\D+/g, ''))
  })
}

const certificateRows = computed(() => filterItems(props.certificate, certificateQuery.value))
const poaRows = computed(() => filterItems(props.poa, poaQuery.value))
</script>

<template>
  <div class="grid min-w-0 gap-3 lg:grid-cols-2">
    <UCard
      class="min-w-0 overflow-hidden ring ring-default"
      :ui="{
        header: 'border-b border-default bg-elevated/25 px-3 py-3 sm:px-4',
        body: 'p-0 sm:p-0'
      }"
    >
      <template #header>
        <div class="flex min-w-0 items-center gap-2">
          <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-warning/10 text-warning ring ring-inset ring-warning/20">
            <UIcon name="i-lucide-key-round" class="size-4" />
          </span>
          <h3 class="min-w-0 flex-1 truncate text-sm font-semibold text-highlighted">
            Certificados vencendo
          </h3>
          <UInput
            v-model="certificateQuery"
            icon="i-lucide-search"
            placeholder="Buscar"
            size="sm"
            class="hidden w-40 sm:block"
          />
          <UButton
            label="Ver todos"
            trailing-icon="i-lucide-arrow-up-right"
            color="neutral"
            variant="ghost"
            size="sm"
            class="shrink-0"
            :to="customerListPath('certificate', 'expiring')"
          />
        </div>
      </template>

      <div class="border-b border-default p-3 sm:hidden">
        <UInput
          v-model="certificateQuery"
          icon="i-lucide-search"
          placeholder="Buscar cliente"
          size="sm"
        />
      </div>

      <div v-if="loading" class="space-y-2 p-4">
        <USkeleton v-for="n in 3" :key="n" class="h-10 w-full" />
      </div>

      <div
        v-else-if="certificate.length === 0"
        class="flex items-center gap-3 px-4 py-8 text-sm text-muted"
      >
        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-success/10 text-success">
          <UIcon name="i-lucide-circle-check" class="size-4" />
        </span>
        Certificados em dia
      </div>

      <div
        v-else-if="certificateRows.length === 0"
        class="flex items-center gap-3 px-4 py-8 text-sm text-muted"
      >
        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-elevated text-muted">
          <UIcon name="i-lucide-search" class="size-4" />
        </span>
        Nenhum resultado
      </div>

      <ul v-else class="max-h-64 divide-y divide-default overflow-y-auto">
        <li
          v-for="item in certificateRows"
          :key="`cert-${item.id}`"
          class="flex min-h-14 items-center justify-between gap-3 px-4 py-2.5"
        >
          <div class="min-w-0">
            <p class="truncate text-sm font-medium text-highlighted">
              {{ item.name }}
            </p>
            <p class="mt-0.5 truncate text-xs tabular-nums text-muted">
              {{ formatTaxId(item.tax_id) }} · {{ formatDate(item.expires_at) }}
            </p>
          </div>
          <UBadge
            :label="deadlineStatusLabel[item.status]"
            :color="deadlineStatusAppearance[item.status].color"
            variant="subtle"
            size="sm"
            class="shrink-0"
          />
        </li>
      </ul>
    </UCard>

    <UCard
      class="min-w-0 overflow-hidden ring ring-default"
      :ui="{
        header: 'border-b border-default bg-elevated/25 px-3 py-3 sm:px-4',
        body: 'p-0 sm:p-0'
      }"
    >
      <template #header>
        <div class="flex min-w-0 items-center gap-2">
          <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-warning/10 text-warning ring ring-inset ring-warning/20">
            <UIcon name="i-lucide-file-key-2" class="size-4" />
          </span>
          <h3 class="min-w-0 flex-1 truncate text-sm font-semibold text-highlighted">
            Procurações vencendo
          </h3>
          <UInput
            v-model="poaQuery"
            icon="i-lucide-search"
            placeholder="Buscar"
            size="sm"
            class="hidden w-40 sm:block"
          />
          <UButton
            label="Ver todos"
            trailing-icon="i-lucide-arrow-up-right"
            color="neutral"
            variant="ghost"
            size="sm"
            class="shrink-0"
            :to="customerListPath('poa', 'expiring')"
          />
        </div>
      </template>

      <div class="border-b border-default p-3 sm:hidden">
        <UInput
          v-model="poaQuery"
          icon="i-lucide-search"
          placeholder="Buscar cliente"
          size="sm"
        />
      </div>

      <div v-if="loading" class="space-y-2 p-4">
        <USkeleton v-for="n in 3" :key="n" class="h-10 w-full" />
      </div>

      <div
        v-else-if="poa.length === 0"
        class="flex items-center gap-3 px-4 py-8 text-sm text-muted"
      >
        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-success/10 text-success">
          <UIcon name="i-lucide-circle-check" class="size-4" />
        </span>
        Procurações em dia
      </div>

      <div
        v-else-if="poaRows.length === 0"
        class="flex items-center gap-3 px-4 py-8 text-sm text-muted"
      >
        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-elevated text-muted">
          <UIcon name="i-lucide-search" class="size-4" />
        </span>
        Nenhum resultado
      </div>

      <ul v-else class="max-h-64 divide-y divide-default overflow-y-auto">
        <li
          v-for="item in poaRows"
          :key="`poa-${item.id}`"
          class="flex min-h-14 items-center justify-between gap-3 px-4 py-2.5"
        >
          <div class="min-w-0">
            <p class="truncate text-sm font-medium text-highlighted">
              {{ item.name }}
            </p>
            <p class="mt-0.5 truncate text-xs tabular-nums text-muted">
              {{ formatTaxId(item.tax_id) }} · {{ formatDate(item.expires_at) }}
            </p>
          </div>
          <UBadge
            :label="deadlineStatusLabel[item.status]"
            :color="deadlineStatusAppearance[item.status].color"
            variant="subtle"
            size="sm"
            class="shrink-0"
          />
        </li>
      </ul>
    </UCard>
  </div>
</template>
