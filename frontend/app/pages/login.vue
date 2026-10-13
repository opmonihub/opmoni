<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent, AuthFormField } from '@nuxt/ui'
import { apiFailure, passwordLength } from '#shared/utils/access'

definePageMeta({ layout: false })

const fields: AuthFormField[] = [{
  name: 'email',
  type: 'email',
  label: 'Email',
  placeholder: 'Digite seu email',
  required: true,
  autocomplete: 'username',
  maxlength: 254
}, {
  name: 'password',
  type: 'password',
  label: 'Senha',
  placeholder: 'Digite sua senha',
  required: true,
  autocomplete: 'current-password'
}]

const schema = z.object({
  email: z.email('Informe um email válido.').max(254),
  password: z.string('Informe a senha.').min(1, 'Informe a senha.').refine(value => passwordLength(value) <= 128, 'Use no máximo 128 caracteres.')
})

type Schema = z.output<typeof schema>

const busy = ref(false)
const error = ref('')
const { authenticate } = useAuth()

async function onSubmit(event: FormSubmitEvent<Schema>) {
  if (busy.value)
    return
  busy.value = true
  error.value = ''
  try {
    await authenticate('/login', event.data)
  } catch (e) {
    const failure = apiFailure(e)
    error.value = failure.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="flex min-h-dvh flex-col items-center justify-center gap-6 bg-default p-4">
    <NuxtLink to="/" aria-label="Opmoni" class="text-2xl font-semibold tracking-tight">
      opmoni<span class="text-primary">.</span>
    </NuxtLink>

    <UPageCard class="w-full max-w-md">
      <UAuthForm
        :schema="schema"
        :fields="fields"
        title="Bem-vindo de volta!"
        icon="i-lucide-lock"
        :loading="busy"
        :submit="{ label: 'Entrar', disabled: busy }"
        @submit="onSubmit"
      >
        <template #description>
          Entre com seu email e senha para acessar seu escritório.
        </template>
        <template #validation>
          <UAlert
            v-if="error"
            color="error"
            icon="i-lucide-info"
            :title="error"
          />
        </template>
        <template #footer>
          Problemas para entrar? Procure o administrador da plataforma.
        </template>
      </UAuthForm>
    </UPageCard>
  </main>
</template>
