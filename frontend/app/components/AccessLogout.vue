<script setup lang="ts">
import { apiFailure } from '#shared/utils/access'

const { logout } = useAuth()
const busy = ref(false)
const error = ref('')
async function submit() {
  if (busy.value)
    return
  busy.value = true
  error.value = ''
  try {
    await logout()
  } catch (e) {
    error.value = apiFailure(e).message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-2">
    <UButton
      label="Sair"
      icon="i-lucide-log-out"
      color="neutral"
      variant="outline"
      :loading="busy"
      @click="submit"
    /><p v-if="error" role="alert" class="text-error text-sm">
      {{ error }}
    </p>
  </div>
</template>
