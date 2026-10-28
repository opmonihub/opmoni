<script setup lang="ts">
const props = withDefaults(defineProps<{
  count: number
  label: string
  disabled?: boolean
  floating?: boolean
}>(), { disabled: false, floating: false })

const emit = defineEmits<{ clear: [] }>()
const countLabel = computed(() => new Intl.NumberFormat('pt-BR').format(props.count))
</script>

<template>
  <div :class="floating ? 'pointer-events-none fixed inset-x-0 bottom-3 z-40 flex justify-center px-3 sm:bottom-5' : undefined">
    <div class="pointer-events-auto flex max-w-full items-center gap-2 rounded-xl bg-default px-2.5 py-2 shadow-lg ring ring-default sm:gap-3 sm:px-3">
      <div class="flex min-w-0 items-center gap-2" role="status" aria-live="polite">
        <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-md bg-elevated px-1.5 text-sm font-semibold text-highlighted tabular-nums">
          {{ countLabel }}
        </span>
        <p class="truncate text-sm text-muted">
          {{ label }}
        </p>
      </div>

      <div class="flex shrink-0 items-center gap-1">
        <slot />
        <UButton
          icon="i-lucide-x"
          color="neutral"
          variant="ghost"
          size="sm"
          :disabled="disabled"
          aria-label="Cancelar seleção"
          @click="emit('clear')"
        />
      </div>
    </div>
  </div>
</template>
