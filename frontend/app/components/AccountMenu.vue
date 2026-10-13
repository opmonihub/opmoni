<script setup lang="ts">
import { apiFailure } from '#shared/utils/access'

const { user, switchAccount } = useAuth()
const busy = ref(false)
const error = ref('')
const items = computed(() => user.value?.accounts.map(a => ({ label: a.name + (a.status === 'suspended' ? ' · Suspenso' : ''), value: a.id, disabled: a.status !== 'active' })) || [])
async function change(value: unknown) {
  if (busy.value || typeof value !== 'number' || value === user.value?.current_account?.id)
    return
  busy.value = true
  error.value = ''
  try {
    await switchAccount(value)
  } catch (e) {
    error.value = apiFailure(e).message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-2">
    <USelect
      :model-value="user?.current_account?.id"
      :items="items"
      placeholder="Selecionar escritório"
      aria-label="Selecionar escritório"
      icon="i-lucide-building-2"
      :loading="busy"
      :disabled="busy"
      class="w-full"
      @update:model-value="change"
    /><p v-if="error" role="alert" class="text-highlighted flex items-start gap-2 text-sm">
      <UIcon name="i-lucide-circle-alert" class="text-error mt-0.5 size-4 shrink-0" aria-hidden="true" />
      <span>{{ error }}</span>
    </p>
  </div>
</template>
