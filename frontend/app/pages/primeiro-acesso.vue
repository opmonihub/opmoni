<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'

definePageMeta({
  layout: 'auth'
})

const route = useRoute()
const toast = useToast()
const { ensureCsrf, fetchMe } = useAuth()

const token = computed(() => String(route.query.token ?? ''))
const email = computed(() => String(route.query.email ?? ''))

const ready = ref(false)
const valid = ref(false)
const ownerName = ref('')
const accountName = ref('')
const submitting = ref(false)

const schema = z.object({
  password: z.string('Senha é obrigatória').min(8, 'Mínimo de 8 caracteres'),
  password_confirmation: z.string('Confirme a senha')
}).refine(data => data.password === data.password_confirmation, {
  message: 'As senhas não coincidem',
  path: ['password_confirmation']
})

type Schema = z.output<typeof schema>
const state = reactive<Partial<Schema>>({
  password: '',
  password_confirmation: ''
})

onMounted(async () => {
  if (!token.value || !email.value) {
    ready.value = true
    valid.value = false
    return
  }

  try {
    const { $api } = useNuxtApp()
    const status = await $api<{ valid: boolean, owner_name?: string, account_name?: string }>('/first-access/status', {
      params: { token: token.value, email: email.value }
    })
    valid.value = status.valid
    ownerName.value = status.owner_name ?? ''
    accountName.value = status.account_name ?? ''
  } catch {
    valid.value = false
  } finally {
    ready.value = true
  }
})

async function onSubmit(event: FormSubmitEvent<Schema>) {
  submitting.value = true
  try {
    const { $api } = useNuxtApp()
    await ensureCsrf()
    await $api('/first-access', {
      method: 'POST',
      body: {
        token: token.value,
        email: email.value,
        password: event.data.password,
        password_confirmation: event.data.password_confirmation
      }
    })
    await fetchMe()
    toast.add({ title: 'Senha definida', color: 'success' })
    await navigateTo('/')
  } catch {
    toast.add({ title: 'Não foi possível concluir', description: 'Link inválido ou expirado.', color: 'error' })
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center gap-6 p-4">
    <div class="text-center">
      <h1 class="text-xl font-semibold text-default">
        Primeiro acesso
      </h1>
      <p v-if="valid && accountName" class="mt-1 text-sm text-muted">
        {{ accountName }}
      </p>
    </div>

    <UAlert
      v-if="ready && !valid"
      color="error"
      title="Link inválido ou expirado"
      description="Peça um novo link ao suporte."
    />

    <UForm
      v-else-if="ready && valid"
      id="first-access-form"
      :schema="schema"
      :state="state"
      class="space-y-4"
      @submit="onSubmit"
    >
      <p v-if="ownerName" class="text-sm text-muted">
        Olá, {{ ownerName }}
      </p>

      <UFormField label="Nova senha" name="password" required>
        <UInput v-model="state.password" type="password" class="w-full" />
      </UFormField>

      <UFormField label="Confirmar senha" name="password_confirmation" required>
        <UInput v-model="state.password_confirmation" type="password" class="w-full" />
      </UFormField>

      <UButton
        type="submit"
        label="Entrar"
        block
        :loading="submitting"
      />
    </UForm>
  </div>
</template>
