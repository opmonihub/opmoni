<script setup lang="ts">
import { computed, ref, toRef, watch } from 'vue'
import { officeErpModuleOptions } from '#shared/utils/officeErpCommercial'
import { useModalFocusReturn } from '~/composables/useModalFocusReturn'
import { focusFormError } from '~/utils/formPresentation'

interface Revision {
  id?: number
  revision_number?: number
  price: string
  interval_months: number
  due_in_days: number
  module_keys: string[]
  service_period_start?: string | null
}

interface Terms {
  price: string
  interval_months: 1 | 3 | 6 | 12
  due_in_days: number
  module_keys: string[]
  expected_pending_revision_id: number | null
}

const props = withDefaults(defineProps<{
  open: boolean
  companyName: string
  planName?: string
  companyId?: number | null
  contractId: number
  currentRevision: Revision | null
  pendingRevision: Revision | null
  availableModuleKeys: string[]
  canAmend: boolean
  pending?: boolean
  error?: string | null
  recoveryKey?: string | null
  fieldErrors?: Record<string, string[]>
}>(), {
  companyId: null,
  planName: 'Plano ERP',
  pending: false,
  error: null,
  recoveryKey: null,
  fieldErrors: () => ({})
})

const emit = defineEmits<{
  'update:open': [open: boolean]
  'submit': [request: { contractId: number, companyId: number | null, terms: Terms }]
  'recover': [key: string]
}>()

const restoreModalFocus = useModalFocusReturn(toRef(props, 'open'))
const sourceRevision = computed(() => props.pendingRevision ?? props.currentRevision)
const selected = ref<string[]>([])
const discardOpen = ref(false)
const localError = ref('')
watch([localError, () => props.fieldErrors], () => {
  if (props.open) void focusFormError(undefined, 'office-erp-modules')
})
const initialSelection = ref('')
const working = computed(() => props.pending)
const blocked = computed(() => working.value || !!props.recoveryKey)
const dirty = computed(() => JSON.stringify([...selected.value].sort()) !== initialSelection.value)
const catalog = officeErpModuleOptions
const currentKeys = computed(() => props.currentRevision?.module_keys ?? [])
const pendingKeys = computed(() => props.pendingRevision?.module_keys ?? [])

function eligibleForNextCycle(key: string) {
  return props.availableModuleKeys.includes(key) || currentKeys.value.includes(key) || pendingKeys.value.includes(key)
}

function requestClose() {
  if (working.value) return
  if (props.recoveryKey) {
    emit('update:open', false)
    return
  }
  if (dirty.value) discardOpen.value = true
  else emit('update:open', false)
}

function toggleModule(key: string, enabled: boolean | 'indeterminate') {
  if (!props.canAmend || blocked.value || !eligibleForNextCycle(key)) return
  const next = new Set(selected.value)
  if (enabled === true) next.add(key)
  else next.delete(key)
  selected.value = [...next]
  localError.value = ''
}

function submit() {
  const revision = sourceRevision.value
  if (!props.canAmend || blocked.value || !revision || !dirty.value) return
  const keys = [...new Set(selected.value)].sort()
  if (keys.some(key => !catalog.some(module => module.value === key))) {
    localError.value = 'A seleção contém um módulo sem suporte para esta assinatura.'
    return
  }
  if (keys.some(key => !eligibleForNextCycle(key))) {
    localError.value = 'Remova os módulos que não estão autorizados para novas ativações.'
    return
  }
  localError.value = ''
  emit('submit', {
    contractId: props.contractId,
    companyId: props.companyId,
    terms: {
      price: revision.price,
      interval_months: revision.interval_months as Terms['interval_months'],
      due_in_days: revision.due_in_days,
      module_keys: keys,
      expected_pending_revision_id: props.pendingRevision?.id ?? null
    }
  })
}

watch(() => props.open, (open) => {
  if (!open) {
    discardOpen.value = false
    return
  }
  const revision = sourceRevision.value
  selected.value = [...(revision?.module_keys ?? [])]
  initialSelection.value = JSON.stringify([...selected.value].sort())
  localError.value = ''
  discardOpen.value = false
}, { immediate: true })

watch(() => [props.companyId, props.contractId] as const, (current, previous) => {
  if (props.open && (current[0] !== previous[0] || current[1] !== previous[1]))
    emit('update:open', false)
})
</script>

<template>
  <UModal
    :open="open"
    title="Gerenciar módulos"
    :description="companyName"
    :dismissible="!pending"
    :close="!pending"
    scrollable
    :ui="{
      content: 'flex w-[calc(100%-1rem)] max-h-[calc(100dvh-2rem)] max-w-xl flex-col overflow-hidden sm:w-full',
      header: 'shrink-0',
      body: 'min-h-0 flex-1 overflow-y-auto',
      footer: 'shrink-0 flex-wrap justify-end'
    }"
    @update:open="value => { if (!value) requestClose() }"
    @after:leave="restoreModalFocus"
  >
    <template #body>
      <div class="space-y-4">
        <UAlert
          v-if="error"
          color="error"
          title="Não foi possível agendar os módulos"
          :description="error"
        />
        <UAlert
          v-if="recoveryKey"
          color="warning"
          variant="subtle"
          title="O resultado da operação ainda não foi confirmado"
        >
          <template #description>
            <div class="flex flex-wrap items-center justify-between gap-3">
              <span>Consulte o resultado pelo identificador original antes de tentar novamente.</span>
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                :loading="pending"
                :disabled="pending"
                @click="emit('recover', recoveryKey!)"
              >
                Verificar resultado
              </UButton>
            </div>
          </template>
        </UAlert>

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-default pb-3">
          <div>
            <p class="text-xs text-muted">
              Plano atual
            </p>
            <p class="mt-1 text-sm font-medium text-highlighted">
              {{ currentRevision ? planName : 'Sem revisão atual' }}
            </p>
          </div>
          <UBadge v-if="pendingRevision" color="warning" variant="subtle">
            Alteração agendada
          </UBadge>
          <UBadge v-else color="neutral" variant="subtle">
            Seleção vigente
          </UBadge>
        </div>

        <p class="text-sm text-muted">
          Escolha os recursos previstos para o próximo ciclo. Os módulos vigentes continuam disponíveis até a data de aplicação.
        </p>

        <UForm
          id="office-erp-modules"
          :state="{ module_keys: selected }"
          @submit="submit"
          @error="event => focusFormError(event, 'office-erp-modules')"
        >
          <UFormField name="module_keys" :error="localError || fieldErrors.module_keys?.[0]" :error-pattern="/^module_keys\..*/">
            <div class="divide-y divide-default" aria-label="Seleção de módulos para o próximo ciclo">
              <div v-for="module in catalog" :key="module.value" class="flex min-w-0 items-center justify-between gap-4 py-4">
                <span class="min-w-0 flex-1">
                  <span class="block text-sm font-medium text-highlighted">{{ module.label }}</span>
                  <span class="mt-1 block text-xs text-muted">{{ currentKeys.includes(module.value) ? 'Habilitado atualmente' : 'Desabilitado atualmente' }}<template v-if="pendingRevision && pendingKeys.includes(module.value) !== currentKeys.includes(module.value)"> · {{ pendingKeys.includes(module.value) ? 'incluído' : 'remoção' }} no próximo ciclo</template></span>
                </span>
                <USwitch
                  :model-value="selected.includes(module.value)"
                  :aria-label="module.label"
                  :disabled="!canAmend || blocked || !eligibleForNextCycle(module.value)"
                  @update:model-value="toggleModule(module.value, $event)"
                />
              </div>
            </div>
          </UFormField>
        </UForm>

        <UAlert
          color="neutral"
          variant="subtle"
          icon="i-lucide-calendar-clock"
          title="Aplicação no próximo ciclo"
          description="A seleção vigente permanece até a data de aplicação. Faturas anteriores e dados da empresa são preservados."
        />
        <UAlert
          v-if="!canAmend"
          color="warning"
          variant="subtle"
          title="Alterações indisponíveis"
          description="A conta atual não tem autorização para agendar alterações nesta assinatura."
        />
        <UAlert
          v-if="localError"
          color="error"
          title="Revise a seleção"
          :description="localError"
        />
      </div>
    </template>
    <template #footer>
      <UButton
        color="neutral"
        variant="outline"
        :disabled="working"
        class="min-h-11 sm:min-h-0"
        @click="requestClose"
      >
        Fechar
      </UButton>
      <UButton
        color="neutral"
        :loading="pending"
        :disabled="!canAmend || blocked || !dirty || !sourceRevision"
        class="min-h-11 sm:min-h-0"
        type="submit"
        form="office-erp-modules"
      >
        Agendar alterações
      </UButton>
    </template>
  </UModal>

  <UModal
    v-model:open="discardOpen"
    title="Descartar alterações?"
    description="A seleção de módulos não enviada será perdida."
    :ui="{ content: 'w-[calc(100%-1rem)] max-w-md sm:w-full', footer: 'justify-end' }"
  >
    <template #footer>
      <UButton color="neutral" variant="outline" @click="discardOpen = false">
        Continuar editando
      </UButton>
      <UButton color="error" @click="emit('update:open', false)">
        Descartar alterações
      </UButton>
    </template>
  </UModal>
</template>
