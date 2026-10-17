<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { apiFailure, passwordLength } from '#shared/utils/access'

definePageMeta({ layout: false })
useHead({ title: 'Cadastro ERP · Opmoni' })

const form = reactive({
  name: '',
  email: '',
  password: '',
  company_name: '',
  cnpj: '',
  establishment_name: ''
})
const schema = z.object({
  name: z.string().trim().min(2).max(255),
  email: z.email().max(254),
  password: z.string().refine(v => passwordLength(v) >= 8).refine(v => passwordLength(v) <= 128),
  company_name: z.string().trim().min(2).max(255),
  cnpj: z.string().trim().min(14).max(20),
  establishment_name: z.string().max(255).optional()
})

const available = ref<boolean | null>(null)
const busy = ref(false)
const error = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const { api, refreshCsrf } = useAccessApi()
const user = useState<import('#shared/types/access').Identity | null>('access-user')
const resolved = useState('access-resolved', () => false)
const { $api } = useNuxtApp()

onMounted(async () => {
  try {
    const status = await $api<{ registration_available: boolean }>('/erp/registration-status')
    available.value = status.registration_available
  } catch {
    available.value = false
  }
})

async function submit(event: FormSubmitEvent<z.output<typeof schema>>) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  fieldErrors.value = {}
  Object.assign(form, event.data)
  try {
    const identity = await api<import('#shared/types/access').Identity>('/erp/register', { method: 'POST', body: { ...form } })
    user.value = identity
    resolved.value = true
    await refreshCsrf()
    await navigateTo('/erp/contrato', { replace: true })
  } catch (err) {
    const failure = apiFailure(err)
    error.value = failure.message
    fieldErrors.value = failure.errors ?? {}
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="mx-auto flex min-h-screen max-w-lg flex-col justify-center gap-6 p-6">
    <div>
      <h1 class="text-2xl font-semibold">
        Cadastro de empresa ERP
      </h1>
      <p class="text-muted text-sm">
        Contratação direta, sem checkout automático. Após o envio, o contrato fica pendente até habilitação pela plataforma.
      </p>
    </div>
    <UAlert
      v-if="available === false"
      color="warning"
      title="Cadastro indisponível"
      description="A plataforma ainda não liberou novos cadastros ERP."
    />
    <UForm
      v-else
      :schema="schema"
      :state="form"
      class="space-y-4"
      @submit="submit"
    >
      <UFormField label="Seu nome" name="name" :error="fieldErrors.name?.[0]">
        <UInput v-model="form.name" autocomplete="name" />
      </UFormField>
      <UFormField label="Email" name="email" :error="fieldErrors.email?.[0]">
        <UInput v-model="form.email" type="email" autocomplete="email" />
      </UFormField>
      <UFormField label="Senha" name="password" :error="fieldErrors.password?.[0]">
        <UInput v-model="form.password" type="password" autocomplete="new-password" />
      </UFormField>
      <UFormField label="Empresa" name="company_name" :error="fieldErrors.company_name?.[0]">
        <UInput v-model="form.company_name" />
      </UFormField>
      <UFormField label="CNPJ" name="cnpj" :error="fieldErrors.cnpj?.[0]">
        <UInput v-model="form.cnpj" inputmode="numeric" />
      </UFormField>
      <UFormField label="Estabelecimento (opcional)" name="establishment_name">
        <UInput v-model="form.establishment_name" placeholder="Matriz" />
      </UFormField>
      <UAlert v-if="error" color="error" :title="error" />
      <UButton
        type="submit"
        block
        size="lg"
        :loading="busy"
        :disabled="available !== true"
      >
        Solicitar cadastro
      </UButton>
    </UForm>
  </div>
</template>
