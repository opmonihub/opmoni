<script setup lang="ts">
import type { Plan } from '#shared/types/admin'
import * as z from 'zod'
import { defaultOfficeErpAllowance, officeErpModuleOptions } from '#shared/utils/officeErpCommercial'
import { apiFailure } from '#shared/utils/access'
import { createLatestRequestRunner } from '~/utils/adminListFilters'
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui'
import DataTableColumnMenu from '~/components/data-table/ColumnMenu.vue'
import { panelBodyClass, panelFooterClass, panelFooterCountClass, panelTableUi } from '~/components/data-table/panel'

definePageMeta({
  middleware: ['auth', 'super-admin']
})
const { $api } = useNuxtApp()
const toast = useToast()
const plans = ref<Plan[]>([])
const loading = ref(true)
const navigation = useListNavigationState('admin:planos', {
  defaults: () => ({ q: '' }),
  normalize: value => ({ q: typeof (value as { q?: unknown })?.q === 'string' ? (value as { q: string }).q : '' })
})
const q = listNavigationField(navigation, 'q')
const columns: TableColumn<Plan>[] = [
  { accessorKey: 'name', header: 'Plano' },
  { id: 'users', header: 'Usuários' },
  { id: 'clients', header: 'Clientes' },
  { id: 'monitorings', header: 'Monitoramentos' },
  { id: 'actions' }
]
const columnVisibility = ref<Record<string, boolean>>({})
const hideableColumns = [
  { id: 'name', label: 'Plano' },
  { id: 'users', label: 'Usuários' },
  { id: 'clients', label: 'Clientes' },
  { id: 'monitorings', label: 'Monitoramentos' }
]
const rows = computed(() => {
  const term = q.value.trim().toLowerCase()
  if (!term)
    return plans.value
  return plans.value.filter(plan => plan.name.toLowerCase().includes(term) || plan.slug.toLowerCase().includes(term))
})
function limitValue(plan: Plan, key: 'users' | 'clients' | 'monitorings') {
  const value = plan.limits?.[key]
  return value === undefined || value === null ? null : value
}
const loadError = ref<unknown>(null)
const hasLoaded = ref(false)
const { isLoading, showError, retry } = useRetryableLoad({
  refresh: load,
  error: loadError,
  loading,
  loadErrorTitle: 'Não foi possível carregar os planos',
  refreshErrorTitle: 'Não foi possível atualizar os planos'
})
const runLatestLoad = createLatestRequestRunner()
onBeforeUnmount(runLatestLoad.invalidate)
function load() {
  loading.value = true
  loadError.value = null
  return runLatestLoad(signal => $api<Plan[]>('/admin/plans', { signal }), {
    onSuccess: (data) => {
      plans.value = data
      hasLoaded.value = true
    },
    onError: (error) => {
      loadError.value = error
    },
    onSettled: () => {
      loading.value = false
    }
  })
}
onMounted(load)
const editOpen = ref(false)
const editSchema = z.object({
  name: z.string().min(2, 'Nome muito curto'),
  users: z.number().int().min(0).nullable().default(null),
  clients: z.number().int().min(0).nullable().default(null),
  monitorings: z.number().int().min(0).nullable().default(null),
  erp_commercial: z.object({
    enabled: z.boolean(),
    licence_limit: z.number().int().min(0).nullable().default(null),
    module_keys: z.array(z.string())
  }).default(defaultOfficeErpAllowance)
})
type EditSchema = z.output<typeof editSchema>
const editTarget = ref<Plan | null>(null)
const editState = reactive<EditSchema>({ name: '', users: null, clients: null, monitorings: null, erp_commercial: defaultOfficeErpAllowance() })
const saving = ref(false)
let active = true
onBeforeUnmount(() => {
  active = false
})
const fieldErrors = ref<Record<string, string[]>>({})
function openEdit(plan: Plan) {
  if (saving.value)
    return
  fieldErrors.value = {}
  editTarget.value = plan
  editState.name = plan.name
  editState.users = plan.limits?.users ?? null
  editState.clients = plan.limits?.clients ?? null
  editState.monitorings = plan.limits?.monitorings ?? null
  editState.erp_commercial = plan.erp_commercial
    ? { ...plan.erp_commercial, module_keys: [...plan.erp_commercial.module_keys] }
    : defaultOfficeErpAllowance()
  editOpen.value = true
}
function planActions(plan: Plan) {
  return [{
    type: 'label' as const,
    label: 'Ações'
  }, {
    label: 'Editar plano',
    icon: 'i-lucide-pencil',
    onSelect: () => openEdit(plan)
  }]
}
async function onSave(event: FormSubmitEvent<EditSchema>) {
  if (!editTarget.value || saving.value)
    return
  const limits = { users: event.data.users ?? null, clients: event.data.clients ?? null, monitorings: event.data.monitorings ?? null }
  fieldErrors.value = {}
  saving.value = true
  try {
    await $api(`/admin/plans/${editTarget.value.id}`, {
      method: 'PUT',
      body: { name: event.data.name, limits, erp_commercial: event.data.erp_commercial ?? defaultOfficeErpAllowance() }
    })
    if (!active)
      return
    toast.add({ title: 'Plano atualizado', description: event.data.name, color: 'success' })
    editOpen.value = false
    await load()
  } catch (error) {
    if (!active)
      return
    fieldErrors.value = apiFailure(error).errors || {}
    toast.add({ title: 'Não foi possível atualizar o plano', color: 'error' })
  } finally {
    saving.value = false
  }
}
useAdminReadAuthority(() => {
  runLatestLoad.invalidate()
  plans.value = []
  hasLoaded.value = false
  loadError.value = null
  loading.value = false
  editOpen.value = false
  Object.assign(editState, { name: '', users: null, clients: null, monitorings: null, erp_commercial: defaultOfficeErpAllowance() })
  fieldErrors.value = {}
  active = false
})
</script>

<template>
  <DataTablePanelList
    title="Planos"
    description="Limites de usuários, clientes, monitoramentos e revenda ERP."
  >
    <template #toolbar>
      <UInput
        v-model="q"
        class="max-w-sm"
        icon="i-lucide-search"
        placeholder="Filtrar por nome ou slug..."
        aria-label="Buscar plano por nome ou slug"
      />
      <DataTableColumnMenu
        v-model="columnVisibility"
        :columns="hideableColumns"
        class="ms-auto shrink-0"
      />
    </template>

    <div :class="panelBodyClass">
      <ErrorRetryAlert
        v-if="showError"
        :title="hasLoaded ? 'Não foi possível atualizar. Os resultados anteriores foram mantidos.' : 'Não foi possível carregar os planos'"
        :loading="isLoading"
        @retry="retry"
      />

      <template v-if="!showError || hasLoaded">
        <UTable
          v-model:column-visibility="columnVisibility"
          :data="rows"
          :columns="columns"
          :loading="loading"
          class="shrink-0"
          :ui="panelTableUi"
        >
          <template #name-cell="{ row }">
            <p class="font-medium text-highlighted">
              {{ row.original.name }}
            </p>
            <p class="text-muted">
              {{ row.original.slug }}
            </p>
          </template>

          <template #users-header>
            <div class="text-right">
              Usuários
            </div>
          </template>
          <template #clients-header>
            <div class="text-right">
              Clientes
            </div>
          </template>
          <template #monitorings-header>
            <div class="text-right">
              Monitoramentos
            </div>
          </template>

          <template #users-cell="{ row }">
            <div class="text-right">
              <UBadge v-if="limitValue(row.original, 'users') === null" color="neutral" variant="subtle">
                Ilimitado
              </UBadge>
              <span v-else class="font-medium text-highlighted tabular-nums">{{ limitValue(row.original, 'users') }}</span>
            </div>
          </template>
          <template #clients-cell="{ row }">
            <div class="text-right">
              <UBadge v-if="limitValue(row.original, 'clients') === null" color="neutral" variant="subtle">
                Ilimitado
              </UBadge>
              <span v-else class="font-medium text-highlighted tabular-nums">{{ limitValue(row.original, 'clients') }}</span>
            </div>
          </template>
          <template #monitorings-cell="{ row }">
            <div class="text-right">
              <UBadge v-if="limitValue(row.original, 'monitorings') === null" color="neutral" variant="subtle">
                Ilimitado
              </UBadge>
              <span v-else class="font-medium text-highlighted tabular-nums">{{ limitValue(row.original, 'monitorings') }}</span>
            </div>
          </template>

          <template #actions-cell="{ row }">
            <DataTableRowActionsMenu
              :items="planActions(row.original)"
              :label="`Ações de ${row.original.name}`"
              flush
            />
          </template>

          <template #empty>
            <DataTablePanelTableEmpty
              icon="i-lucide-layers"
              label="Nenhum plano encontrado."
            />
          </template>
        </UTable>

        <div :class="panelFooterClass">
          <div :class="panelFooterCountClass">
            {{ rows.length }} de {{ plans.length }} plano(s)
          </div>
        </div>
      </template>
    </div>
  </DataTablePanelList>

  <USlideover
    v-model:open="editOpen"
    :dismissible="!saving"
    :close="!saving"
    title="Editar plano"
    :description="editTarget?.slug"
    :ui="{ footer: 'justify-end' }"
  >
    <template #body>
      <UForm
        id="edit-plan-form"
        :schema="editSchema"
        :state="editState"
        class="space-y-4"
        @submit="onSave"
      >
        <UFormField label="Nome" name="name" :error="fieldErrors['name']?.[0]">
          <UInput v-model="editState.name" class="w-full" />
        </UFormField>
        <UFormField
          label="Usuários"
          name="users"
          :error="fieldErrors['limits.users']?.[0]"
          hint="Vazio = ilimitado"
        >
          <UInputNumber
            v-model="editState.users"
            :min="0"
            :step="1"
            placeholder="Ilimitado"
            class="w-full"
          />
        </UFormField>
        <UFormField
          label="Clientes"
          name="clients"
          :error="fieldErrors['limits.clients']?.[0]"
          hint="Vazio = ilimitado"
        >
          <UInputNumber
            v-model="editState.clients"
            :min="0"
            :step="1"
            placeholder="Ilimitado"
            class="w-full"
          />
        </UFormField>
        <UFormField
          label="Monitoramentos"
          name="monitorings"
          :error="fieldErrors['limits.monitorings']?.[0]"
          hint="Vazio = ilimitado"
        >
          <UInputNumber
            v-model="editState.monitorings"
            :min="0"
            :step="1"
            placeholder="Ilimitado"
            class="w-full"
          />
        </UFormField>
        <USeparator label="Revenda ERP" />
        <UFormField name="erp_commercial.enabled" :error="fieldErrors['erp_commercial.enabled']?.[0] || fieldErrors['erp_commercial']?.[0]">
          <USwitch
            v-model="editState.erp_commercial.enabled"
            label="Permitir revenda ERP"
            description="Habilita o escritório a oferecer o ERP aos seus clientes."
          />
        </UFormField>
        <UFormField
          label="Licenças ERP"
          name="erp_commercial.licence_limit"
          :error="fieldErrors['erp_commercial.licence_limit']?.[0]"
          hint="Vazio = ilimitado"
        >
          <UInputNumber
            v-model="editState.erp_commercial.licence_limit"
            :min="0"
            :step="1"
            placeholder="Ilimitado"
            class="w-full"
          />
        </UFormField>
        <UFormField
          label="Módulos disponíveis para revenda"
          name="erp_commercial.module_keys"
          :error="fieldErrors['erp_commercial.module_keys']?.[0]"
          description="Selecione os módulos que o escritório pode oferecer. A seleção pode ficar vazia."
        >
          <USelectMenu
            v-model="editState.erp_commercial.module_keys"
            :items="officeErpModuleOptions"
            value-key="value"
            multiple
            placeholder="Nenhum módulo selecionado"
            :search-input="{ placeholder: 'Buscar módulo...' }"
            class="w-full"
          />
        </UFormField>
      </UForm>
    </template>

    <template #footer="{ close }">
      <UButton
        label="Cancelar"
        color="neutral"
        variant="outline"
        :disabled="saving"
        @click="close"
      />
      <UButton
        label="Salvar"
        type="submit"
        form="edit-plan-form"
        :loading="saving"
        :disabled="saving"
      />
    </template>
  </USlideover>
</template>
