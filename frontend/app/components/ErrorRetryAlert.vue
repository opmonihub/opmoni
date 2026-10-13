<script setup lang="ts">
/**
 * The one failure surface every data-backed page shows when a load or refresh
 * comes back failed: a `subtle` error alert naming what could not be loaded, one
 * recovery sentence, and a solid retry action.
 *
 * Every copy of this block was a byte-identical `UAlert` (16 files at the time of
 * extraction), which is exactly why the recovery sentence had already drifted per
 * page ("Verifique sua conexão…" vs "Verifique a conexão…"). Both live here now.
 *
 * The `v-if` stays with the caller: whether a failure is fatal to the page is a
 * page decision (several pages keep showing what they have and only toast).
 *
 * ```vue
 * <ErrorRetryAlert title="Não foi possível carregar as contas" @retry="load" />
 * ```
 */
withDefaults(defineProps<{
  /** What could not be loaded, in the product's own words. */
  title: string
  /** Override only when the failure is not a connectivity one. */
  description?: string
  /** Spins the retry action while the retry itself is in flight. */
  loading?: boolean
}>(), {
  description: 'Verifique sua conexão e tente novamente.',
  loading: false
})

defineEmits<{
  retry: []
}>()
</script>

<template>
  <UAlert
    color="error"
    variant="subtle"
    icon="i-lucide-circle-alert"
    :title="title"
    :description="description"
    :actions="[{ label: 'Tentar novamente', color: 'error', variant: 'solid', loading, onClick: () => $emit('retry') }]"
  />
</template>
