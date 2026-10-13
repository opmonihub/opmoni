<script setup lang="ts">
import * as z from 'zod'
import type { AuthFormField, FormSubmitEvent, StepperItem } from '@nuxt/ui'
import { apiFailure, passwordLength } from '#shared/utils/access'

definePageMeta({ layout: false })
useHead({ title: 'Configure seu escritório · Opmoni' })
const step = ref(1)
const form = reactive({ name: '', email: '', password: '', company: '', size: 'solo' })
const sizes = [{ label: 'Somente eu', value: 'solo' }, { label: '2–10 pessoas', value: '2-10' }, { label: '11–50 pessoas', value: '11-50' }, { label: '51–200 pessoas', value: '51-200' }, { label: 'Mais de 200 pessoas', value: '200+' }]
const schema = computed(() => z.object({
  name: z.string().trim().min(step.value === 2 ? 0 : 2, 'Informe ao menos 2 caracteres.').max(255),
  email: step.value === 2 ? z.string() : z.email('Informe um email válido.').max(254),
  password: z.string().refine(value => step.value === 2 || passwordLength(value) >= 8, 'Use ao menos 8 caracteres.').refine(value => passwordLength(value) <= 128, 'Use no máximo 128 caracteres.'),
  company: z.string().trim().min(step.value === 1 ? 0 : 2, 'Informe ao menos 2 caracteres.').max(255),
  size: z.string().refine(value => ['solo', '2-10', '11-50', '51-200', '200+'].includes(value), 'Selecione o tamanho da equipe.')
}))

const busy = ref(false)
const error = ref('')
const initialized = ref(false)
const fields = ref<Record<string, string[]>>({})
const { authenticate } = useAuth()
const showPassword = ref(false)
const passwordInput = useTemplateRef('passwordInput')

watch(step, async () => {
  await nextTick()
  document.getElementById('onboarding-step-title')?.focus()
})
const steps: StepperItem[] = [
  { title: 'Acesso', icon: 'i-lucide-user-round' },
  { title: 'Escritório', icon: 'i-lucide-building-2' }
]
const accessSchema = z.object({
  name: z.string().trim().min(2, 'Informe ao menos 2 caracteres.').max(255),
  email: z.email('Informe um email válido.').max(254),
  password: z.string().refine(value => passwordLength(value) >= 8, 'Use ao menos 8 caracteres.').refine(value => passwordLength(value) <= 128, 'Use no máximo 128 caracteres.')
})
const accessFields = computed<AuthFormField[]>(() => [
  { name: 'name', type: 'text', label: 'Nome completo', placeholder: 'Como você se chama?', autocomplete: 'name', maxlength: 255, required: true, size: 'lg', defaultValue: form.name, error: fields.value.name?.[0] },
  { name: 'email', type: 'email', label: 'Email', placeholder: 'voce@escritorio.com.br', autocomplete: 'email', maxlength: 254, required: true, size: 'lg', defaultValue: form.email, error: fields.value.email?.[0] },
  { name: 'password', type: 'password', label: 'Senha', description: 'Use de 8 a 128 caracteres.', autocomplete: 'new-password', required: true, size: 'lg', defaultValue: form.password, error: fields.value.password?.[0] }
])

function continueAccess(event: FormSubmitEvent<z.output<typeof accessSchema>>) {
  Object.assign(form, event.data)
  fields.value = {}
  error.value = ''
  showPassword.value = false
  step.value = 2
}

async function submit() {
  if (busy.value)
    return
  busy.value = true
  error.value = ''
  try {
    await authenticate('/register', { ...form })
  } catch (e) {
    const failure = apiFailure(e)
    fields.value = failure.errors || {}
    initialized.value = failure.status === 403
    error.value = initialized.value ? 'A configuração inicial já foi concluída. Entre com seu acesso.' : failure.message
    if (fields.value.name || fields.value.email || fields.value.password)
      step.value = 1
    else if (fields.value.company || fields.value.size)
      step.value = 2
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="flex min-h-dvh flex-col items-center justify-center gap-6 bg-default px-4 py-8 sm:py-12">
    <NuxtLink to="/" aria-label="Opmoni" class="text-2xl font-semibold tracking-tight">
      opmoni<span class="text-primary">.</span>
    </NuxtLink>

    <div class="w-full max-w-md space-y-6">
      <header class="space-y-2 text-center">
        <h1 class="text-2xl font-semibold tracking-tight text-highlighted">
          Configure seu escritório
        </h1>
        <p class="text-sm text-muted">
          Crie seu acesso e cadastre o escritório para começar.
        </p>
      </header>

      <UPageCard :ui="{ container: 'gap-y-6' }">
        <UStepper
          :model-value="step - 1"
          :items="steps"
          disabled
          size="sm"
          aria-hidden="true"
          class="w-full"
        />
        <p class="text-center text-xs text-muted" role="status" aria-live="polite">
          Etapa {{ step }} de {{ steps.length }} · {{ steps[step - 1]?.title }}
        </p>

        <UAuthForm
          v-if="step === 1"
          :schema="accessSchema"
          :fields="accessFields"
          description="Esses dados serão usados para entrar no Opmoni."
          :submit="{ label: 'Continuar', trailingIcon: 'i-lucide-arrow-right', size: 'lg' }"
          @submit="continueAccess"
        >
          <template #title>
            <h2 id="onboarding-step-title" tabindex="-1" class="focus:outline-none">
              Crie seu acesso
            </h2>
          </template>
          <template #password-field="{ state }">
            <UInput
              ref="passwordInput"
              v-model="state.password"
              :type="showPassword ? 'text' : 'password'"
              placeholder="Crie uma senha"
              autocomplete="new-password"
              size="lg"
              required
              class="w-full"
              :ui="{ trailing: 'pe-1' }"
            >
              <template #trailing>
                <UButton
                  type="button"
                  color="neutral"
                  variant="link"
                  size="sm"
                  :icon="showPassword ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                  :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'"
                  :aria-pressed="showPassword"
                  :aria-controls="passwordInput?.inputRef?.id"
                  @click="showPassword = !showPassword"
                />
              </template>
            </UInput>
          </template>
          <template #validation>
            <UAlert
              v-if="error"
              color="error"
              icon="i-lucide-circle-alert"
              :title="error"
            />
          </template>
        </UAuthForm>

        <template v-else>
          <header class="space-y-1 text-center">
            <h2 id="onboarding-step-title" tabindex="-1" class="text-xl font-semibold text-highlighted focus:outline-none">
              Sobre seu escritório
            </h2>
            <p class="text-base text-muted">
              Defina o nome e o tamanho da sua equipe.
            </p>
          </header>
          <UForm
            :schema="schema"
            :state="form"
            :disabled="busy || initialized"
            class="space-y-5"
            @submit="submit"
          >
            <UFormField
              label="Nome do escritório"
              name="company"
              :error="fields.company?.[0]"
              size="lg"
              required
            >
              <UInput
                v-model="form.company"
                placeholder="Ex.: Almeida Advocacia"
                autocomplete="organization"
                required
                maxlength="255"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Tamanho da equipe"
              name="size"
              :error="fields.size?.[0]"
              size="lg"
              required
            >
              <USelect v-model="form.size" :items="sizes" class="w-full" />
            </UFormField>
            <UAlert
              v-if="error"
              color="error"
              icon="i-lucide-circle-alert"
              :title="error"
            />
            <UButton
              v-if="initialized"
              to="/login"
              label="Ir para login"
              size="lg"
              block
            />
            <div v-else class="flex items-center justify-between gap-3 pt-1">
              <UButton
                type="button"
                label="Voltar"
                leading-icon="i-lucide-arrow-left"
                color="neutral"
                variant="ghost"
                size="lg"
                :disabled="busy"
                @click="step--"
              />
              <UButton
                type="submit"
                label="Criar escritório"
                trailing-icon="i-lucide-arrow-right"
                size="lg"
                :loading="busy"
                :disabled="busy"
              />
            </div>
          </UForm>
        </template>
      </UPageCard>
    </div>
  </main>
</template>
