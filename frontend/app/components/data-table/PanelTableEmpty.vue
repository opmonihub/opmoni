<script setup lang="ts">
import { panelTableEmptyClass } from '~/components/data-table/panel'

/**
 * `#empty` body for a `panelTableUi` table.
 *
 * Seven Admin tables each hand-rolled this same muted icon-plus-sentence block
 * while the rest of the app used `UEmpty variant="naked"`. It is kept as-is on
 * purpose: this is an extraction, so the panel tables keep the exact density they
 * shipped with. The two idioms are a real inconsistency in the product and are
 * worth a decision, not a silent swap.
 */
defineProps<{
  icon: string
  label: string
}>()
</script>

<template>
  <div :class="panelTableEmptyClass">
    <UIcon :name="icon" class="size-6" />
    <span>{{ label }}</span>
  </div>
</template>
