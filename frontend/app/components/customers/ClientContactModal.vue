<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { Client, ClientUpdatePayload } from '~/types/client'

defineOptions({ inheritAttrs: false })

const props = defineProps<{
  open: boolean
  client: Client | null
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'saved': [client: Client]
}>()

const isOpen = computed({
  get: () => props.open,
  set: value => emit('update:open', value)
})

const { update } = useClients()
const toast = useToast()

const isIndividual = computed(() => props.client?.person_type === 'individual')

const companySchema = z.object({
  email: z.email('Email inválido').or(z.literal('')).optional(),
  phone: z.string().max(20, 'Telefone muito longo').optional()
})

const individualSchema = companySchema.extend({
  street_type: z.string().max(40).optional(),
  street: z.string().max(255).optional(),
  address_number: z.string().max(30).optional(),
  address_complement: z.string().max(255).optional(),
  district: z.string().max(255).optional(),
  postal_code: z.string().max(9).optional(),
  city: z.string().max(255).optional(),
  state: z.string().length(2, 'UF deve ter 2 letras').or(z.literal('')).optional()
})

const schema = computed(() => isIndividual.value ? individualSchema : companySchema)
type Schema = z.output<typeof individualSchema>

const state = reactive({
  email: '',
  phone: '',
  street_type: '',
  street: '',
  address_number: '',
  address_complement: '',
  district: '',
  postal_code: '',
  city: '',
  state: ''
})

const submitting = ref(false)

function syncFromClient() {
  const c = props.client
  if (!c) return
  state.email = c.email ?? ''
  state.phone = c.phone ?? ''
  state.street_type = c.address?.street_type ?? ''
  state.street = c.address?.street ?? ''
  state.address_number = c.address?.number ?? ''
  state.address_complement = c.address?.complement ?? ''
  state.district = c.address?.district ?? ''
  state.postal_code = c.address?.postal_code ?? ''
  state.city = c.address?.city ?? ''
  state.state = c.address?.state ?? ''
}

watch(() => props.open, (open) => {
  if (open) syncFromClient()
})

watch(() => props.client, () => {
  if (props.open) syncFromClient()
})

async function onSubmit(event: FormSubmitEvent<Schema>) {
  if (!props.client) return
  submitting.value = true
  try {
    const payload: ClientUpdatePayload = {
      email: event.data.email,
      phone: event.data.phone
    }
    if (isIndividual.value) {
      payload.street_type = event.data.street_type
      payload.street = event.data.street
      payload.address_number = event.data.address_number
      payload.address_complement = event.data.address_complement
      payload.district = event.data.district
      payload.postal_code = event.data.postal_code
      payload.city = event.data.city
      payload.state = event.data.state
    }
    const saved = await update(props.client.id, payload)
    toast.add({ title: 'Contato atualizado', color: 'success' })
    emit('saved', saved)
    isOpen.value = false
  } catch {
    toast.add({ title: 'Não foi possível salvar o contato', color: 'error' })
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <UModal
    v-bind="$attrs"
    v-model:open="isOpen"
    title="Editar contato"
    :description="client ? client.name : 'Atualizar contato e endereço'"
  >
    <template #body>
      <UForm
        id="client-contact-form"
        :schema="schema"
        :state="state"
        class="space-y-4"
        @submit="onSubmit"
      >
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <UFormField label="Email" name="email">
            <UInput
              v-model="state.email"
              type="email"
              placeholder="contato@empresa.com"
              class="w-full"
            />
          </UFormField>
          <UFormField label="Telefone" name="phone">
            <UInput v-model="state.phone" placeholder="(00) 00000-0000" class="w-full" />
          </UFormField>
        </div>

        <template v-if="isIndividual">
          <USeparator />
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <UFormField label="Tipo logradouro" name="street_type">
              <UInput v-model="state.street_type" class="w-full" />
            </UFormField>
            <UFormField label="Logradouro" name="street" class="sm:col-span-2">
              <UInput v-model="state.street" class="w-full" />
            </UFormField>
          </div>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <UFormField label="Número" name="address_number">
              <UInput v-model="state.address_number" class="w-full" />
            </UFormField>
            <UFormField label="Complemento" name="address_complement">
              <UInput v-model="state.address_complement" class="w-full" />
            </UFormField>
          </div>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <UFormField label="Bairro" name="district">
              <UInput v-model="state.district" class="w-full" />
            </UFormField>
            <UFormField label="CEP" name="postal_code">
              <UInput v-model="state.postal_code" class="w-full" />
            </UFormField>
          </div>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <UFormField label="Cidade" name="city" class="sm:col-span-2">
              <UInput v-model="state.city" class="w-full" />
            </UFormField>
            <UFormField label="UF" name="state">
              <UInput v-model="state.state" maxlength="2" class="w-full" />
            </UFormField>
          </div>
        </template>

        <p v-else class="text-sm text-muted">
          Endereço da empresa vem da Receita Federal. Use “Atualizar pela Receita” no cadastro.
        </p>
      </UForm>
    </template>

    <template #footer="{ close }">
      <UButton
        label="Cancelar"
        color="neutral"
        variant="outline"
        @click="close"
      />
      <UButton
        label="Salvar"
        type="submit"
        form="client-contact-form"
        :loading="submitting"
      />
    </template>
  </UModal>
</template>
