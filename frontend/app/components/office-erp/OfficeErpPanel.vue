<script setup lang="ts">
import DataTablePanelList from '~/components/data-table/PanelList.vue'
import { companyListPanelUi } from '~/components/office-erp/companyList'

defineProps<{ title: string, description?: string, companyList?: boolean }>()
</script>

<template>
  <DataTablePanelList :title="title" :description="description" :ui="companyList ? companyListPanelUi : undefined">
    <template v-if="$slots.action" #action>
      <div class="flex flex-wrap items-center gap-2">
        <slot name="action" />
      </div>
    </template>
    <template v-if="$slots.toolbar" #toolbar>
      <slot name="toolbar" />
    </template>
    <slot />
  </DataTablePanelList>
  <p v-if="$slots.hint" class="flex items-start gap-2 text-xs leading-5 text-muted">
    <slot name="hint" />
  </p>
</template>
