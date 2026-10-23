<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { state, pending, canRead, canWrite, authorityKey, refresh, save } = useErpCompanySettings()
const toast = useToast()
const loadError = ref('')
const form = reactive({
  mail_host: '',
  mail_port: '',
  mail_from_address: '',
  mail_from_name: ''
})

onMounted(async () => {
  if (!state.value && canRead.value) await refreshSettings()
  sync()
})

watch(state, sync, { flush: 'sync' })
watch(authorityKey, () => {
  loadError.value = ''
  sync()
}, { flush: 'sync' })

function sync() {
  const email = (state.value?.email_settings ?? {}) as Record<string, string>
  form.mail_host = email.mail_host ?? ''
  form.mail_port = email.mail_port ?? ''
  form.mail_from_address = email.mail_from_address ?? ''
  form.mail_from_name = email.mail_from_name ?? ''
}

async function refreshSettings() {
  if (!canRead.value || pending.value) return
  const authority = authorityKey.value
  loadError.value = ''
  try {
    await refresh()
  } catch {
    if (canRead.value && authority === authorityKey.value) loadError.value = 'Não foi possível carregar as configurações.'
  }
}

async function onSubmit() {
  if (!canWrite.value || pending.value || !state.value) return
  const authority = authorityKey.value
  try {
    const response = await save({ email_settings: { ...form } })
    if (!response || !canWrite.value || authority !== authorityKey.value) return
    toast.add({ title: 'E-mail salvo', color: 'success', icon: 'i-lucide-check' })
  } catch {
    if (!canWrite.value || authority !== authorityKey.value) return
    toast.add({ title: 'Erro ao salvar', color: 'error', icon: 'i-lucide-x' })
  }
}
</script>

<template>
  <UForm :disabled="!canWrite || pending || !state" @submit.prevent="onSubmit">
    <UPageCard
      title="E-mail"
      description="settings_email.blade.php"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        v-if="canWrite"
        type="submit"
        :disabled="pending || !state"
        label="Salvar"
        :loading="pending"
        class="w-fit lg:ms-auto"
      />
    </UPageCard>
    <p v-if="!canWrite" class="mb-4 text-sm text-muted">
      {{ canRead ? 'Seu acesso permite apenas consultar estas configurações.' : 'Seu acesso não permite consultar ou alterar estas configurações.' }}
    </p>
    <div v-if="!state && canRead" class="mb-4 space-y-2">
      <p class="text-sm text-muted" role="status">
        {{ loadError || (pending ? 'Carregando configurações…' : 'As configurações ainda não foram confirmadas.') }}
      </p>
      <UButton
        v-if="!pending"
        type="button"
        color="neutral"
        variant="outline"
        label="Atualizar configurações"
        @click="refreshSettings"
      />
    </div>
    <UPageCard variant="subtle" class="space-y-4">
      <UFormField label="Host SMTP" name="mail_host">
        <UInput v-model="form.mail_host" class="max-w-md" :disabled="!canWrite || pending || !state" />
      </UFormField>
      <UFormField label="Porta" name="mail_port">
        <UInput v-model="form.mail_port" class="max-w-md" :disabled="!canWrite || pending || !state" />
      </UFormField>
      <UFormField label="Remetente (e-mail)" name="mail_from_address">
        <UInput v-model="form.mail_from_address" class="max-w-md" :disabled="!canWrite || pending || !state" />
      </UFormField>
      <UFormField label="Nome remetente" name="mail_from_name">
        <UInput v-model="form.mail_from_name" class="max-w-md" :disabled="!canWrite || pending || !state" />
      </UFormField>
    </UPageCard>
  </UForm>
</template>
