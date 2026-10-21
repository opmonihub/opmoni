<script setup lang="ts">
defineProps<{
  message?: string
  pending: boolean
  canRetry: boolean
  busy: boolean
}>()
const emit = defineEmits<{ reconcile: [], retry: [], dismiss: [] }>()
</script>

<template>
  <section v-if="message || pending" class="space-y-2 rounded-md border border-warning/40 bg-warning/5 p-3" aria-label="Estado da operação de compra">
    <UAlert v-if="message" color="error" :title="message" />
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
