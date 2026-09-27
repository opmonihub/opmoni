<script setup lang="ts">
import type { AccordionItem } from '@nuxt/ui'
import type { WorkProcessDetail, WorkTask } from '~/types/work'
import { statusPresentation } from '~/composables/useWorkPresentation'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const toast = useToast()
const { showProcess } = useWork()

const processId = computed(() => {
  const id = Number(route.params.id)
  if (Number.isNaN(id)) throw createError({ statusCode: 404, message: 'Não encontrado' })
  return id
})

const { data, status, error, refresh } = await useAsyncData<WorkProcessDetail>(
  'work-processo-detalhe',
  () => showProcess(processId.value),
  { watch: [processId] }
)

const process = computed(() => data.value ?? null)
const isLoading = computed(() => status.value === 'pending')

const tasks = computed<WorkTask[]>(() => {
  return [...(data.value?.tasks ?? [])].sort((a, b) => {
    if (!a.due_on && !b.due_on) return a.order - b.order
    if (!a.due_on) return 1
    if (!b.due_on) return -1
    return a.due_on === b.due_on ? a.order - b.order : (a.due_on < b.due_on ? -1 : 1)
  })
})

const progress = computed(() => process.value?.progress ?? { total: 0, done: 0, dismissed: 0, open: 0, ratio: 0 })
const progressPercent = computed(() => Math.round(progress.value.ratio * 100))

const statusIcon: Record<WorkTask['status'], string> = {
  todo: 'i-lucide-circle',
  doing: 'i-lucide-loader',
  done: 'i-lucide-circle-check',
  dismissed: 'i-lucide-circle-minus'
}

function taskStatusPresentation(taskStatus: WorkTask['status']) {
  return {
    ...statusPresentation(taskStatus),
    icon: statusIcon[taskStatus]
  }
}

function isLocked(index: number): boolean {
  for (let i = 0; i < index; i++) {
    const previous = tasks.value[i]
    if (previous && previous.status !== 'done' && previous.status !== 'dismissed') return true
  }
  return false
}

const timelineItems = computed(() => tasks.value.map(task => ({
  title: task.title,
  description: taskStatusPresentation(task.status).label,
  icon: taskStatusPresentation(task.status).icon,
  date: task.due_on ? new Date(`${task.due_on}T00:00:00`).toLocaleDateString('pt-BR') : undefined
})))

// UStepper existe no @nuxt/ui instalado e é usado aqui como visão horizontal
// do fluxo, mantendo o UTimeline como detalhe cronológico abaixo.
const stepperValue = ref<string | number | undefined>(undefined)

const stepperItems = computed(() => tasks.value.map(task => ({
  value: String(task.id),
  title: task.title,
  description: `${taskStatusPresentation(task.status).label}${task.due_on ? ` · ${new Date(`${task.due_on}T00:00:00`).toLocaleDateString('pt-BR')}` : ''}`,
  icon: taskStatusPresentation(task.status).icon
})))

type TaskAccordionItem = AccordionItem & { task: WorkTask, locked: boolean }

const accordionItems = computed<TaskAccordionItem[]>(() => tasks.value.map((task, index) => ({
  label: task.title,
  value: String(task.id),
  disabled: false,
  task,
  locked: isLocked(index)
})))

async function onRefresh() {
  try {
    await refresh()
  } catch {
    toast.add({ title: 'Não foi possível atualizar o processo', color: 'error' })
  }
}

watch(error, (value) => {
  if (value) toast.add({ title: 'Não foi possível carregar o processo', color: 'error' })
})
</script>

<template>
  <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-4 overflow-y-auto p-3 sm:gap-5 sm:p-4 lg:p-5">
    <header class="flex min-w-0 flex-wrap items-center gap-2">
      <UButton
        icon="i-lucide-arrow-left"
        color="neutral"
        variant="ghost"
        aria-label="Voltar para processos"
        to="/work/processos"
      />
      <div class="flex min-w-0 flex-1 items-center gap-2.5">
        <UIcon name="i-lucide-layers" class="size-5 shrink-0 text-primary" />
        <h2 class="truncate text-base font-semibold tracking-tight text-highlighted sm:text-lg">
          {{ process?.name ?? 'Processo' }}
        </h2>
      </div>
      <UButton
        icon="i-lucide-refresh-cw"
        color="neutral"
        variant="ghost"
        aria-label="Atualizar processo"
        :loading="isLoading"
        @click="onRefresh"
      />
    </header>

    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      icon="i-lucide-circle-alert"
      title="Não foi possível carregar o processo"
      description="Verifique sua conexão e tente novamente."
      :actions="[{ label: 'Tentar novamente', color: 'error', variant: 'solid', onClick: () => onRefresh() }]"
    />

    <div
      v-else-if="isLoading && !process"
      class="flex flex-col gap-3"
      aria-busy="true"
      aria-label="Carregando processo"
    >
      <UCard variant="subtle" :ui="{ body: 'p-4' }">
        <div class="flex flex-col gap-3">
          <div class="flex items-center gap-2">
            <USkeleton class="h-4 w-48" />
            <USkeleton class="ms-auto h-5 w-20 rounded-full" />
          </div>
          <div class="flex items-center gap-3">
            <USkeleton class="h-2 flex-1 rounded-full" />
            <USkeleton class="h-3 w-16" />
          </div>
        </div>
      </UCard>

      <div class="grid min-w-0 gap-3 lg:grid-cols-2">
        <UCard variant="subtle" :ui="{ body: 'p-4' }">
          <div class="mb-4 flex items-center gap-2">
            <USkeleton class="size-4 rounded" />
            <USkeleton class="h-4 w-16" />
          </div>
          <div class="flex flex-col gap-3">
            <USkeleton v-for="index in 4" :key="index" class="h-10 w-full rounded-lg" />
          </div>
        </UCard>
        <UCard variant="subtle" :ui="{ body: 'p-4' }">
          <div class="mb-4 flex items-center gap-2">
            <USkeleton class="size-4 rounded" />
            <USkeleton class="h-4 w-16" />
          </div>
          <div class="flex flex-col gap-2">
            <USkeleton v-for="index in 5" :key="index" class="h-12 w-full rounded-lg" />
          </div>
        </UCard>
      </div>
    </div>

    <template v-else-if="process">
      <UCard variant="subtle" :ui="{ body: 'p-4' }">
        <div class="flex min-w-0 flex-col gap-3">
          <div class="flex min-w-0 flex-wrap items-center gap-2">
            <p v-if="process.client?.name" class="min-w-0 flex-1 truncate text-sm text-muted">
              {{ process.client.name }}{{ process.template?.name ? ` · ${process.template.name}` : '' }}
            </p>
            <UBadge
              v-if="process.reference_month"
              color="neutral"
              variant="subtle"
              :label="process.reference_month"
            />
          </div>
          <div class="flex items-center gap-3">
            <UProgress :model-value="progressPercent" class="flex-1" />
            <span class="shrink-0 text-xs font-medium text-muted">
              {{ progress.done }}/{{ progress.total }} ({{ progressPercent }}%)
            </span>
          </div>
          <p v-if="progress.dismissed > 0" class="text-xs text-muted">
            {{ progress.dismissed }} dispensada(s)
          </p>
        </div>
      </UCard>

      <div class="grid min-w-0 gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <UCard variant="subtle" :ui="{ body: 'p-4' }">
          <template #header>
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-list-ordered" class="size-4 shrink-0 text-muted" />
              <h3 class="text-sm font-semibold text-highlighted">
                Etapas
              </h3>
            </div>
          </template>
          <UStepper
            v-if="stepperItems.length > 0"
            v-model="stepperValue"
            :items="stepperItems"
            :linear="false"
            orientation="horizontal"
            class="w-full overflow-x-auto pb-2"
          />
          <UTimeline v-if="timelineItems.length > 0" :items="timelineItems" class="mt-4" />
          <UEmpty
            v-else
            icon="i-lucide-list-checks"
            title="Nenhuma etapa"
            description="Este processo ainda não tem tarefas."
            variant="naked"
          />
        </UCard>

        <UCard variant="subtle" :ui="{ body: 'p-4' }">
          <template #header>
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-kanban-square" class="size-4 shrink-0 text-muted" />
              <h3 class="text-sm font-semibold text-highlighted">
                Tarefas
              </h3>
            </div>
          </template>
          <UAccordion v-if="accordionItems.length > 0" :items="accordionItems" type="multiple">
            <template #content="{ item }">
              <div class="flex min-w-0 flex-col gap-2 px-1 pb-1">
                <div class="flex flex-wrap items-center gap-2">
                  <UBadge
                    :color="taskStatusPresentation(item.task.status).color"
                    variant="subtle"
                    :label="taskStatusPresentation(item.task.status).label"
                  />
                  <UBadge
                    v-if="item.locked"
                    color="warning"
                    variant="subtle"
                    label="Aguardando etapas anteriores"
                  >
                    <template #leading>
                      <UIcon name="i-lucide-lock" class="size-3" />
                    </template>
                  </UBadge>
                </div>
                <UTooltip
                  v-if="item.locked"
                  text="Etapas anteriores pendentes — o avanço pode ser bloqueado se a cascata do modelo estiver ativa"
                >
                  <p class="flex items-center gap-1.5 text-xs text-muted">
                    <UIcon name="i-lucide-lock" class="size-3.5 shrink-0" />
                    Aguardando conclusão das etapas anteriores.
                  </p>
                </UTooltip>
                <p v-if="item.task.description" class="text-sm text-muted">
                  {{ item.task.description }}
                </p>
                <p class="text-xs text-muted">
                  Vencimento: {{ item.task.due_on ? new Date(`${item.task.due_on}T00:00:00`).toLocaleDateString('pt-BR') : 'sem prazo' }}
                  · Ordem {{ item.task.order }}
                </p>
              </div>
            </template>
          </UAccordion>
          <UEmpty
            v-else
            icon="i-lucide-list-checks"
            title="Nenhuma tarefa"
            description="Este processo ainda não tem tarefas."
            variant="naked"
          />
        </UCard>
      </div>
    </template>
  </div>
</template>
