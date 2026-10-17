<script setup lang="ts">
import DataTableColumnMenu from '~/components/data-table/ColumnMenu.vue'
import WorkToolbarTeleport from '~/components/work/WorkToolbarTeleport'
import type { WorkTemplate } from '~/types/work'
import { taxRegimeLabel } from '~/utils/portfolioLabels'
import { pageScrollClass } from '~/utils/pageShell'
import { workTableUi } from '~/utils/workGroupedTable'
import type { TaxRegime } from '~/types/client'

definePageMeta({ middleware: 'auth' })

const { canManageWork } = useAuth()
const { listTemplates } = useWork()

const { data, status, error, refresh: reload } = await useAsyncData<WorkTemplate[]>(
  'work-modelos',
  () => listTemplates()
)

const templates = computed<WorkTemplate[]>(() => data.value ?? [])

/**
 * The `refresh` below is the composable's, not `reload`'s: the toolbar button and
 * the empty-state action answer through it, so a failed manual refresh toasts
 * "Não foi possível atualizar os modelos" instead of going quiet.
 */
const { isLoading, showError, refresh, retry } = useRetryableLoad({
  refresh: reload,
  error,
  loading: computed(() => status.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar os modelos',
  refreshErrorTitle: 'Não foi possível atualizar os modelos'
})

function regimeBadges(template: WorkTemplate): string[] {
  if (!template.regimes || template.regimes.length === 0) return ['Todos']
  return template.regimes.map(regime => taxRegimeLabel[regime as TaxRegime] ?? regime)
}

function recurrenceLabel(template: WorkTemplate): string {
  return `Gera dia ${template.generate_day} · vence dia ${template.due_day}`
}

function departmentSummary(template: WorkTemplate): string {
  const departments = [...new Set((template.steps ?? []).map(step => step.department?.name).filter((name): name is string => Boolean(name)))]
  if (departments.length === 0) return 'Sem departamento'
  if (departments.length <= 2) return departments.join(', ')
  return `${departments.slice(0, 2).join(', ')} +${departments.length - 2}`
}

const columnVisibility = ref<Record<string, boolean>>({})

const hideableColumns = [
  { id: 'name', label: 'Título' },
  { id: 'regimes', label: 'Regimes' },
  { id: 'tags', label: 'Categorias/Tags' },
  { id: 'steps', label: 'Departamentos' },
  { id: 'clients', label: 'Clientes' },
  { id: 'recurrence', label: 'Recorrência' }
]

function clientSummary(template: WorkTemplate): string {
  const added = (template.exceptions ?? []).filter(exception => exception.kind === 'added').length
  const removed = (template.exceptions ?? []).filter(exception => exception.kind === 'removed').length
  const tagCount = template.tags?.length ?? 0
  const parts: string[] = []
  if (tagCount > 0) parts.push(`${tagCount} tag(s)`)
  else parts.push('Pela regra')
  if (added > 0) parts.push(`+${added} incluído(s)`)
  if (removed > 0) parts.push(`-${removed} excluído(s)`)
  return parts.join(' · ')
}
</script>

<template>
  <div :class="pageScrollClass">
    <ClientOnly>
      <WorkToolbarTeleport>
        <div class="flex items-center gap-1">
          <UButton
            v-if="canManageWork"
            label="Novo modelo"
            icon="i-lucide-plus"
            color="primary"
            size="sm"
            to="/work/modelos/novo"
          />
          <UButton
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="ghost"
            aria-label="Atualizar modelos"
            :loading="isLoading"
            @click="refresh"
          />
        </div>
      </WorkToolbarTeleport>
    </ClientOnly>

    <ErrorRetryAlert
      v-if="showError"
      title="Não foi possível carregar os modelos"
      @retry="retry"
    />

    <WorkTableSkeleton
      v-else-if="isLoading && templates.length === 0"
      :columns="6"
      :rows="6"
      :grouped="false"
    />

    <UEmpty
      v-else-if="templates.length === 0"
      icon="i-lucide-shapes"
      title="Nenhum modelo cadastrado"
      description="Os modelos definem as rotinas mensais que geram um processo por cliente."
      variant="naked"
      :actions="canManageWork ? [{ label: 'Criar modelo', icon: 'i-lucide-plus', to: '/work/modelos/novo' }] : [{ label: 'Atualizar', icon: 'i-lucide-refresh-cw', onClick: () => refresh() }]"
    />

    <div v-else class="flex min-w-0 flex-col gap-3">
      <div class="flex justify-end">
        <DataTableColumnMenu
          v-model="columnVisibility"
          :columns="hideableColumns"
          class="shrink-0"
        />
      </div>
      <UCard variant="subtle" :ui="{ body: 'p-0 sm:p-0' }">
        <UTable
          v-model:column-visibility="columnVisibility"
          :data="templates"
          :columns="[
            { accessorKey: 'name', header: 'Título' },
            { accessorKey: 'regimes', header: 'Regimes' },
            { accessorKey: 'tags', header: 'Categorias/Tags' },
            { accessorKey: 'steps', header: 'Departamentos' },
            { id: 'clients', header: 'Clientes' },
            { id: 'recurrence', header: 'Recorrência' }
          ]"
          :ui="workTableUi"
        >
          <template #name-cell="{ row }">
            <div class="flex min-w-0 items-center gap-2">
              <NuxtLink :to="`/work/modelos/${row.original.id}`" class="min-w-0 flex-1 truncate text-sm font-medium text-primary hover:underline" :title="row.original.name">
                {{ row.original.name }}
              </NuxtLink>
              <UBadge
                v-if="!row.original.is_active"
                color="neutral"
                variant="subtle"
                label="Inativo"
              />
            </div>
          </template>
          <template #regimes-cell="{ row }">
            <div class="flex max-w-52 flex-wrap gap-1">
              <UBadge
                v-for="label in regimeBadges(row.original)"
                :key="label"
                color="info"
                variant="subtle"
                :label="label"
              />
            </div>
          </template>
          <template #tags-cell="{ row }">
            <div class="flex max-w-52 flex-wrap gap-1">
              <span v-if="!row.original.tags || row.original.tags.length === 0" class="text-xs text-muted">—</span>
              <UBadge
                v-for="tag in (row.original.tags ?? [])"
                :key="tag.id"
                color="neutral"
                variant="subtle"
                :label="tag.name"
              />
            </div>
          </template>
          <template #steps-cell="{ row }">
            <span class="text-sm text-muted">{{ departmentSummary(row.original) }}</span>
          </template>
          <template #clients-cell="{ row }">
            <span class="text-sm text-muted">{{ clientSummary(row.original) }}</span>
          </template>
          <template #recurrence-cell="{ row }">
            <span class="text-sm text-muted">{{ recurrenceLabel(row.original) }}</span>
          </template>
        </UTable>
      </UCard>
    </div>
  </div>
</template>
