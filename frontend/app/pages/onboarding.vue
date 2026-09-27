<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent, StepperItem } from '@nuxt/ui'

definePageMeta({
  layout: 'auth'
})

const toast = useToast()
const { fetchMe, registrationAvailable, register, user } = useAuth()

// Client-only e pós-hydration: evita divergência SSR/cliente
// e o setup assíncrono (warning do <Suspense>).
onMounted(async () => {
  if (!user.value) {
    await fetchMe().catch(() => {})
  }
  if (user.value) {
    await navigateTo('/')
    return
  }
  if (!(await registrationAvailable().catch(() => true))) {
    await navigateTo('/login')
  }
})

const items: StepperItem[] = [{
  value: 'account',
  title: 'Conta',
  description: 'Seus dados de acesso',
  icon: 'i-lucide-user'
}, {
  value: 'company',
  title: 'Empresa',
  description: 'Onde você trabalha',
  icon: 'i-lucide-building-2'
}, {
  value: 'review',
  title: 'Revisão',
  description: 'Confirme e conclua',
  icon: 'i-lucide-check'
}]

const step = ref('account')

const formIds = {
  account: 'onboarding-account',
  company: 'onboarding-company',
  review: 'onboarding-review'
} as const

const accountSchema = z.object({
  name: z.string('Nome é obrigatório').min(2, 'Mínimo de 2 caracteres'),
  email: z.email('E-mail inválido'),
  password: z.string('Senha é obrigatória').min(8, 'Mínimo de 8 caracteres')
})
type AccountSchema = z.output<typeof accountSchema>
const accountState = reactive<Partial<AccountSchema>>({ name: '', email: '', password: '' })

const companySchema = z.object({
  company: z.string('Empresa é obrigatória').min(2, 'Mínimo de 2 caracteres'),
  size: z.string('Selecione o tamanho')
})
type CompanySchema = z.output<typeof companySchema>
const companyState = reactive<Partial<CompanySchema>>({ company: '', size: '' })

const reviewSchema = z.object({
  terms: z.boolean('Você precisa aceitar os termos').refine(val => val === true, 'Você precisa aceitar os termos')
})
type ReviewSchema = z.output<typeof reviewSchema>
const reviewState = reactive<Partial<ReviewSchema>>({ terms: false })

const submitting = ref(false)

// @submit só dispara após validação passar, então avançar é seguro
function goCompany() {
  step.value = 'company'
}

function goReview() {
  step.value = 'review'
}

function prev() {
  if (step.value === 'company') {
    step.value = 'account'
  } else if (step.value === 'review') {
    step.value = 'company'
  }
}

async function onSubmit(event: FormSubmitEvent<ReviewSchema>) {
  if (!event.data.terms) {
    return
  }
  submitting.value = true
  try {
    await register({
      name: accountState.name ?? '',
      email: accountState.email ?? '',
      password: accountState.password ?? '',
      company: companyState.company ?? '',
      size: companyState.size ?? ''
    })
    toast.add({ title: `Conta criada para ${accountState.name}!`, color: 'success' })
    await navigateTo('/')
  } catch {
    toast.add({ title: 'Não foi possível criar a conta', description: 'Verifique os dados e tente novamente.', color: 'error' })
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="mx-auto flex min-h-dvh w-full max-w-2xl flex-col items-center justify-center gap-6 p-4">
    <div class="text-center">
      <h1 class="text-xl font-semibold text-default">
        Bem-vindo ao opmoni
      </h1>
      <p class="mt-1 text-sm text-muted">
        Complete as etapas para criar sua conta.
      </p>
    </div>

    <UStepper
      v-model="step"
      :items="items"
      linear
      class="w-full"
    />

    <UPageCard class="w-full">
      <UForm
        v-if="step === 'account'"
        :id="formIds.account"
        :schema="accountSchema"
        :state="accountState"
        class="space-y-4"
        @submit="goCompany"
      >
        <UFormField name="name" label="Nome" required>
          <UInput v-model="accountState.name" placeholder="Seu nome" class="w-full" />
        </UFormField>
        <UFormField name="email" label="E-mail" required>
          <UInput
            v-model="accountState.email"
            type="email"
            placeholder="voce@empresa.com"
            class="w-full"
          />
        </UFormField>
        <UFormField name="password" label="Senha" required>
          <UInput
            v-model="accountState.password"
            type="password"
            placeholder="Mínimo de 8 caracteres"
            class="w-full"
          />
        </UFormField>
      </UForm>

      <UForm
        v-else-if="step === 'company'"
        :id="formIds.company"
        :schema="companySchema"
        :state="companyState"
        class="space-y-4"
        @submit="goReview"
      >
        <UFormField name="company" label="Empresa" required>
          <UInput v-model="companyState.company" placeholder="Nome da empresa" class="w-full" />
        </UFormField>
        <UFormField name="size" label="Tamanho da equipe" required>
          <USelect
            v-model="companyState.size"
            :items="['Só eu', '2–10', '11–50', '51–200', '200+']"
            placeholder="Selecione"
            class="w-full"
          />
        </UFormField>
      </UForm>

      <UForm
        v-else
        :id="formIds.review"
        :schema="reviewSchema"
        :state="reviewState"
        class="space-y-4"
        @submit="onSubmit"
      >
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between gap-4">
            <dt class="text-muted">
              Nome
            </dt>
            <dd class="font-medium text-default">
              {{ accountState.name }}
            </dd>
          </div>
          <div class="flex justify-between gap-4">
            <dt class="text-muted">
              E-mail
            </dt>
            <dd class="font-medium text-default">
              {{ accountState.email }}
            </dd>
          </div>
          <div class="flex justify-between gap-4">
            <dt class="text-muted">
              Empresa
            </dt>
            <dd class="font-medium text-default">
              {{ companyState.company }}
            </dd>
          </div>
          <div class="flex justify-between gap-4">
            <dt class="text-muted">
              Equipe
            </dt>
            <dd class="font-medium text-default">
              {{ companyState.size }}
            </dd>
          </div>
        </dl>
        <UFormField name="terms">
          <UCheckbox v-model="reviewState.terms" label="Aceito os termos de uso" />
        </UFormField>
      </UForm>

      <template #footer>
        <div class="flex justify-between">
          <UButton
            label="Voltar"
            color="neutral"
            variant="ghost"
            :disabled="step === 'account'"
            @click="prev"
          />
          <UButton
            :label="step === 'review' ? 'Concluir' : 'Continuar'"
            :icon="step === 'review' ? 'i-lucide-check' : undefined"
            :trailing-icon="step === 'review' ? undefined : 'i-lucide-arrow-right'"
            :loading="submitting"
            type="submit"
            :form="formIds[step as keyof typeof formIds]"
          />
        </div>
      </template>
    </UPageCard>

    <p class="text-sm text-muted">
      Já tem conta? <ULink to="/login" :active="false" class="font-medium text-primary">Entrar</ULink>
    </p>
  </div>
</template>
