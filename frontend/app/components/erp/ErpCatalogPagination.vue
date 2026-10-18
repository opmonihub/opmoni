<script setup lang="ts">
import type { ErpPaginated } from '#shared/types/erpCatalogs'

defineProps<{
  meta?: ErpPaginated<unknown>['meta']
  pending: boolean
  error?: string
}>()
const page = defineModel<number>('page', { required: true })
defineEmits<{ retry: [] }>()
</script>

<template>
  <div class="space-y-3">
    <UAlert v-if="error" color="error" :title="error">
      <template #actions>
        <UButton
          label="Tentar novamente"
          color="error"
          variant="soft"
          :loading="pending"
          @click="$emit('retry')"
        />
      </template>
    </UAlert>
    <div v-if="meta && meta.last_page > 1" class="flex justify-end">
      <UPagination
        v-model:page="page"
        :total="meta.total"
        :items-per-page="meta.per_page"
        :disabled="pending"
      />
    </div>
  </div>
</template>
