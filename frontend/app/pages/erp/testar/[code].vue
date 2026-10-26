<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { TrialRegistrationInput } from '#shared/types/officeErpCommercial'
import { passwordLength } from '#shared/utils/access'

definePageMeta({ layout: false })
useHead({ title: 'Teste ERP de 7 dias · Opmoni', meta: [{ name: 'referrer', content: 'no-referrer' }] })

const route = useRoute()
const trial = useErpTrialRegistration()
const form = reactive<TrialRegistrationInput>({
  name: '',
  email: '',
  password: '',
  company_name: '',
  cnpj: '',
  tax_regime: 'presumed_profit',
  confirm_existing_association: false
})
const schema = z.object({
  name: z.string().trim().min(2, 'Informe seu nome completo.').max(255),
  email: z.email('Informe um email válido.').max(254),
  password: z.string().refine(value => passwordLength(value) >= 8, 'Use ao menos 8 caracteres.').refine(value => passwordLength(value) <= 128, 'Use no máximo 128 caracteres.'),
  company_name: z.string().trim().min(2, 'Informe a razão social.').max(255),
  cnpj: z.string().trim().min(14, 'Informe um CNPJ válido.').max(20),
  tax_regime: z.enum(['mei', 'simple_national', 'presumed_profit', 'actual_profit', 'other']),
  confirm_existing_association: z.boolean().optional()
}).superRefine((value, context) => {
  if (requiresAssociationConfirmation.value && value.confirm_existing_association !== true) {
    context.addIssue({ code: 'custom', path: ['confirm_existing_association'], message: 'Confirme a associação para continuar.' })
  }
})
type SignupForm = z.output<typeof schema>

const regimeOptions = [
  { label: 'MEI', value: 'mei' },
  { label: 'Simples Nacional', value: 'simple_national' },
  { label: 'Lucro presumido', value: 'presumed_profit' },
  { label: 'Lucro real', value: 'actual_profit' },
  { label: 'Outro', value: 'other' }
]
const fieldError = (name: string) => trial.failure.value?.fieldErrors[name]?.[0]
const requiresAssociationConfirmation = computed(() => trial.failure.value?.code === 'company_association_required')
const loginRequired = computed(() => trial.failure.value?.code === 'login_required')
const busy = computed(() => trial.phase.value === 'submitting')
const recoveryBusy = ref(false)
const taxRegimeValue = computed({
  get: () => form.tax_regime,
  set: (value) => { form.tax_regime = value as TrialRegistrationInput['tax_regime'] }
})

function submit(event: FormSubmitEvent<SignupForm>) {
  Object.assign(form, event.data)
  void trial.submit(form)
}

async function recover() {
  if (recoveryBusy.value) return
  recoveryBusy.value = true
  try {
    await trial.recover()
  } finally {
    recoveryBusy.value = false
  }
}

function formatDate(value: string) {
  const date = new Date(value)
  if (Number.isNaN(date.valueOf())) return 'Data indisponível'
  return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'long', timeStyle: 'short' }).format(date)
}

onMounted(() => {
  void trial.load(String(route.params.code ?? ''))
})
</script>

<template>
  <main class="min-h-dvh bg-default px-4 py-10 text-default sm:px-6 sm:py-16">
    <div class="mx-auto grid w-full max-w-5xl gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,28rem)] lg:items-start">
      <section class="max-w-2xl space-y-8">
        <NuxtLink to="/" aria-label="Opmoni, página inicial" class="inline-flex items-center text-xl font-semibold tracking-tight text-highlighted">
          opmoni<span class="text-primary">.</span>
        </NuxtLink>
        <div class="space-y-4">
          <p class="text-sm font-medium text-primary">
            Avaliação acompanhada pelo escritório
          </p>
          <h1 class="max-w-xl text-4xl font-semibold tracking-tight text-highlighted sm:text-5xl">
            Teste de 7 dias
          </h1>
          <p class="max-w-prose text-lg leading-8 text-muted">
            Conheça o ERP com seus próprios dados. O escritório que compartilhou este link poderá ajudar na implantação, sem acessar sua empresa automaticamente.
          </p>
        </div>

        <div v-if="trial.availability.value" class="space-y-5 border-t border-default pt-6">
          <div>
            <p class="text-sm text-muted">
              Escritório responsável
            </p>
            <p class="mt-1 text-lg font-semibold text-highlighted">
              {{ trial.availability.value.sponsor.name }}
            </p>
          </div>
          <div>
            <p class="text-sm text-muted">
              Plano ERP
            </p>
            <p class="mt-1 font-medium text-highlighted">
              {{ trial.availability.value.plan.name }}
            </p>
            <p v-if="trial.availability.value.plan.description" class="mt-1 text-sm leading-6 text-muted">
              {{ trial.availability.value.plan.description }}
            </p>
            <p class="mt-2 text-sm text-muted">
              Inclui somente os módulos liberados para este plano.
            </p>
          </div>
        </div>
      </section>

      <section class="min-w-0 space-y-5">
        <UAlert
          v-if="trial.failure.value && !loginRequired"
          :color="trial.phase.value === 'recovery' ? 'warning' : 'error'"
          :title="trial.phase.value === 'recovery' ? 'Confirme o resultado do cadastro' : 'Não foi possível continuar'"
          :description="trial.failure.value.message"
        />

        <UAlert
          v-if="loginRequired"
          color="warning"
          title="Esta conta já está cadastrada"
          description="Entre com seu email nesta nova aba e depois volte a este link para confirmar a associação com o escritório. Nenhuma senha foi reenviada."
        >
          <template #actions>
            <UButton
              to="/login"
              target="_blank"
              rel="noopener"
              color="neutral"
              variant="outline"
            >
              Entrar em outra aba
            </UButton>
          </template>
        </UAlert>

        <UAlert
          v-if="requiresAssociationConfirmation"
          color="warning"
          title="Confirme a associação ao escritório"
          description="A empresa já está vinculada à carteira deste escritório. Confirme somente se reconhece essa relação."
        />

        <UAlert
          v-if="trial.phase.value === 'availability' && !trial.failure.value"
          color="neutral"
          title="Verificando o link"
          description="Estamos conferindo a disponibilidade do teste."
        />

        <UAlert
          v-if="trial.phase.value === 'confirmed' && trial.outcome.value"
          color="success"
          title="Empresa em teste"
          description="O cadastro foi confirmado. Use o mesmo email para entrar no ERP."
        >
          <template #description>
            <div class="space-y-1">
              <p>O teste começou em {{ formatDate(trial.outcome.value.started_at) }}.</p>
              <p>Seu acesso de avaliação termina em {{ formatDate(trial.outcome.value.ends_at) }}.</p>
              <NuxtLink to="/login" class="font-medium text-primary underline underline-offset-4">Ir para entrar</NuxtLink>
            </div>
          </template>
        </UAlert>

        <div v-if="trial.phase.value === 'recovery'" class="space-y-4 rounded-xl border border-default bg-elevated p-5 sm:p-7">
          <div>
            <h2 class="text-xl font-semibold text-highlighted">
              Verifique seu cadastro
            </h2>
            <p class="mt-2 text-sm leading-6 text-muted">
              {{ trial.pending.value?.email ? `Vamos consultar o cadastro de ${trial.pending.value.email}.` : 'Vamos consultar o cadastro enviado anteriormente.' }}
              A senha não será enviada novamente.
            </p>
          </div>
          <UButton
            color="primary"
            :loading="recoveryBusy"
            :disabled="recoveryBusy"
            @click="recover"
          >
            Consultar resultado
          </UButton>
          <NuxtLink to="/login" class="block text-sm font-medium text-primary underline underline-offset-4">
            Entrar com email e senha
          </NuxtLink>
        </div>

        <UForm
          v-else-if="trial.phase.value === 'form'"
          :schema="schema"
          :state="form"
          class="space-y-5 rounded-xl border border-default bg-elevated p-5 sm:p-7"
          @submit="submit"
        >
          <div>
            <h2 class="text-xl font-semibold text-highlighted">
              Crie seu acesso
            </h2>
            <p class="mt-2 text-sm leading-6 text-muted">
              Se já possui uma conta, entre primeiro e volte a este link. O escritório não receberá sua senha.
            </p>
          </div>

          <UFormField
            label="Seu nome"
            name="name"
            required
            :error="fieldError('name')"
          >
            <UInput v-model="form.name" autocomplete="name" class="w-full" />
          </UFormField>
          <UFormField
            label="Email"
            name="email"
            required
            :error="fieldError('email')"
          >
            <UInput
              v-model="form.email"
              type="email"
              autocomplete="email"
              class="w-full"
            />
          </UFormField>
          <UFormField
            label="Senha"
            name="password"
            required
            :error="fieldError('password')"
            description="Use de 8 a 128 caracteres."
          >
            <UInput
              v-model="form.password"
              type="password"
              autocomplete="new-password"
              class="w-full"
            />
          </UFormField>

          <div class="border-t border-default pt-5">
            <h3 class="font-semibold text-highlighted">
              Dados da empresa
            </h3>
            <p class="mt-1 text-sm leading-6 text-muted">
              Esses dados identificam sua empresa. O escritório não terá acesso operacional por causa deste cadastro.
            </p>
          </div>
          <UFormField
            label="Razão social"
            name="company_name"
            required
            :error="fieldError('company_name')"
          >
            <UInput v-model="form.company_name" autocomplete="organization" class="w-full" />
          </UFormField>
          <UFormField
            label="CNPJ"
            name="cnpj"
            required
            :error="fieldError('cnpj')"
          >
            <UInput
              v-model="form.cnpj"
              inputmode="numeric"
              autocomplete="off"
              class="w-full"
            />
          </UFormField>
          <UFormField
            label="Regime tributário"
            name="tax_regime"
            required
            :error="fieldError('tax_regime')"
          >
            <USelect v-model="taxRegimeValue" :items="regimeOptions" class="w-full" />
          </UFormField>

          <UFormField v-if="requiresAssociationConfirmation" name="confirm_existing_association" :error="fieldError('confirm_existing_association')">
            <UCheckbox v-model="form.confirm_existing_association" :label="`Confirmo que ${trial.availability.value?.sponsor.name ?? 'este escritório'} atende minha empresa.`" />
          </UFormField>

          <UButton
            type="submit"
            block
            size="lg"
            :loading="busy"
            :disabled="busy || !trial.availability.value?.available"
          >
            Iniciar meu teste
          </UButton>
          <p class="text-xs leading-5 text-muted">
            O teste dura 168 horas a partir da confirmação. Não há cobrança automática nem conversão automática para plano pago.
          </p>
        </UForm>

        <div v-else-if="trial.phase.value === 'availability' && trial.failure.value" class="rounded-xl border border-default bg-elevated p-5 sm:p-7">
          <UButton
            color="neutral"
            variant="outline"
            :loading="busy"
            @click="trial.load(String(route.params.code ?? ''))"
          >
            Verificar link novamente
          </UButton>
        </div>
      </section>
    </div>
  </main>
</template>
