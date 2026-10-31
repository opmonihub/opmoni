<script setup lang="ts">
import DataTablePanelList from '~/components/data-table/PanelList.vue'
import { officeErpListToolbarClass } from '~/components/office-erp/companyList'

defineProps<{ title: string, description?: string, companyList?: boolean, list?: boolean, stackedToolbar?: boolean }>()
</script>

<template>
  <section v-if="list || companyList" :aria-label="title" class="flex min-w-0 flex-col gap-4">
    <header :class="stackedToolbar ? 'flex min-w-0 flex-col items-stretch gap-3' : 'flex flex-wrap items-center justify-between gap-3'">
      <div class="flex min-w-0 items-center gap-1.5">
        <h2 class="text-base font-semibold text-highlighted">
          <slot name="title">
            {{ title }}
          </slot>
        </h2>
        <UPopover v-if="!$slots.title && (description || $slots.hint)" :content="{ align: 'start' }">
          <UButton
            color="neutral"
            variant="ghost"
            size="sm"
            icon="i-lucide-circle-help"
            :aria-label="`Sobre ${title.toLocaleLowerCase('pt-BR')}`"
            class="min-h-9 min-w-9 justify-center"
          />
          <template #content>
            <div class="max-w-72 space-y-2 p-3 text-sm text-default">
              <p v-if="description">
                {{ description }}
              </p>
              <div v-if="$slots.hint" class="flex items-start gap-2">
                <slot name="hint" />
              </div>
            </div>
          </template>
        </UPopover>
      </div>
      <template v-if="stackedToolbar">
        <div aria-label="Controles da lista" class="flex min-w-0 flex-wrap items-center gap-2">
          <div v-if="$slots.toolbar" class="w-full min-w-0 sm:w-auto sm:flex-1">
            <slot name="toolbar" />
          </div>
          <div v-if="$slots.action" class="flex shrink-0 items-center gap-2">
            <slot name="action" />
          </div>
        </div>
      </template>
      <div v-else-if="$slots.toolbar || $slots.action" aria-label="Controles da lista" class="flex w-full min-w-0 flex-wrap items-center gap-2 lg:w-auto lg:justify-end">
        <div v-if="$slots.toolbar" :class="officeErpListToolbarClass">
          <slot name="toolbar" />
        </div>
        <div v-if="$slots.action" class="flex shrink-0 flex-wrap items-center gap-2">
          <slot name="action" />
        </div>
      </div>
    </header>
    <slot />
  </section>
  <template v-else>
    <DataTablePanelList :title="title" :description="description">
      <template v-if="$slots.title" #title>
        <slot name="title" />
      </template>
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
</template>
