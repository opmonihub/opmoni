<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import type { ClientSheet } from '~/types/client'
import type { MonitoringObligation } from '~/utils/monitoringNav'
import { clientSheetTaxIdLabel } from '~/utils/portfolioLabels'

const props = defineProps<{
  open: boolean
  obligation: MonitoringObligation
  associatedIds: number[]
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'associated': []
}>()

const isOpen = computed({
  get: () => props.open,
  set: value => emit('update:open', value)
})

const toast = useToast()
const { list } = useClients()
const { associateClients } = useSerpro()

const CANDIDATE_CAP = 100

const search = ref('')
const debouncedSearch = refDebounced(search, 350)
const candidates = ref<ClientSheet[]>([])
const selected = ref<number[]>([])
const loading = ref(false)
const saving = ref(false)
const capped = ref(false)
async function loadCandidates() {
  loading.value = true
  try {
    // `sheet: 1` asks the whole match set instead of a page of it: the cap below
    // and the "Selecionar todos" count both mean "every client matching the
    // search", and a paged list would quietly turn both into "the first 25".
    const response = await list({ sheet: 1, q: debouncedSearch.value.trim(), sort: 'name', direction: 'asc' })
    const companies = (response.data ?? []).filter(client => client.person_type === 'company')
    const already = new Set(props.associatedIds)
    const fresh = companies.filter(client => !already.has(client.id))
    // Capped, never paginated. A load-more inside a modal is awkward on the
    // pointer and worse on a phone; the honest alternative to a bounded list
    // is a full one, and the office narrows the search instead.
    capped.value = fresh.length > CANDIDATE_CAP
    candidates.value = fresh.slice(0, CANDIDATE_CAP)
    selected.value = selected.value.filter(id => candidates.value.some(client => client.id === id))
  } catch {
    toast.add({ title: 'Não foi possível carregar os clientes', color: 'error' })
  } finally {
    loading.value = false
  }
}

watch([isOpen, debouncedSearch], ([open]) => {
  if (!open) return
  selected.value = []
  void loadCandidates()
})

/** "Selecionar todos" means every candidate matching the search, not the page. */
const allSelected = computed(() => candidates.value.length > 0 && selected.value.length === candidates.value.length)

function toggleAll() {
  selected.value = allSelected.value ? [] : candidates.value.map(client => client.id)
}

function toggleOne(id: number) {
  selected.value = selected.value.includes(id)
    ? selected.value.filter(item => item !== id)
    : [...selected.value, id]
}

const addingId = ref<number | null>(null)

async function associate(ids: number[]) {
  if (!ids.length) return
  saving.value = true
  addingId.value = ids.length === 1 ? ids[0]! : null
  try {
    const result = await associateClients(props.obligation.slug, ids)
    // Reporting the whole batch as added would be a lie whenever `already` > 0.
    if (result.already > 0) {
      toast.add({
        title: `${result.associated} clientes associados, ${result.already} já estavam`,
        color: 'warning'
      })
    } else {
      toast.add({ title: `${result.associated} clientes associados`, color: 'success' })
    }
    selected.value = selected.value.filter(id => !ids.includes(id))
    emit('associated')
  } catch {
    toast.add({ title: 'Não foi possível associar os clientes', color: 'error' })
  } finally {
    saving.value = false
    addingId.value = null
  }
}
</script>

<template>
  <UModal
    v-model:open="isOpen"
    :title="`Adicionar clientes a ${obligation.label}`"
    :description="`Acompanhe ${obligation.label} destes clientes. Nenhuma sincronização é disparada: a execução é uma ação à parte.`"
  >
    <template #body>
      <div class="flex flex-col gap-3">
        <UInput
          v-model="search"
          icon="i-lucide-search"
          placeholder="Buscar por nome ou CNPJ"
          aria-label="Buscar clientes para associar"
        />

        <UAlert
          v-if="capped"
          color="warning"
          variant="subtle"
          icon="i-lucide-info"
          title="A lista foi limitada a 100 clientes"
          description="Refine a busca para ver os demais."
        />

        <USkeleton v-if="loading" class="h-40 w-full" />

        <UEmpty
          v-else-if="candidates.length === 0"
          icon="i-lucide-user-round-search"
          title="Nenhum cliente para associar"
          description="A integração acompanha apenas clientes pessoa jurídica que ainda não estão nesta obrigação."
          variant="naked"
        />

        <div v-else class="flex max-h-80 flex-col gap-1 overflow-y-auto">
          <div
            v-for="client in candidates"
            :key="client.id"
            class="flex items-center justify-between gap-3 rounded-lg px-2 py-1.5 hover:bg-elevated/50"
          >
            <UCheckbox
              :model-value="selected.includes(client.id)"
              :label="client.name"
              :description="clientSheetTaxIdLabel(client)"
              class="min-w-0 flex-1"
              @update:model-value="toggleOne(client.id)"
            />
            <UButton
              icon="i-lucide-plus"
              color="success"
              variant="ghost"
              size="sm"
              :loading="addingId === client.id"
              :aria-label="`Adicionar ${client.name}`"
              @click="associate([client.id])"
            />
          </div>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full items-center justify-between gap-3">
        <UButton
          :label="allSelected ? 'Desmarcar todos' : 'Selecionar todos'"
          color="neutral"
          variant="ghost"
          :disabled="candidates.length === 0"
          @click="toggleAll"
        />
        <UButton
          :label="`Adicionar ${selected.length} selecionados`"
          icon="i-lucide-user-plus"
          :disabled="selected.length === 0"
          :loading="saving"
          @click="associate(selected)"
        />
      </div>
    </template>
  </UModal>
</template>
