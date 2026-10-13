<script setup lang="ts">
import type { DeadlineStatus } from '~/types/client'
import { deadlineBadgePresentation } from '~/utils/portfolioLabels'

const props = defineProps<{
  status: DeadlineStatus
  value?: string | null
  kind: 'certificate' | 'poa'
  actionable?: boolean
}>()

const emit = defineEmits<{ action: [] }>()

const documentLabel = computed(() => props.kind === 'certificate' ? 'certificado A1' : 'procuração e-CAC')

const badge = computed(() => {
  const date = props.status !== 'missing' && props.value ? formatDate(props.value) : undefined
  return deadlineBadgePresentation(props.status, date)
})

const expiredOn = computed(() => props.value ? formatDate(props.value) : null)
</script>

<template>
  <UButton
    v-if="actionable && status === 'missing'"
    size="xs"
    color="neutral"
    variant="outline"
    icon="i-lucide-plus"
    label="Cadastrar"
    class="max-w-full"
    :aria-label="`Cadastrar ${documentLabel}`"
    @click.stop="emit('action')"
  />
  <UButton
    v-else-if="actionable && status === 'expired'"
    size="xs"
    color="error"
    variant="outline"
    icon="i-lucide-refresh-cw"
    label="Atualizar"
    class="max-w-full"
    :title="expiredOn ? `Vencido em ${expiredOn}` : 'Vencido'"
    :aria-label="expiredOn ? `Atualizar ${documentLabel} vencido em ${expiredOn}` : `Atualizar ${documentLabel}`"
    @click.stop="emit('action')"
  />
  <UBadge
    v-else
    class="max-w-full shrink-0"
    variant="subtle"
    :color="badge.color"
    :icon="badge.icon"
    :label="badge.label"
    :title="badge.title"
    :ui="{ base: 'max-w-full', label: 'truncate tabular-nums' }"
  />
</template>
