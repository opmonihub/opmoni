<script setup lang="ts">
const props = defineProps<{
  message?: string
  color?: 'error' | 'success' | 'warning' | 'info'
  pending: boolean
  canRetry: boolean
  busy: boolean
}>()
const emit = defineEmits<{ reconcile: [], retry: [], dismiss: [] }>()
const effectiveColor = computed(() => props.color ?? (props.message ? 'error' : 'warning'))
</script>

<template>
  <section
    v-if="message || pending"
    class="space-y-2 rounded-md border p-3"
    :class="effectiveColor === 'success' ? 'border-success/40 bg-success/5' : effectiveColor === 'warning' ? 'border-warning/40 bg-warning/5' : effectiveColor === 'info' ? 'border-info/40 bg-info/5' : 'border-error/40 bg-error/5'"
    aria-label="Estado da operação de compra"
  >
    <UAlert v-if="message" :color="effectiveColor" :title="message" />
    <p v-if="pending" class="text-sm text-muted">
      A operação original permanece guardada nesta sessão.
    </p>
    <div v-if="pending" class="flex flex-wrap gap-2">
      <UButton
        size="xs"
        color="neutral"
        variant="outline"
        label="Consultar operação"
        :loading="busy"
        :disabled="busy"
        @click="emit('reconcile')"
      />
      <UButton
        v-if="canRetry"
        size="xs"
        label="Repetir conteúdo original"
        :loading="busy"
        :disabled="busy"
        @click="emit('retry')"
      />
    </div>
    <UButton
      v-else-if="message"
      size="xs"
      color="neutral"
      variant="ghost"
      label="Dispensar aviso"
      @click="emit('dismiss')"
    />
  </section>
</template>
