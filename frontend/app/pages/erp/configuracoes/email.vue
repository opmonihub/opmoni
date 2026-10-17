<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const { state, pending, refresh, save } = useErpCompanySettings()
const toast = useToast()
const form = reactive({
  mail_host: '',
  mail_port: '',
  mail_from_address: '',
  mail_from_name: ''
})

onMounted(async () => {
  if (!state.value)
    await refresh()
  sync()
})

watch(state, sync)

function sync() {
  const email = (state.value?.email_settings ?? {}) as Record<string, string>
  form.mail_host = email.mail_host ?? ''
  form.mail_port = email.mail_port ?? ''
  form.mail_from_address = email.mail_from_address ?? ''
  form.mail_from_name = email.mail_from_name ?? ''
}

async function onSubmit() {
  try {
    await save({ email_settings: { ...form } })
    toast.add({ title: 'E-mail salvo', color: 'success', icon: 'i-lucide-check' })
  } catch {
    toast.add({ title: 'Erro ao salvar', color: 'error', icon: 'i-lucide-x' })
  }
}
</script>

<template>
  <UForm @submit.prevent="onSubmit">
    <UPageCard
      title="E-mail"
      description="settings_email.blade.php"
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        type="submit"
        label="Salvar"
        :loading="pending"
        class="w-fit lg:ms-auto"
      />
    </UPageCard>
    <UPageCard variant="subtle" class="space-y-4">
      <UFormField label="Host SMTP" name="mail_host">
        <UInput v-model="form.mail_host" class="max-w-md" />
      </UFormField>
      <UFormField label="Porta" name="mail_port">
        <UInput v-model="form.mail_port" class="max-w-md" />
      </UFormField>
      <UFormField label="Remetente (e-mail)" name="mail_from_address">
        <UInput v-model="form.mail_from_address" class="max-w-md" />
      </UFormField>
      <UFormField label="Nome remetente" name="mail_from_name">
        <UInput v-model="form.mail_from_name" class="max-w-md" />
      </UFormField>
    </UPageCard>
  </UForm>
</template>
