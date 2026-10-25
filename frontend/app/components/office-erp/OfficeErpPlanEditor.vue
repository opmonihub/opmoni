<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type { OfficeErpOffer, OfficeErpOfferTerms } from '#shared/types/officeErpCommercial'
import { officeErpModuleOptions, parseOfficeErpMoney } from '#shared/utils/officeErpCommercial'

const props = withDefaults(defineProps<{
  open: boolean
  offer?: OfficeErpOffer | null
  moduleKeys?: string[]
  canSellNew: boolean
  saving?: boolean
  fieldErrors?: Record<string, string[]>
  failure?: string | null
  recoveryKey?: string | null
}>(), {
  offer: null,
  moduleKeys: () => [],
  saving: false,
  fieldErrors: () => ({}),
  failure: null,
  recoveryKey: null
})

const emit = defineEmits<{
  'update:open': [open: boolean]
  'save': [terms: OfficeErpOfferTerms]
  'recover': [key: string]
}>()

const form = reactive({
  name: '',
  description: '',
  price: '',
  intervalMonths: '1',
  dueInDays: '0',
  moduleKeys: [] as string[],
  published: false
})
const localErrors = ref<Record<string, string[]>>({})
const moneyError = computed(() => localErrors.value.price?.[0] ?? props.fieldErrors.price?.[0] ?? '')
const visibleModules = computed(() => officeErpModuleOptions.filter(option => props.moduleKeys.includes(option.value)))
const intervalOptions = [
  { label: 'Mensal', value: '1' },
  { label: 'Trimestral', value: '3' },
  { label: 'Semestral', value: '6' },
  { label: 'Anual', value: '12' }
]

watch(() => props.open, (open) => {
  if (!open) return
  const revision = props.offer?.current_revision
  form.name = revision?.name ?? props.offer?.name ?? ''
  form.description = revision?.description ?? props.offer?.description ?? ''
  form.price = revision?.price ?? ''
  form.intervalMonths = String(revision?.interval_months ?? 1)
  form.dueInDays = String(revision?.due_in_days ?? 0)
  form.moduleKeys = [...(revision?.module_keys ?? [])]
  form.published = revision?.published ?? false
  localErrors.value = {}
}, { immediate: true })

function setModule(moduleKey: string, checked: boolean) {
  form.moduleKeys = checked
    ? [...new Set([...form.moduleKeys, moduleKey])]
    : form.moduleKeys.filter(value => value !== moduleKey)
}

function submit() {
  if (!props.canSellNew || props.saving) return
  localErrors.value = {}
  let price: string
  try {
    price = parseOfficeErpMoney(form.price)
  } catch {
    localErrors.value = { price: ['Informe um valor em reais com até duas casas decimais.'] }
    return
  }

  const name = form.name.trim()
  const interval = Number.parseInt(form.intervalMonths, 10)
  const dueInDays = Number.parseInt(form.dueInDays, 10)
  const errors: Record<string, string[]> = {}
  if (!name) errors.name = ['Informe o nome da oferta.']
  if (![1, 3, 6, 12].includes(interval)) errors.interval_months = ['Selecione um ciclo válido.']
  if (!/^\d{1,5}$/.test(form.dueInDays) || dueInDays > 65535) errors.due_in_days = ['Informe de 0 a 65.535 dias.']
  if (form.moduleKeys.some(key => !props.moduleKeys.includes(key))) errors.module_keys = ['Remova módulos que não estão liberados para o escritório.']
  if (Object.keys(errors).length) {
    localErrors.value = errors
    return
  }

  emit('save', {
    name,
    description: form.description.trim(),
    price,
    currency: 'BRL',
    interval_months: interval as 1 | 3 | 6 | 12,
    due_in_days: dueInDays,
    module_keys: [...form.moduleKeys].sort(),
    published: form.published
  })
}

defineExpose({ form, submit })
</script>

<template>
  <USlideover
    :open="open"
    :title="offer ? 'Editar oferta' : 'Nova oferta'"
    description="Cada alteração cria uma revisão para novas vendas. Contratos existentes preservam os termos aceitos."
    side="right"
    @update:open="emit('update:open', $event)"
  >
    <template #body>
      <div class="flex h-full min-h-0 flex-col">
        <UAlert
          v-if="failure"
          color="error"
          title="Não foi possível salvar a oferta"
          :description="failure"
          class="mb-4"
        />
        <UAlert
          v-if="recoveryKey"
          color="warning"
          title="O resultado desta operação ainda não foi confirmado"
          class="mb-4"
        >
          <template #description>
            <div class="flex flex-wrap items-center justify-between gap-3">
              <span>Verifique a operação pelo identificador original antes de tentar novamente.</span>
              <UButton
                size="sm"
                color="neutral"
                variant="outline"
                :loading="saving"
                :disabled="saving"
                @click="emit('recover', recoveryKey!)"
              >
                Verificar resultado
              </UButton>
            </div>
          </template>
        </UAlert>

        <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
          <div class="flex-1 space-y-4 overflow-y-auto pe-1">
            <UFormField
              name="name"
              label="Nome da oferta"
              required
              :error="localErrors.name?.[0] ?? fieldErrors.name?.[0]"
            >
              <UInput
                v-model="form.name"
                maxlength="255"
                autocomplete="off"
                class="w-full"
              />
            </UFormField>
            <UFormField name="description" label="Descrição">
              <UTextarea
                v-model="form.description"
                :rows="3"
                autoresize
                :maxrows="6"
                class="w-full"
              />
            </UFormField>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <UFormField
                name="price"
                label="Preço (R$)"
                required
                :error="moneyError"
              >
                <UInput
                  v-model="form.price"
                  type="text"
                  inputmode="decimal"
                  autocomplete="off"
                  placeholder="0,00"
                  class="w-full"
                />
              </UFormField>
              <UFormField
                name="interval_months"
                label="Ciclo de cobrança"
                required
                :error="localErrors.interval_months?.[0] ?? fieldErrors.interval_months?.[0]"
              >
                <USelect v-model="form.intervalMonths" :items="intervalOptions" class="w-full" />
              </UFormField>
            </div>

            <UFormField
              name="due_in_days"
              label="Prazo para vencimento (dias)"
              required
              :error="localErrors.due_in_days?.[0] ?? fieldErrors.due_in_days?.[0]"
            >
              <UInput
                v-model="form.dueInDays"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                class="w-full"
              />
            </UFormField>

            <UFormField name="module_keys" label="Módulos incluídos" :error="localErrors.module_keys?.[0] ?? fieldErrors.module_keys?.[0]">
              <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <UCheckbox
                  v-for="module in visibleModules"
                  :key="module.value"
                  :model-value="form.moduleKeys.includes(module.value)"
                  :label="module.label"
                  :disabled="!canSellNew || saving"
                  @update:model-value="setModule(module.value, !!$event)"
                />
              </div>
              <p v-if="!visibleModules.length" class="mt-2 text-sm text-muted">
                Nenhum módulo está liberado para novas ofertas.
              </p>
            </UFormField>

            <UCheckbox v-model="form.published" label="Publicar esta revisão para novas vendas" :disabled="!canSellNew || saving" />
            <p class="text-sm text-muted">
              A publicação não altera o valor nem os módulos de contratos já aceitos.
            </p>
          </div>

          <div class="mt-5 flex flex-col-reverse justify-end gap-2 border-t border-default pt-4 sm:flex-row">
            <UButton
              color="neutral"
              variant="outline"
              :disabled="saving"
              @click="emit('update:open', false)"
            >
              Cancelar
            </UButton>
            <UButton type="submit" :loading="saving" :disabled="!canSellNew || saving || !!recoveryKey">
              Salvar oferta
            </UButton>
          </div>
        </form>
      </div>
    </template>
  </USlideover>
</template>
