<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { Client, ClientUpdatePayload, CnpjRefreshPreview } from '~/types/client'

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

const { update, refreshPreview, refreshCnpj } = useClients()
const toast = useToast()

const schema = z.object({
  name: z.string().min(2, 'Informe o nome completo').max(255).optional(),
  status: z.enum(['active', 'inactive']),
  tax_regime: z.enum(['mei', 'simple_national', 'presumed_profit', 'actual_profit', 'other', 'not_applicable'])
})

type Schema = z.output<typeof schema>

const state = reactive({
  name: '',
  status: 'active' as 'active' | 'inactive',
  tax_regime: 'presumed_profit' as Schema['tax_regime']
})

const submitting = ref(false)
const refreshing = ref(false)
const refreshData = ref<CnpjRefreshPreview | null>(null)
const confirmingRefresh = ref(false)

const isCompany = computed(() => props.client?.person_type === 'company')
const isIndividual = computed(() => props.client?.person_type === 'individual')

const regimeLocked = computed(() => {
  if (!isCompany.value) return null
  const regime = props.client?.tax_regime
  if (regime === 'mei' || regime === 'simple_national') return regime
  return null
})

const regimeOptions = computed(() => {
  if (regimeLocked.value === 'mei') return [{ label: 'MEI', value: 'mei' }]
  if (regimeLocked.value === 'simple_national') return [{ label: 'Simples Nacional', value: 'simple_national' }]
  return [
    { label: 'Presumido', value: 'presumed_profit' },
    { label: 'Real', value: 'actual_profit' },
    { label: 'Outro', value: 'other' }
  ]
})

const statusOptions = [
  { label: 'Ativo', value: 'active' },
  { label: 'Inativo', value: 'inactive' }
]

function syncFromClient() {
  const c = props.client
  if (!c) return
  state.name = c.name ?? ''
  state.status = c.status ?? 'active'
  state.tax_regime = (c.tax_regime ?? (c.person_type === 'individual' ? 'not_applicable' : 'presumed_profit')) as Schema['tax_regime']
  refreshData.value = null
  confirmingRefresh.value = false
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
      status: event.data.status
    }
    if (isIndividual.value) {
      payload.name = event.data.name
      payload.tax_regime = 'not_applicable'
    } else {
      payload.tax_regime = event.data.tax_regime
    }
    const saved = await update(props.client.id, payload)
    toast.add({ title: 'Cadastro atualizado', color: 'success' })
    emit('saved', saved)
    isOpen.value = false
  } catch {
    toast.add({ title: 'Não foi possível salvar o cadastro', color: 'error' })
  } finally {
    submitting.value = false
  }
}

async function onRefreshPreview() {
  if (!props.client) return
  refreshing.value = true
  try {
    refreshData.value = await refreshPreview(props.client.id)
    confirmingRefresh.value = true
  } catch {
    toast.add({ title: 'Não foi possível consultar a Receita', color: 'error' })
  } finally {
    refreshing.value = false
  }
}

async function onConfirmRefresh() {
  if (!props.client) return
  refreshing.value = true
  try {
    const saved = await refreshCnpj(props.client.id)
    toast.add({ title: 'Dados atualizados pela Receita', color: 'success' })
    emit('saved', saved)
    confirmingRefresh.value = false
    refreshData.value = null
    isOpen.value = false
  } catch {
    toast.add({ title: 'Não foi possível aplicar a atualização', color: 'error' })
  } finally {
    refreshing.value = false
  }
}

const refreshEntries = computed(() => {
  if (!refreshData.value) return []
  return Object.entries(refreshData.value.changes).map(([field, change]) => ({
    field,
    ...(change as { from: unknown, to: unknown })
  }))
})
</script>

<template>
  <UModal
    v-bind="$attrs"
    v-model:open="isOpen"
    title="Editar cadastro"
    :description="client ? client.name : 'Atualizar dados cadastrais'"
  >
    <template #body>
      <UForm
        id="client-cadastro-form"
        :schema="schema"
        :state="state"
        class="space-y-4"
        @submit="onSubmit"
      >
        <UFormField
          v-if="isIndividual"
          label="Nome completo"
          name="name"
          required
        >
          <UInput v-model="state.name" placeholder="Nome do cliente" class="w-full" />
        </UFormField>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <UFormField label="Situação" name="status" required>
            <USelect
              v-model="state.status"
              :items="statusOptions"
              value-key="value"
              class="w-full"
            />
          </UFormField>

          <UFormField
            v-if="isCompany"
            label="Regime tributário"
            name="tax_regime"
            :help="regimeLocked ? 'Definido pela Receita (MEI/Simples)' : undefined"
            required
          >
            <USelect
              v-model="state.tax_regime"
              :items="regimeOptions"
              value-key="value"
              class="w-full"
              :disabled="!!regimeLocked"
            />
          </UFormField>

          <UFormField
            v-else
            label="Regime tributário"
            name="tax_regime"
          >
            <UInput value="Não aplicável" class="w-full" disabled />
          </UFormField>
        </div>

        <template v-if="isCompany">
          <USeparator />
          <div class="space-y-2">
            <UButton
              label="Atualizar pela Receita"
              icon="i-lucide-refresh-cw"
              color="neutral"
              variant="outline"
              type="button"
              :loading="refreshing"
              @click="onRefreshPreview"
            />
            <UAlert
              v-if="confirmingRefresh && refreshData"
              color="warning"
              variant="subtle"
              title="Alterações encontradas na Receita"
              description="Confira abaixo e confirme para aplicar."
            />
            <ul v-if="confirmingRefresh && refreshEntries.length" class="space-y-1 text-sm">
              <li
                v-for="entry in refreshEntries"
                :key="entry.field"
                class="flex justify-between gap-4"
              >
                <span class="text-muted">{{ entry.field }}</span>
                <span>{{ String(entry.from ?? '—') }} → {{ String(entry.to ?? '—') }}</span>
              </li>
            </ul>
            <p v-if="confirmingRefresh && !refreshEntries.length" class="text-sm text-muted">
              Nenhuma alteração encontrada.
            </p>
            <div v-if="confirmingRefresh" class="flex justify-end gap-2">
              <UButton
                label="Descartar"
                color="neutral"
                variant="subtle"
                type="button"
                @click="confirmingRefresh = false; refreshData = null"
              />
              <UButton
                label="Confirmar atualização"
                color="warning"
                variant="solid"
                type="button"
                :loading="refreshing"
                :disabled="!refreshEntries.length"
                @click="onConfirmRefresh"
              />
            </div>
          </div>
        </template>
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
        form="client-cadastro-form"
        :loading="submitting"
      />
    </template>
  </UModal>
</template>
