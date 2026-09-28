<script setup lang="ts">
import type { MonitoringMessageStub } from '~/types/serpro'
import { formatMonitoringDate, monitoringDeadlinePassed } from '~/utils/monitoringPresentation'

const props = defineProps<{
  message: MonitoringMessageStub
  clientName: string
}>()

const emit = defineEmits<{ open: [] }>()

/** An office that has missed a deadline has to see that it missed one. */
const prazo = computed(() => {
  const passed = monitoringDeadlinePassed(props.message.prazo_limite)
  return {
    label: `${passed ? 'Prazo vencido em' : 'Prazo'} ${formatMonitoringDate(props.message.prazo_limite)}`,
    class: passed ? 'font-medium text-error' : 'text-muted'
  }
})

/** The same wording on both layouts, so a phone does not read as a different act. */
const action = computed(() => {
  const label = props.message.ciencia_em ? 'Ver mensagem' : 'Abrir mensagem'
  return { label, ariaLabel: `${label}: ${props.message.assunto} (${props.clientName})` }
})
</script>

<template>
  <!--
    The subject the office is about to consent to, beside the action. Showing
    the action without naming the message would ask for the act before showing
    what the act is about. The button never reaches the body: `MessageDetail`
    asks for consent first.
  -->
  <div class="flex min-w-0 flex-col gap-1.5">
    <span class="truncate text-xs text-muted" :title="message.assunto">
      {{ message.assunto }}
    </span>
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
      <UButton
        size="xs"
        color="neutral"
        variant="outline"
        icon="i-lucide-mail-open"
        :label="action.label"
        :aria-label="action.ariaLabel"
        @click="emit('open')"
      />
      <span v-if="message.ciencia_em" class="text-xs text-muted tabular-nums">
        Ciência {{ formatMonitoringDate(message.ciencia_em) }}
      </span>
      <span
        v-if="message.prazo_limite"
        class="text-xs tabular-nums"
        :class="prazo.class"
      >
        {{ prazo.label }}
      </span>
    </div>
  </div>
</template>
