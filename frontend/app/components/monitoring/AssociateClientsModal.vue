<!-- app/components/monitoring/AssociateClientsModal.vue -->
<script setup lang="ts">
import type { MonitoringObligation } from '~/utils/monitoringNav'

defineProps<{ open: boolean, obligation: MonitoringObligation, associatedIds: number[] }>()
const emit = defineEmits<{ 'update:open': [value: boolean], 'associated': [] }>()
void emit
</script>

<template>
  <div />
</template>
