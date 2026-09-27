<script setup lang="ts">
import * as z from 'zod'
import type { AuthFormField, FormSubmitEvent } from '@nuxt/ui'

definePageMeta({
  layout: 'auth'
})

const toast = useToast()
const { fetchMe, registrationAvailable, login, user } = useAuth()

// Client-only e pós-hydration: evita divergência SSR/cliente (mismatch no ULink)
// e o setup assíncrono (warning do <Suspense>).
onMounted(async () => {
  if (!user.value) {
    await fetchMe().catch(() => {})
  }
  if (user.value) {
    await navigateTo('/')
    return
  }
  if (await registrationAvailable().catch(() => false)) {
    await navigateTo('/onboarding')
  }
})

const fields: AuthFormField[] = [{
  name: 'email',
  type: 'email',
  label: 'E-mail',
  placeholder: 'voce@empresa.com',
  required: true
}, {
  name: 'password',
  label: 'Senha',
  type: 'password',
  placeholder: 'Sua senha',
  required: true
}, {
  name: 'remember',
  label: 'Lembrar de mim',
  type: 'checkbox'
}]

const schema = z.object({
  email: z.email('E-mail inválido'),
  password: z.string('Senha é obrigatória').min(8, 'Mínimo de 8 caracteres')
})

type Schema = z.output<typeof schema>

const loading = ref(false)

async function onSubmit(event: FormSubmitEvent<Schema>) {
  loading.value = true
  try {
    await login(event.data.email, event.data.password)
    toast.add({ title: `Bem-vindo de volta, ${event.data.email}!`, color: 'success' })
    await navigateTo('/')
  } catch {
    toast.add({ title: 'Não foi possível entrar', description: 'Verifique seu e-mail e senha.', color: 'error' })
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-dvh items-center justify-center p-4">
    <UPageCard class="w-full max-w-md">
      <UAuthForm
        :schema="schema"
        :fields="fields"
        :loading="loading"
        title="Entrar no opmoni"
        description="Acesse sua conta para continuar."
        icon="i-lucide-lock"
        @submit="onSubmit"
      >
        <template #footer>
          Não tem conta? <ULink to="/onboarding" :active="false" class="font-medium text-primary">Criar conta</ULink>.
        </template>
      </UAuthForm>
    </UPageCard>
  </div>
</template>
