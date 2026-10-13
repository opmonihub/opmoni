<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent, AuthFormField } from '@nuxt/ui'
import { apiFailure, passwordLength } from '#shared/utils/access'

definePageMeta({ layout: 'access' })
useHead({ meta: [{ name: 'referrer', content: 'no-referrer' }] })
const route = useRoute()
const token = typeof route.query.token === 'string' ? route.query.token : ''
const email = typeof route.query.email === 'string' ? route.query.email : ''
const { api } = useAccessApi()
const { authenticate } = useAuth()
const status = ref<{
  valid: boolean
  owner_name?: string
  account_name?: string
} | null>(null)
const schema = z.object({ password: z.string().refine(value => passwordLength(value) >= 8, 'Use ao menos 8 caracteres.').refine(value => passwordLength(value) <= 128, 'Use no máximo 128 caracteres.'), password_confirmation: z.string() }).refine(data => data.password === data.password_confirmation, { message: 'As senhas devem ser iguais.', path: ['password_confirmation'] })
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const authFields: AuthFormField[] = [{ name: 'password', type: 'password', label: 'Senha', required: true, autocomplete: 'new-password' }, { name: 'password_confirmation', type: 'password', label: 'Confirmar senha', required: true, autocomplete: 'new-password' }]
async function validate() {
  loading.value = true
  error.value = ''
  try {
    status.value = token && email ? await api('/first-access/status', { query: { token, email } }) : { valid: false }
  } catch (e) {
    error.value = apiFailure(e).message
  } finally {
    loading.value = false
  }
}
async function submit(event: FormSubmitEvent<z.output<typeof schema>>) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    await authenticate('/first-access', { token, email, ...event.data })
  } catch (e) {
    error.value = apiFailure(e).message
  } finally {
    busy.value = false
  }
}
onMounted(validate)
</script>

<template>
  <UPageCard class="w-full" variant="subtle">
    <p v-if="loading" role="status">
      Validando seu link…
    </p>
    <UAuthForm
      v-else-if="status?.valid"
      :schema="schema"
      :fields="authFields"
      title="Primeiro acesso"
      :description="`${status.owner_name}, defina sua senha para acessar ${status.account_name}.`"
      icon="i-lucide-key-round"
      :loading="busy"
      :submit="{ label: 'Ativar acesso', disabled: busy }"
      @submit="submit"
    >
      <template #validation>
        <UAlert v-if="error" color="error" :title="error" />
      </template>
    </UAuthForm>
    <template v-else>
      <h1 class="text-2xl font-semibold">
        Primeiro acesso
      </h1><UAlert v-if="error" color="error" :title="error" /><UButton v-if="error" label="Tentar novamente" @click="validate" /><p v-else>
        Este link é inválido ou expirou. Solicite um novo link ao administrador da plataforma.
      </p>
    </template>
  </UPageCard>
</template>
