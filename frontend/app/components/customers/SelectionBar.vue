<script setup lang="ts">
const props = defineProps<{
  count: number
  disabled?: boolean
}>()

const emit = defineEmits<{
  clear: []
  tags: []
}>()

const countLabel = computed(() => new Intl.NumberFormat('pt-BR').format(props.count))
const noun = computed(() => props.count === 1 ? 'cliente selecionado' : 'clientes selecionados')
</script>

<template>
  <div class="flex max-w-full items-center gap-2 rounded-xl bg-default px-2.5 py-2 shadow-lg ring ring-default sm:gap-3 sm:px-3">
    <div class="flex min-w-0 items-center gap-2">
      <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-md bg-elevated px-1.5 text-sm font-semibold text-highlighted tabular-nums">
        {{ countLabel }}
      </span>
      <p class="truncate text-sm text-muted">
        {{ noun }}
      </p>
    </div>

    <div class="flex shrink-0 items-center gap-1">
      <UButton
        label="Tags"
        icon="i-lucide-tags"
        color="neutral"
        variant="outline"
        size="sm"
        :disabled="disabled"
        @click="emit('tags')"
      />
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
</template>
