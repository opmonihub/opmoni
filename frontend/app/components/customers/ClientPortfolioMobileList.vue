<script setup lang="ts">
import { useInfiniteScroll } from '@vueuse/core'
import type { DropdownMenuItem } from '@nuxt/ui'
import type { ClientSheet } from '~/types/client'
import {
  clientRegimeText,
  clientSheetTaxIdLabel,
  clientStatusPresentation
} from '~/utils/portfolioLabels'

const props = defineProps<{
  rows: ClientSheet[]
  listEpoch: number
  isLoading: boolean
  canManageClients: boolean
  selectedCount: number
  canLoadMore: boolean
  isClientSelected: (id: number) => boolean
  rowActions: (client: ClientSheet) => DropdownMenuItem[]
}>()

const emit = defineEmits<{
  'load-more': []
  'set-selected': [id: number, selected: boolean | 'indeterminate']
  'open-certificate': [client: ClientSheet]
  'open-power-of-attorney': [client: ClientSheet]
  'remember-focus': []
}>()

const mobileList = useTemplateRef<HTMLElement>('mobileList')

// A new list replaces the rows, so start it at the top. nextTick matters: the
// scroll container only mounts on the same tick the rows arrive.
watch(() => props.listEpoch, async () => {
  await nextTick()
  mobileList.value?.scrollTo({ top: 0 })
})

onMounted(() => {
  useInfiniteScroll(
    mobileList,
    () => emit('load-more'),
    {
      distance: 200,
      canLoadMore: () => props.canLoadMore
    }
  )
})
</script>

<template>
  <div v-if="isLoading" class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto md:hidden">
    <USkeleton v-for="index in 4" :key="index" class="h-52 w-full rounded-lg" />
  </div>

  <div v-else-if="rows.length" class="flex min-h-0 flex-1 flex-col gap-3 md:hidden">
    <div
      ref="mobileList"
      class="min-h-0 flex-1 overflow-y-auto"
      :class="canManageClients && selectedCount ? 'pb-16' : ''"
    >
      <!--
        Cards are deliberately NOT windowed: their height is variable (the name
        wraps, the default-open collapsible adds a document block), so any fixed
        stride overlaps or leaves gaps. Rows are already server-paginated by
        `loadMore`, which bounds the list by scroll depth.
      -->
      <div class="flex flex-col gap-3">
        <UCard
          v-for="client in rows"
          :key="client.id"
          variant="subtle"
          :ui="{ root: 'overflow-visible', body: 'p-4' }"
        >
          <div class="flex items-start gap-3">
            <UCheckbox
              v-if="canManageClients"
              :model-value="isClientSelected(client.id)"
              size="lg"
              class="mt-0.5"
              :ui="{ base: 'rounded-full' }"
              :aria-label="`Selecionar ${client.name}`"
              @update:model-value="emit('set-selected', client.id, $event)"
            />
            <DataTableIdentity
              class="flex-1"
              :title="client.name"
              :meta="clientSheetTaxIdLabel(client)"
              :truncate="false"
            >
              <CustomersClientTags class="mt-1" :tags="client.tags" />
            </DataTableIdentity>
            <UDropdownMenu :items="rowActions(client)" :content="{ align: 'end' }">
              <UButton
                icon="i-lucide-ellipsis-vertical"
                color="neutral"
                variant="ghost"
                :aria-label="`Ações para ${client.name}`"
                @click="emit('remember-focus')"
              />
            </UDropdownMenu>
          </div>

          <div class="mt-3 flex items-center justify-between gap-3">
            <UBadge
              color="neutral"
              variant="subtle"
              class="min-w-0"
              :label="clientRegimeText(client.tax_regime)"
              :ui="{ base: 'min-w-0 max-w-[70%] whitespace-normal', label: 'whitespace-normal text-left' }"
            />
            <UBadge
              class="shrink-0"
              :color="clientStatusPresentation[client.status]?.color ?? 'neutral'"
              :icon="clientStatusPresentation[client.status]?.icon"
              variant="subtle"
              :label="clientStatusPresentation[client.status]?.label ?? client.status"
            />
          </div>

          <USeparator class="my-3" />

          <UCollapsible default-open>
            <template #default="{ open }">
              <UButton
                color="neutral"
                variant="ghost"
                class="w-full px-1"
                :trailing-icon="open ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                :aria-label="open ? 'Recolher certificado A1 e e-CAC' : 'Expandir certificado A1 e e-CAC'"
              >
                <span class="flex min-w-0 flex-1 items-center gap-2 text-left">
                  <UIcon name="i-lucide-badge-check" class="size-4 shrink-0 text-muted" />
                  <span class="break-words">Certificado A1 e e-CAC</span>
                </span>
              </UButton>
            </template>
            <template #content>
              <div class="mt-2 flex flex-col gap-2.5 rounded-lg bg-default p-3 ring ring-default">
                <div class="flex items-center justify-between gap-3">
                  <p class="text-sm text-muted">
                    Certificado A1
                  </p>
                  <CustomersDocumentStatus
                    :status="client.certificate_status"
                    :value="client.certificate?.valid_until"
                    kind="certificate"
                    :actionable="canManageClients"
                    @action="emit('open-certificate', client)"
                  />
                </div>
                <div class="flex items-center justify-between gap-3">
                  <p class="text-sm text-muted">
                    e-CAC
                  </p>
                  <CustomersDocumentStatus
                    :status="client.ecac_power_of_attorney_status"
                    :value="client.ecac_power_of_attorney?.expires_at"
                    kind="poa"
                    :actionable="canManageClients"
                    @action="emit('open-power-of-attorney', client)"
                  />
                </div>
              </div>
            </template>
          </UCollapsible>
        </UCard>
      </div>
    </div>
  </div>
</template>
