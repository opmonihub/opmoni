<script setup lang="ts">
import type { ErpCashRegister } from '#shared/types/erpBusiness'
import { decimalTextIsValid } from '#shared/utils/erpFinance'
import { retainedChoiceItem } from '~/utils/erpFinancialChoiceCompatibility'

const props = withDefaults(defineProps<{
  mode: 'register' | 'open'
  busy?: boolean
  errors?: Record<string, string>
  referencesCurrent?: boolean
  referenceError?: string
  registers?: ErpCashRegister[]
  establishments?: { id: number, name: string, status?: string }[]
}>(), { busy: false, errors: () => ({}), referencesCurrent: true, registers: () => [], establishments: () => [] })
const emit = defineEmits<{ submit: [payload: Record<string, unknown>] }>()
const state = reactive({ name: '', erp_establishment_id: 'none', erp_cash_register_id: 'none', opening_amount: '', currency: 'BRL' })
const localError = ref('')
const zero = (value: string) => /^0+(?:\.0{1,4})?$/.test(value)
const activeRegisters = computed(() => props.registers.filter(item => item.status === 'active'))
const registerItems = computed(() => retainedChoiceItem(activeRegisters.value, state.erp_cash_register_id, register => register.name, id => `Caixa #${id} (seleção indisponível)`))
const activeEstablishments = computed(() => props.establishments.filter(item => item.status !== 'inactive'))
const establishmentItems = computed(() => retainedChoiceItem(activeEstablishments.value, state.erp_establishment_id, establishment => establishment.name, id => `Estabelecimento #${id} (seleção indisponível)`))
function submit() {
  localError.value = ''
  if (!props.referencesCurrent) {
    localError.value = props.referenceError ?? 'As referências financeiras não estão atualizadas. Atualize as listas antes de continuar.'
    return
  }
  if (props.mode === 'register') {
    const establishmentId = Number(state.erp_establishment_id)
    if (!state.name.trim() || !activeEstablishments.value.some(item => item.id === establishmentId)) {
      localError.value = 'Informe o nome e selecione um estabelecimento ativo.'
      return
    }
    emit('submit', { name: state.name.trim(), erp_establishment_id: establishmentId })
    return
  }
  const registerId = Number(state.erp_cash_register_id)
  if (!activeRegisters.value.some(item => item.id === registerId)) {
    localError.value = 'O caixa selecionado não está mais ativo. Atualize a lista e selecione outro caixa.'
    return
  }
  if (!(decimalTextIsValid(state.opening_amount) || zero(state.opening_amount))) {
    localError.value = 'Informe um valor de abertura válido, incluindo zero, com até quatro casas decimais.'
    return
  }
  if (!/^[A-Z]{3}$/.test(state.currency)) {
    localError.value = 'Informe a moeda em três letras maiúsculas.'
    return
  }
  emit('submit', { erp_cash_register_id: registerId, opening_amount: state.opening_amount, currency: state.currency })
}
defineExpose({ state, submit, localError, registerItems, establishmentItems })
</script>

<template>
  <section data-cash-session-form class="space-y-4">
    <UAlert
      v-if="localError"
      color="error"
      variant="subtle"
      :title="localError"
    />
    <UForm :state="state" class="grid gap-3 sm:grid-cols-2" @submit="submit">
      <template v-if="props.mode === 'register'">
        <UFormField
          label="Nome do caixa"
          name="name"
          required
          :error="errors?.name"
        >
          <UInput v-model="state.name" maxlength="120" autocomplete="off" />
        </UFormField>
        <UFormField
          label="Estabelecimento"
          name="erp_establishment_id"
          required
          :error="errors?.erp_establishment_id"
        >
          <USelect v-model="state.erp_establishment_id" :items="[{ label: 'Selecione', value: 'none' }, ...establishmentItems]" />
        </UFormField>
      </template>
      <template v-else>
        <UFormField
          label="Caixa"
          name="erp_cash_register_id"
          required
          :error="errors?.erp_cash_register_id"
        >
          <USelect v-model="state.erp_cash_register_id" :items="[{ label: 'Selecione', value: 'none' }, ...registerItems]" />
        </UFormField>
        <UFormField
          label="Valor de abertura"
          name="opening_amount"
          required
          description="Use ponto para separar as casas decimais."
          :error="errors?.opening_amount"
        >
          <UInput
            v-model="state.opening_amount"
            type="text"
            inputmode="decimal"
            autocomplete="off"
          />
        </UFormField>
        <UFormField
          label="Moeda"
          name="currency"
          required
          :error="errors?.currency"
        >
          <UInput v-model="state.currency" maxlength="3" autocapitalize="characters" />
        </UFormField>
      </template>
      <div class="flex items-end">
        <UButton
          type="submit"
          :loading="busy"
          :disabled="busy"
          :label="props.mode === 'register' ? 'Cadastrar caixa' : 'Abrir sessão'"
        />
      </div>
    </UForm>
  </section>
</template>
