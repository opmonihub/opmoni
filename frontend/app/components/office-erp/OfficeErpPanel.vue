<script setup lang="ts">
import DataTablePanelList from '~/components/data-table/PanelList.vue'

defineProps<{ title: string, description?: string }>()
</script>

<template>
  <DataTablePanelList :title="title" :description="description">
    <template v-if="$slots.action" #action>
      <slot name="action" />
    </template>
    <template v-if="$slots.toolbar" #toolbar>
      <slot name="toolbar" />
    </template>
    <slot />
  </DataTablePanelList>
</template>
