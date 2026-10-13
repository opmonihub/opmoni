<script setup lang="ts">
/**
 * Loading shell that mirrors Work UCard + UTable pages
 * (clientes / processos / modelos / tarefas table mode).
 */
withDefaults(defineProps<{
  /** Visible column placeholders (excluding expand control when grouped). */
  columns?: number
  /** Number of body rows. */
  rows?: number
  /** Mimic grouped expand + indent rhythm. */
  grouped?: boolean
}>(), {
  columns: 5,
  rows: 8,
  grouped: true
})

const cellWidths = ['w-36', 'w-20', 'w-24', 'w-16', 'w-28', 'w-20', 'w-14'] as const

/** Depth pattern: group → subgroup → leaves (repeats). */
function rowDepth(index: number): number {
  const slot = index % 4
  if (slot === 0) return 0
  if (slot === 1) return 1
  return 2
}

function isGroupRow(index: number): boolean {
  return rowDepth(index) < 2
}
</script>

<template>
  <UCard
    variant="subtle"
    :ui="{ body: 'p-0 sm:p-0' }"
    aria-busy="true"
    aria-label="Carregando tabela"
  >
    <div class="overflow-x-auto">
      <div class="min-w-[36rem]">
        <div class="flex items-center gap-2 border-b border-default px-3 py-2">
          <span v-if="grouped" class="inline-block size-5 shrink-0" />
          <USkeleton
            v-for="col in columns"
            :key="`head-${col}`"
            class="h-3"
            :class="cellWidths[(col - 1) % cellWidths.length]"
          />
        </div>

        <div
          v-for="row in rows"
          :key="row"
          class="flex items-center gap-2 border-b border-default px-3 py-1.5 last:border-b-0"
        >
          <template v-if="grouped">
            <span
              class="inline-block shrink-0"
              :style="{ width: `calc(${rowDepth(row - 1)} * 1rem)` }"
            />
            <USkeleton
              v-if="isGroupRow(row - 1)"
              class="size-5 shrink-0 rounded"
            />
            <span
              v-else
              class="inline-block size-5 shrink-0"
            />
          </template>

          <USkeleton
            v-for="col in columns"
            :key="`cell-${row}-${col}`"
            class="h-3.5"
            :class="col === 1 && isGroupRow(row - 1) ? 'w-44' : cellWidths[(col - 1) % cellWidths.length]"
          />
        </div>
      </div>
    </div>
  </UCard>
</template>
