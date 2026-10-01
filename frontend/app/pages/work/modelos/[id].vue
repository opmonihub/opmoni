<script setup lang="ts">
import type { TabsItem } from '@nuxt/ui'
import { apiMessage } from '~/composables/useApiError'
import type { ClientTag, TaxRegime } from '~/types/client'
import type { WorkPreviewRow, WorkTaskPriority, WorkTemplate, WorkTemplatePayload } from '~/types/work'
import { taxRegimeLabel } from '~/utils/portfolioLabels'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const router = useRouter()
const toast = useToast()
const { canManageWork } = useAuth()
const { $api } = useNuxtApp()
const { showTemplate, createTemplate, updateTemplate, previewTemplate, generateTemplate } = useWork()

function parseId(param: unknown): number | null {
  const raw = Array.isArray(param) ? param[0] : param
  const id = Number(raw)
  return Number.isInteger(id) && id > 0 ? id : null
}

const routeId = computed(() => parseId(route.params.id))
const isNew = computed(() => routeId.value === null)
const templateId = computed(() => routeId.value)

const loading = ref(!isNew.value)
const loadError = ref(false)
const saving = ref(false)
const generating = ref(false)
const referenceMonth = ref('2026-03')

interface StepDraft {
  key: number
  id?: number
  title: string
  department_id: number | null
  due_day: number
  priority: WorkTaskPriority
  order: number
  default_assignee_member_id: number | null
}

interface ExceptionDraft {
  client_id: number
  kind: 'added' | 'removed'
}

const form = reactive({
  name: '',
  description: '',
  cascade: false,
  is_active: true,
  generate_day: 1,
  due_day: 20,
  regimes: [] as TaxRegime[],
  tag_ids: [] as number[],
  exceptions: [] as ExceptionDraft[],
  steps: [] as StepDraft[]
})

let stepKey = 1

function blankStep(order: number): StepDraft {
  return {
    default_assignee_member_id: null,
    department_id: null,
    due_day: 20,
    key: stepKey++,
    order,
    priority: 'medium',
    title: ''
  }
}

function syncFromTemplate(template: WorkTemplate) {
  form.name = template.name ?? ''
  form.description = template.description ?? ''
  form.cascade = template.cascade
  form.is_active = template.is_active
  form.generate_day = template.generate_day
  form.due_day = template.due_day
  form.regimes = [...(template.regimes ?? [])] as TaxRegime[]
  form.tag_ids = [...(template.tags ?? []).map(tag => tag.id)]
  form.exceptions = [...(template.exceptions ?? [])]
  form.steps = (template.steps ?? []).map(step => ({
    key: stepKey++,
    id: step.id,
    title: step.title,
    department_id: step.department_id,
    due_day: step.due_day,
    priority: step.priority,
    order: step.order,
    default_assignee_member_id: step.default_assignee_member_id
  }))
}

async function load() {
  if (isNew.value) {
    form.steps = [blankStep(1), blankStep(2)]
    return
  }
  loading.value = true
  loadError.value = false
  try {
    syncFromTemplate(await showTemplate(templateId.value as number))
  } catch {
    // No toast here: the composable below watches `loadError` and owns it.
    loadError.value = true
  } finally {
    loading.value = false
  }
}

await load()

watch(templateId, () => void load())

/**
 * `load` is this page's own loader — it resolves (or fails) through
 * `loadError`/`loading` instead of a `useAsyncData` ref, so the composable is
 * pointed at those two. `refresh` is the composable's, and the alert's retry
 * answers through `retry` so a failed retry clears the failure first.
 */
const { showError, retry } = useRetryableLoad({
  refresh: load,
  error: loadError,
  loading,
  loadErrorTitle: 'Não foi possível carregar o modelo'
})

const { data: tagCatalog } = await useAsyncData<ClientTag[]>(
  'work-model-tags',
  async () => {
    const res = await $api<{ data?: ClientTag[] } | ClientTag[]>('/tags')
    return Array.isArray(res) ? res : (res.data ?? [])
  },
  { default: () => [] as ClientTag[] }
)

const { memberOptions } = useDirectory()
const { list: listDepartments } = useDepartments()

const { data: departmentCatalog } = await useAsyncData(
  'work-model-departments',
  () => listDepartments(),
  { default: () => [] }
)

const departmentItems = computed(() => [
  { label: 'Sem departamento', value: null },
  ...(departmentCatalog.value ?? []).map(department => ({ label: department.name, value: department.id }))
])

const previewKey = computed(() => (isNew.value ? 'new' : String(templateId.value)))
const previewEnabled = computed(() => !isNew.value)

const { data: previewData, status: previewStatus, error: previewError, refresh: refreshPreview } = await useAsyncData<WorkPreviewRow[]>(
  'work-model-preview',
  () => previewTemplate(templateId.value as number),
  { watch: [previewKey], immediate: previewEnabled.value }
)

watch(previewEnabled, (enabled) => {
  if (enabled) void refreshPreview()
})

const previewRows = computed<WorkPreviewRow[]>(() => previewData.value ?? [])
const previewLoading = computed(() => previewStatus.value === 'pending')

const addedCount = computed(() => previewRows.value.filter(row => row.reason === 'added').length)
const ruleCount = computed(() => previewRows.value.filter(row => row.reason === 'rule').length)

function reasonPresentation(reason: WorkPreviewRow['reason']): { label: string, color: 'info' | 'success' } {
  return reason === 'added' ? { label: 'Incluído (exceção)', color: 'success' } : { label: 'Pela regra', color: 'info' }
}

const tabs: TabsItem[] = [
  { label: 'Associação', value: 'assoc', icon: 'i-lucide-link' },
  { label: 'Clientes e Exceções', value: 'clients', icon: 'i-lucide-users' },
  { label: 'Prazo', value: 'deadline', icon: 'i-lucide-calendar' },
  { label: 'Tarefas', value: 'tasks', icon: 'i-lucide-list-checks' },
  { label: 'Recorrência', value: 'recurrence', icon: 'i-lucide-repeat' }
]
const tab = ref('assoc')

const regimeOptions = computed(() => (Object.keys(taxRegimeLabel) as TaxRegime[]).map(value => ({
  label: taxRegimeLabel[value],
  value
})))

const priorityOptions = [
  { label: 'Baixa', value: 'low' },
  { label: 'Média', value: 'medium' },
  { label: 'Alta', value: 'high' },
  { label: 'Urgente', value: 'urgent' }
]

const assigneeItems = computed(() => [
  { label: 'Sem responsável', value: null },
  ...memberOptions.value
])

function addStep() {
  form.steps.push(blankStep(form.steps.length + 1))
}

function removeStep(key: number) {
  form.steps = form.steps.filter(step => step.key !== key).map((step, index) => ({ ...step, order: index + 1 }))
}

function moveStep(key: number, delta: -1 | 1) {
  const index = form.steps.findIndex(step => step.key === key)
  const target = index + delta
  if (index < 0 || target < 0 || target >= form.steps.length) return
  const current = form.steps[index]
  const other = form.steps[target]
  if (!current || !other) return
  form.steps[index] = other
  form.steps[target] = current
  form.steps.forEach((step, position) => {
    step.order = position + 1
  })
}

function addException(kind: 'added' | 'removed', clientId: number) {
  if (!Number.isInteger(clientId) || clientId <= 0) return
  if (form.exceptions.some(exception => exception.client_id === clientId)) {
    toast.add({ title: 'Cliente já está nas exceções', color: 'warning' })
    return
  }
  form.exceptions.push({ client_id: clientId, kind })
}

const addedClientId = ref('')
const removedClientId = ref('')

function removeException(clientId: number) {
  form.exceptions = form.exceptions.filter(exception => exception.client_id !== clientId)
}

function buildPayload(): WorkTemplatePayload {
  const droppedSteps = form.steps.filter(step => step.title.trim().length === 0).length
  if (droppedSteps > 0) {
    toast.add({ title: droppedSteps === 1 ? '1 etapa sem título foi ignorada' : `${droppedSteps} etapas sem título foram ignoradas`, color: 'warning' })
  }
  return {
    name: form.name.trim(),
    description: form.description.trim() || null,
    cascade: form.cascade,
    generate_day: form.generate_day,
    due_day: form.due_day,
    is_active: form.is_active,
    regimes: [...form.regimes],
    tag_ids: [...form.tag_ids],
    exceptions: form.exceptions.map(exception => ({ ...exception })),
    steps: form.steps
      .filter(step => step.title.trim().length > 0)
      .map((step, index) => ({
        ...(step.id ? { id: step.id } : {}),
        title: step.title.trim(),
        department_id: step.department_id,
        description: null,
        due_day: step.due_day,
        priority: step.priority,
        order: index + 1,
        default_assignee_member_id: step.default_assignee_member_id
      }))
  }
}

async function onSave() {
  if (!form.name.trim()) {
    toast.add({ title: 'Informe o título do modelo', color: 'error' })
    return
  }
  saving.value = true
  try {
    const payload = buildPayload()
    const saved = isNew.value
      ? await createTemplate(payload)
      : await updateTemplate(templateId.value as number, payload)
    toast.add({ title: isNew.value ? 'Modelo criado' : 'Modelo atualizado', color: 'success' })
    if (!isNew.value) syncFromTemplate(saved)
    await router.push(`/work/modelos/${saved.id}`)
  } catch (error: unknown) {
    toast.add({ title: 'Não foi possível salvar o modelo', description: apiMessage(error), color: 'error' })
  } finally {
    saving.value = false
  }
}

const canGenerate = computed(() => !isNew.value && /^\d{4}-(0[1-9]|1[0-2])$/.test(referenceMonth.value))

async function onGenerate() {
  if (isNew.value || !canGenerate.value) return
  generating.value = true
  try {
    const created = await generateTemplate(templateId.value as number, referenceMonth.value)
    toast.add({ title: `${created.length} processo(s) gerado(s) em ${referenceMonth.value}`, color: 'success' })
  } catch (error: unknown) {
    toast.add({ title: 'Não foi possível gerar os processos', description: apiMessage(error), color: 'error' })
  } finally {
    generating.value = false
  }
}
</script>

<template>
  <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-4 overflow-y-auto p-3 sm:gap-5 sm:p-4 lg:p-5">
    <header class="flex min-w-0 flex-wrap items-center gap-2">
      <UButton
        icon="i-lucide-arrow-left"
        color="neutral"
        variant="ghost"
        aria-label="Voltar para modelos"
        to="/work/modelos"
      />
      <div class="flex min-w-0 flex-1 items-center gap-2.5">
        <UIcon name="i-lucide-shapes" class="size-5 shrink-0 text-primary" />
        <h2 class="truncate text-base font-semibold tracking-tight text-highlighted sm:text-lg">
          {{ isNew ? 'Novo modelo' : (form.name || 'Modelo') }}
        </h2>
      </div>
      <UButton
        v-if="canManageWork"
        label="Salvar"
        icon="i-lucide-check"
        color="primary"
        size="sm"
        :loading="saving"
        :disabled="saving"
        @click="onSave"
      />
    </header>

    <ErrorRetryAlert
      v-if="showError"
      title="Não foi possível carregar o modelo"
      @retry="retry"
    />

    <div
      v-else-if="loading"
      class="flex flex-col gap-3"
      aria-busy="true"
      aria-label="Carregando modelo"
    >
      <UCard variant="subtle" :ui="{ body: 'p-3 sm:p-4' }">
        <div class="grid min-w-0 gap-2 sm:grid-cols-2">
          <div class="flex flex-col gap-1.5">
            <USkeleton class="h-3 w-14" />
            <USkeleton class="h-9 w-full rounded-md" />
          </div>
          <div class="flex flex-col gap-1.5">
            <USkeleton class="h-3 w-20" />
            <USkeleton class="h-9 w-full rounded-md" />
          </div>
        </div>
      </UCard>

      <div class="flex gap-4 border-b border-default pb-2">
        <USkeleton v-for="index in 4" :key="index" class="h-4 w-20" />
      </div>

      <UCard variant="subtle" :ui="{ body: 'p-4' }">
        <div class="flex flex-col gap-3">
          <div class="flex flex-col gap-1.5">
            <USkeleton class="h-3 w-16" />
            <USkeleton class="h-9 w-full rounded-md" />
          </div>
          <div class="flex flex-col gap-1.5">
            <USkeleton class="h-3 w-24" />
            <USkeleton class="h-9 w-full rounded-md" />
          </div>
          <div class="flex items-center gap-3">
            <USkeleton class="h-5 w-10 rounded-full" />
            <USkeleton class="h-3 w-20" />
          </div>
          <div class="flex items-center gap-3">
            <USkeleton class="h-5 w-10 rounded-full" />
            <USkeleton class="h-3 w-28" />
          </div>
        </div>
      </UCard>
    </div>

    <template v-else>
      <div v-if="!canManageWork" class="flex">
        <UAlert
          color="warning"
          variant="subtle"
          icon="i-lucide-eye"
          title="Modo somente leitura"
          description="Sua função pode ver os modelos, mas só gestores criam ou editam."
        />
      </div>

      <UCard variant="subtle" :ui="{ body: 'p-3 sm:p-4' }">
        <div class="grid min-w-0 gap-2 sm:grid-cols-2">
          <UFormField label="Título" name="name" required>
            <UInput
              v-model="form.name"
              placeholder="Ex.: PGDAS"
              class="w-full"
              :disabled="!canManageWork"
            />
          </UFormField>
          <UFormField label="Descrição" name="description">
            <UInput
              v-model="form.description"
              placeholder="Opcional"
              class="w-full"
              :disabled="!canManageWork"
            />
          </UFormField>
        </div>
      </UCard>

      <UTabs
        v-model="tab"
        :items="tabs"
        variant="link"
        :content="false"
        class="w-full"
      />

      <div v-show="tab === 'assoc'">
        <UCard variant="subtle" :ui="{ body: 'p-4' }">
          <div class="flex min-w-0 flex-col gap-3">
            <UFormField label="Regimes" name="regimes" help="Vazio = todos os regimes.">
              <USelectMenu
                v-model="form.regimes"
                :items="regimeOptions"
                value-key="value"
                label-key="label"
                multiple
                placeholder="Todos os regimes"
                class="w-full"
                :disabled="!canManageWork"
              />
            </UFormField>
            <UFormField label="Tags (categorias)" name="tag_ids" help="Cliente precisa ter ao menos uma das tags.">
              <USelectMenu
                v-model="form.tag_ids"
                :items="(tagCatalog ?? []).map(tag => ({ label: tag.name, value: tag.id }))"
                value-key="value"
                label-key="label"
                multiple
                placeholder="Nenhuma tag"
                class="w-full"
                :disabled="!canManageWork"
              />
            </UFormField>
            <UFormField label="Cascata" name="cascade" help="Etapas só avançam após concluir as anteriores.">
              <USwitch v-model="form.cascade" :disabled="!canManageWork" />
            </UFormField>
            <UFormField label="Modelo ativo" name="is_active">
              <USwitch v-model="form.is_active" :disabled="!canManageWork" />
            </UFormField>
          </div>
        </UCard>
      </div>

      <div v-show="tab === 'clients'">
        <UCard variant="subtle" :ui="{ body: 'p-4' }">
          <div class="flex min-w-0 flex-col gap-3">
            <div class="flex flex-wrap items-center gap-2">
              <h3 class="min-w-0 flex-1 text-sm font-semibold text-highlighted">
                Clientes elegíveis
              </h3>
              <UBadge color="info" variant="subtle" :label="`${ruleCount} pela regra`" />
              <UBadge color="success" variant="subtle" :label="`${addedCount} incluído(s)`" />
            </div>

            <UAlert
              v-if="isNew"
              color="info"
              variant="subtle"
              icon="i-lucide-info"
              title="Salve o modelo para ver a prévia"
              description="A lista de clientes elegíveis aparece depois da criação."
            />

            <template v-else>
              <div v-if="previewLoading" class="flex flex-col gap-2">
                <USkeleton v-for="index in 3" :key="index" class="h-10 w-full rounded-lg" />
              </div>
              <UAlert
                v-else-if="previewError"
                color="error"
                variant="subtle"
                icon="i-lucide-circle-alert"
                title="Não foi possível carregar a prévia"
                :actions="[{ label: 'Tentar novamente', color: 'error', variant: 'solid', onClick: () => refreshPreview() }]"
              />
              <UEmpty
                v-else-if="previewRows.length === 0"
                icon="i-lucide-users"
                title="Nenhum cliente elegível"
                description="Ajuste regimes, tags ou exceções."
                variant="naked"
              />
              <ul v-else class="divide-y divide-default overflow-hidden rounded-lg ring ring-default">
                <li v-for="row in previewRows" :key="row.client.id" class="flex items-center gap-2 px-3 py-2">
                  <span class="min-w-0 flex-1 truncate text-sm text-highlighted">{{ row.client.name }}</span>
                  <UBadge :color="reasonPresentation(row.reason).color" variant="subtle" :label="reasonPresentation(row.reason).label" />
                </li>
              </ul>
            </template>

            <div v-if="canManageWork" class="grid min-w-0 gap-2 sm:grid-cols-2">
              <UFormField label="Incluir cliente (ID)" name="added">
                <div class="flex gap-1.5">
                  <UInput
                    v-model="addedClientId"
                    type="number"
                    min="1"
                    placeholder="ID"
                    class="w-full"
                  />
                  <UButton
                    label="Incluir"
                    size="sm"
                    color="success"
                    variant="outline"
                    @click="addException('added', Number(addedClientId)); addedClientId = ''"
                  />
                </div>
              </UFormField>
              <UFormField label="Excluir cliente (ID)" name="removed">
                <div class="flex gap-1.5">
                  <UInput
                    v-model="removedClientId"
                    type="number"
                    min="1"
                    placeholder="ID"
                    class="w-full"
                  />
                  <UButton
                    label="Excluir"
                    size="sm"
                    color="neutral"
                    variant="outline"
                    @click="addException('removed', Number(removedClientId)); removedClientId = ''"
                  />
                </div>
              </UFormField>
            </div>

            <ul v-if="form.exceptions.length > 0" class="divide-y divide-default overflow-hidden rounded-lg ring ring-default">
              <li v-for="exception in form.exceptions" :key="exception.client_id" class="flex items-center gap-2 px-3 py-2">
                <span class="min-w-0 flex-1 text-sm text-muted">Cliente {{ exception.client_id }}</span>
                <UBadge :color="exception.kind === 'added' ? 'success' : 'neutral'" variant="subtle" :label="exception.kind === 'added' ? 'Incluído' : 'Excluído'" />
                <UButton
                  v-if="canManageWork"
                  icon="i-lucide-x"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  :aria-label="`Remover exceção do cliente ${exception.client_id}`"
                  @click="removeException(exception.client_id)"
                />
              </li>
            </ul>
          </div>
        </UCard>
      </div>

      <div v-show="tab === 'deadline'">
        <UCard variant="subtle" :ui="{ body: 'p-4' }">
          <div class="grid min-w-0 gap-2 sm:grid-cols-2">
            <UFormField label="Dia de vencimento do processo" name="due_day" help="Limitado ao último dia do mês.">
              <UInput
                v-model.number="form.due_day"
                type="number"
                :min="1"
                :max="31"
                class="w-full"
                :disabled="!canManageWork"
              />
            </UFormField>
          </div>
        </UCard>
      </div>

      <div v-show="tab === 'tasks'">
        <UCard variant="subtle" :ui="{ body: 'p-4' }">
          <div class="flex min-w-0 flex-col gap-3">
            <div class="flex items-center gap-2">
              <h3 class="min-w-0 flex-1 text-sm font-semibold text-highlighted">
                Etapas do modelo ({{ form.steps.length }})
              </h3>
              <UButton
                v-if="canManageWork"
                label="Adicionar etapa"
                icon="i-lucide-plus"
                size="xs"
                color="neutral"
                variant="outline"
                @click="addStep"
              />
            </div>
            <UEmpty
              v-if="form.steps.length === 0"
              icon="i-lucide-list-checks"
              title="Nenhuma etapa"
              description="Adicione ao menos uma etapa para o processo gerado."
              variant="naked"
            />
            <div v-for="step in form.steps" :key="step.key" class="flex min-w-0 flex-col gap-2 rounded-xl bg-default p-3 ring ring-default">
              <div class="flex min-w-0 items-center gap-2">
                <span class="shrink-0 text-xs font-semibold text-muted">Ordem {{ step.order }}</span>
                <div class="min-w-0 flex-1" />
                <UButton
                  v-if="canManageWork"
                  icon="i-lucide-chevron-up"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  aria-label="Mover etapa para cima"
                  :disabled="step.order <= 1"
                  @click="moveStep(step.key, -1)"
                />
                <UButton
                  v-if="canManageWork"
                  icon="i-lucide-chevron-down"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  aria-label="Mover etapa para baixo"
                  :disabled="step.order >= form.steps.length"
                  @click="moveStep(step.key, 1)"
                />
                <UButton
                  v-if="canManageWork"
                  icon="i-lucide-trash-2"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  aria-label="Remover etapa"
                  @click="removeStep(step.key)"
                />
              </div>
              <div class="grid min-w-0 gap-2 sm:grid-cols-2">
                <UFormField label="Título" :name="`step-${step.key}-title`" required>
                  <UInput
                    v-model="step.title"
                    placeholder="Ex.: Apurar"
                    class="w-full"
                    :disabled="!canManageWork"
                  />
                </UFormField>
                <UFormField label="Departamento" :name="`step-${step.key}-dept`">
                  <USelectMenu
                    v-model="step.department_id"
                    :items="departmentItems"
                    value-key="value"
                    label-key="label"
                    placeholder="Sem departamento"
                    clear
                    class="w-full"
                    :disabled="!canManageWork"
                  />
                </UFormField>
                <UFormField label="Dia de vencimento" :name="`step-${step.key}-due`">
                  <UInput
                    v-model.number="step.due_day"
                    type="number"
                    :min="1"
                    :max="31"
                    class="w-full"
                    :disabled="!canManageWork"
                  />
                </UFormField>
                <UFormField label="Prioridade" :name="`step-${step.key}-priority`">
                  <USelect
                    v-model="step.priority"
                    :items="priorityOptions"
                    class="w-full"
                    :disabled="!canManageWork"
                  />
                </UFormField>
                <UFormField label="Responsável padrão" :name="`step-${step.key}-assignee`">
                  <USelectMenu
                    v-model="step.default_assignee_member_id"
                    :items="assigneeItems"
                    value-key="value"
                    label-key="label"
                    placeholder="Sem responsável"
                    class="w-full"
                    :disabled="!canManageWork"
                  />
                </UFormField>
              </div>
            </div>
          </div>
        </UCard>
      </div>

      <div v-show="tab === 'recurrence'">
        <UCard variant="subtle" :ui="{ body: 'p-4' }">
          <div class="flex min-w-0 flex-col gap-3">
            <div class="grid min-w-0 gap-2 sm:grid-cols-2">
              <UFormField label="Dia de geração" name="generate_day" help="Rotina mensal gera um processo por cliente.">
                <UInput
                  v-model.number="form.generate_day"
                  type="number"
                  :min="1"
                  :max="31"
                  class="w-full"
                  :disabled="!canManageWork"
                />
              </UFormField>
              <UFormField label="Mês de referência (gerar)" name="reference_month" help="Formato AAAA-MM.">
                <UInput
                  v-model="referenceMonth"
                  placeholder="2026-03"
                  pattern="\d{4}-(0[1-9]|1[0-2])"
                  class="w-full"
                  :disabled="!canManageWork"
                />
              </UFormField>
            </div>
            <div class="flex flex-wrap gap-2">
              <UButton
                v-if="canManageWork && !isNew"
                label="Gerar mês"
                icon="i-lucide-play"
                color="primary"
                :loading="generating"
                :disabled="!canGenerate || generating"
                @click="onGenerate"
              />
            </div>
            <p v-if="!isNew" class="text-xs text-muted">
              A geração é idempotente: clientes já gerados no mês não duplicam. Mudanças no modelo só valem para os próximos meses.
            </p>
          </div>
        </UCard>
      </div>
    </template>
  </div>
</template>
