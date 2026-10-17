<script setup lang="ts">
import * as z from 'zod'
import type { ExtendedPlatformAccount, AccountCommercialDetail, DetailResponse } from '#shared/types/admin'
import { apiFailure } from '#shared/utils/access'
import { apiErrorMessage } from '~/composables/useApiError'
import { brazilUfItems } from '~/utils/brazilUfs'

const props = defineProps<{
  account: ExtendedPlatformAccount | null
}>()
const emit = defineEmits<{
  saved: [
  ]
}>()
const open = defineModel<boolean>('open', { default: false })
const { $api } = useNuxtApp()
const toast = useToast()
const loading = ref(false)
const saving = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const saveError = ref('')
const error = ref<unknown>(null)
const subscription = ref<{
  status: string
  plan?: {
    name: string
  } | null
} | null>(null)
const form = reactive({ phone: '', phone_whatsapp: false, state: '', city: '' })
const selectedState = computed({
  get: () => form.state || 'none',
  set: (value) => {
    form.state = value === 'none' ? '' : value
  }
})
const schema = z.object({
  phone: z.string().max(20, 'Informe até 20 caracteres.'),
  phone_whatsapp: z.boolean(),
  state: z.union([z.literal(''), z.string().length(2, 'Selecione uma UF.')]),
  city: z.string().max(255, 'Informe até 255 caracteres.')
})
let generation = 0
const { showError, retry } = useRetryableLoad({
  refresh: load, error, loading, loadErrorTitle: 'Não foi possível carregar os dados comerciais'
})
async function load() {
  if (!props.account)
    return
  const version = ++generation
  loading.value = true
  error.value = null
  try {
    const result = await $api<DetailResponse<AccountCommercialDetail>>(`/admin/accounts/${props.account.id}/commercial`)
    if (version !== generation)
      return
    const contact = result.data.platform_billing_contact
    Object.assign(form, { phone: contact?.phone ?? '', phone_whatsapp: contact?.phone_whatsapp ?? false, state: contact?.state ?? '', city: contact?.city ?? '' })
    subscription.value = result.data.subscription ?? null
  } catch (failure) {
    if (version === generation)
      error.value = failure
  } finally {
    if (version === generation)
      loading.value = false
  }
}
async function save() {
  if (!props.account || saving.value || loading.value || error.value)
    return
  const version = generation
  fieldErrors.value = {}
  saveError.value = ''
  saving.value = true
  try {
    await $api(`/admin/accounts/${props.account.id}/commercial`, { method: 'PATCH', body: { ...form, phone_whatsapp: !!form.phone && form.phone_whatsapp } })
    if (version !== generation)
      return
    emit('saved')
    toast.add({ title: 'Dados comerciais atualizados', color: 'success' })
    open.value = false
  } catch (failure) {
    if (version !== generation)
      return
    fieldErrors.value = apiFailure(failure).errors || {}
    saveError.value = apiErrorMessage(failure) ?? 'Não foi possível salvar os dados comerciais.'
    toast.add({ title: 'Não foi possível salvar', description: apiErrorMessage(failure), color: 'error' })
  } finally {
    saving.value = false
  }
}
watch(open, (value) => {
  if (value) {
    Object.assign(form, { phone: '', phone_whatsapp: false, state: '', city: '' })
    subscription.value = null
    fieldErrors.value = {}
    saveError.value = ''
    void load()
  } else {
    generation++
    Object.assign(form, { phone: '', phone_whatsapp: false, state: '', city: '' })
    subscription.value = null
  }
})
onBeforeUnmount(() => {
  generation++
  Object.assign(form, { phone: '', phone_whatsapp: false, state: '', city: '' })
  subscription.value = null
})
</script>

<template>
  <UModal
    v-model:open="open"
    title="Dados comerciais"
    :description="account?.name"
    :dismissible="!saving"
    :close="!saving"
  >
    <template #body>
      <ErrorRetryAlert
        v-if="showError"
        title="Não foi possível carregar os dados comerciais"
        :loading="loading"
        @retry="retry"
      />
      <p v-else-if="loading" role="status">
        Carregando…
      </p>
      <UForm
        v-else
        :schema="schema"
        :state="form"
        class="space-y-4"
        @submit="save"
      >
        <p class="text-sm text-muted">
          Plano: {{ subscription?.plan?.name ?? 'Sem assinatura' }}
          <span v-if="subscription"> · {{ subscription.status }}</span>
        </p>
        <UFormField name="phone" :error="fieldErrors.phone?.[0]" label="Telefone">
          <UInput
            v-model="form.phone"
            type="tel"
            maxlength="20"
            class="w-full"
          />
        </UFormField>
        <UFormField name="phone_whatsapp" :error="fieldErrors.phone_whatsapp?.[0]">
          <UCheckbox v-model="form.phone_whatsapp" label="Este telefone recebe WhatsApp" :disabled="!form.phone" />
        </UFormField>
        <UFormField name="state" :error="fieldErrors.state?.[0]" label="UF">
          <USelect v-model="selectedState" :items="[{ label: 'Não informado', value: 'none' }, ...brazilUfItems]" class="w-full" />
        </UFormField>
        <UFormField name="city" :error="fieldErrors.city?.[0]" label="Município">
          <UInput v-model="form.city" maxlength="255" class="w-full" />
        </UFormField>
        <UAlert v-if="saveError" color="error" :title="saveError" />
        <UButton
          type="submit"
          label="Salvar dados comerciais"
          :loading="saving"
          :disabled="saving || loading"
        />
      </UForm>
    </template>
  </UModal>
</template>
