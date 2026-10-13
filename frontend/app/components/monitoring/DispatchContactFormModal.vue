<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { ClientDispatchContact } from '~/types/whatsapp'
import { apiErrorMessage } from '~/composables/useApiError'

/**
 * O cadastro rápido de Contato de envio: rótulo opcional + um canal +
 * o valor do canal. Serve à ficha do cliente e ao atalho "sem WhatsApp" da
 * folha de monitoramento — a mesma API (`/clients/{id}/dispatch-contacts`)
 * nos dois lugares. Na edição o canal não troca: mudar de canal é um
 * contato novo, e o backend recusa `channel` num update.
 */
const props = defineProps<{
  open: boolean
  clientId: number
  /** O nome da linha que pediu o cadastro — o modal se apresenta por ele. */
  clientName?: string
  /** O contato em edição; `null`/ausente é cadastro. */
  contact?: ClientDispatchContact | null
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'saved': [contact: ClientDispatchContact]
}>()

const isOpen = computed({
  get: () => props.open,
  set: value => emit('update:open', value)
})

const toast = useToast()
const { create, update } = useDispatchContacts()

const editing = computed(() => props.contact ?? null)

const channelItems = [
  { label: 'WhatsApp', value: 'whatsapp' as const },
  { label: 'E-mail', value: 'email' as const }
]

const schema = z.object({
  label: z.string().max(255, 'Rótulo muito longo').optional(),
  channel: z.enum(['whatsapp', 'email']),
  value: z.string().min(1, 'Informe o telefone ou o e-mail').max(255)
}).superRefine((data, ctx) => {
  if (data.channel === 'whatsapp') {
    const digits = data.value.replace(/\D+/g, '')
    // A mesma régua do backend: BR-plausível, 10 a 13 dígitos.
    if (digits.length < 10 || digits.length > 13) {
      ctx.addIssue({ code: 'custom', path: ['value'], message: 'O telefone está fora do padrão para envio por WhatsApp.' })
    }
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.value.trim())) {
    ctx.addIssue({ code: 'custom', path: ['value'], message: 'O e-mail informado não é válido.' })
  }
})
type Schema = z.output<typeof schema>

const state = reactive<Schema>({ label: '', channel: 'whatsapp', value: '' })
const submitting = ref(false)

function syncFromProps() {
  const c = editing.value
  state.label = c?.label ?? ''
  state.channel = c?.channel ?? 'whatsapp'
  // O valor gravado trafega em dígitos; na edição a máscara é a do display.
  state.value = c?.value ?? ''
}

watch(() => props.open, (open) => {
  if (open) syncFromProps()
})

const title = computed(() => editing.value ? 'Editar Contato de envio' : 'Cadastrar Contato de envio')

const valueLabel = computed(() => state.channel === 'whatsapp' ? 'Telefone (WhatsApp)' : 'E-mail')
const valuePlaceholder = computed(() => state.channel === 'whatsapp' ? '(11) 98765-4321' : 'socio@empresa.com')

async function onSubmit(event: FormSubmitEvent<Schema>) {
  if (submitting.value) return
  submitting.value = true
  try {
    const label = event.data.label?.trim() || null
    // O telefone trafega em dígitos; o e-mail em minúsculas — a API
    // normaliza de novo, e a forma aqui evita um 422 por máscara.
    const value = event.data.channel === 'whatsapp'
      ? event.data.value.replace(/\D+/g, '')
      : event.data.value.trim().toLowerCase()
    const saved = editing.value
      ? await update(props.clientId, editing.value.id, { label, value })
      : await create(props.clientId, { label, channel: event.data.channel, value })
    toast.add({ title: editing.value ? 'Contato de envio atualizado' : 'Contato de envio cadastrado', color: 'success' })
    emit('saved', saved)
    isOpen.value = false
  } catch (e) {
    toast.add({
      title: 'Não foi possível salvar o Contato de envio',
      description: apiErrorMessage(e) ?? 'Tente novamente.',
      color: 'error'
    })
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <UModal
    v-model:open="isOpen"
    :title="title"
    :description="clientName ?? 'Um Contato de envio por canal: WhatsApp ou e-mail.'"
  >
    <template #body>
      <UForm
        id="dispatch-contact-form"
        :schema="schema"
        :state="state"
        class="space-y-4"
        @submit="onSubmit"
      >
        <UFormField label="Rótulo" name="label" hint="Opcional — ex.: Sócio 1">
          <UInput v-model="state.label" placeholder="Sócio 1" class="w-full" />
        </UFormField>

        <UFormField label="Canal" name="channel">
          <URadioGroup
            v-model="state.channel"
            :items="channelItems"
            orientation="horizontal"
            :disabled="editing !== null"
          />
          <p v-if="editing" class="mt-1 text-xs text-muted">
            O canal não troca na edição — mudar de canal é cadastrar um contato novo.
          </p>
        </UFormField>

        <UFormField :label="valueLabel" name="value">
          <UInput
            v-model="state.value"
            :type="state.channel === 'email' ? 'email' : 'tel'"
            :placeholder="valuePlaceholder"
            class="w-full"
          />
        </UFormField>
      </UForm>
    </template>

    <template #footer="{ close }">
      <div class="flex w-full items-center justify-end gap-1.5">
        <UButton
          label="Cancelar"
          color="neutral"
          variant="outline"
          @click="close"
        />
        <UButton
          :label="editing ? 'Salvar' : 'Cadastrar'"
          type="submit"
          form="dispatch-contact-form"
          :loading="submitting"
        />
      </div>
    </template>
  </UModal>
</template>
