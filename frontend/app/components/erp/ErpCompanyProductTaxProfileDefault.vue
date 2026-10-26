<script setup lang="ts">
import { apiFailure } from '#shared/utils/access'

const settings = useErpCompanySettings()
const selectedProfileId = ref<number | null>(null)
const selectedProfileValid = ref(false)
const loadError = ref('')
const saveError = ref('')
const profileError = ref('')
const savePending = ref(false)
const reviewPending = ref(false)
const reviewConflict = ref(false)
const reviewNotice = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

const currentProfileId = computed(() => settings.state.value?.default_product_tax_profile_id ?? null)
const hasSelectionChanges = computed(() => selectedProfileId.value !== currentProfileId.value)
const canSave = computed(() => settings.canWrite.value
  && !settings.pending.value
  && !savePending.value
  && !reviewPending.value
  && !reviewConflict.value
  && !!settings.state.value
  && (!hasSelectionChanges.value || selectedProfileId.value === null || selectedProfileValid.value))

let hydratedAuthority: string | null = null
let draftInitialized = false
let baselineProfileId: number | null = null

function clearFeedback() {
  loadError.value = ''
  saveError.value = ''
  profileError.value = ''
  reviewConflict.value = false
  reviewNotice.value = ''
  fieldErrors.value = {}
}

watch([settings.authorityKey, settings.canRead, settings.canWrite], ([authority, canRead, canWrite]) => {
  hydratedAuthority = authority
  draftInitialized = false
  baselineProfileId = null
  selectedProfileId.value = null
  selectedProfileValid.value = false
  savePending.value = false
  reviewPending.value = false
  clearFeedback()
  if ((canRead || canWrite) && settings.state.value) {
    selectedProfileId.value = currentProfileId.value
    baselineProfileId = currentProfileId.value
    draftInitialized = true
  }
}, { flush: 'sync', immediate: true })

watch(settings.state, (state) => {
  if (!state || !settings.canRead.value || hydratedAuthority !== settings.authorityKey.value)
    return
  const current = currentProfileId.value
  if (!draftInitialized || selectedProfileId.value === baselineProfileId || selectedProfileId.value === current) {
    selectedProfileId.value = current
  } else if (current !== baselineProfileId) {
    reviewConflict.value = true
    reviewNotice.value = 'O padrão tributário foi atualizado. Sua escolha em rascunho foi mantida.'
  }
  baselineProfileId = current
  loadError.value = ''
  draftInitialized = true
}, { flush: 'sync', immediate: true })

watch(selectedProfileId, (id, previous) => {
  if (id !== previous) {
    selectedProfileValid.value = id === null || id === currentProfileId.value
    profileError.value = ''
    fieldErrors.value = {}
    saveError.value = ''
    reviewNotice.value = ''
  }
}, { flush: 'sync' })

onMounted(async () => {
  if (!settings.state.value && settings.canRead.value && !settings.pending.value) {
    const authority = settings.authorityKey.value
    try {
      await settings.refresh()
    } catch {
      if (authority === settings.authorityKey.value && settings.canRead.value)
        loadError.value = 'Não foi possível carregar o perfil padrão da empresa.'
    }
  }
})

function updateSelectionValidity(event: { id: number | null, valid: boolean }) {
  if (event.id === selectedProfileId.value)
    selectedProfileValid.value = event.valid
}

async function saveDefault() {
  const current = settings.state.value
  if (!current || !canSave.value || !hasSelectionChanges.value)
    return

  const authority = settings.authorityKey.value
  const submitted = selectedProfileId.value
  saveError.value = ''
  reviewNotice.value = ''
  fieldErrors.value = {}
  savePending.value = true
  try {
    const response = await settings.save({
      default_product_tax_profile_id: submitted,
      expected_tax_default_revision: current.tax_default_revision ?? 0
    })
    if (!response || authority !== settings.authorityKey.value || !settings.canWrite.value)
      return
    if (selectedProfileId.value === submitted)
      selectedProfileId.value = currentProfileId.value
    baselineProfileId = currentProfileId.value
    reviewConflict.value = false
    reviewNotice.value = ''
  } catch (failure) {
    if (authority !== settings.authorityKey.value || !settings.canWrite.value)
      return
    const details = apiFailure(failure)
    saveError.value = details.message
    fieldErrors.value = details.errors ?? {}
    reviewConflict.value = details.status === 409 && details.code === 'product_tax_default_changed'
  } finally {
    if (authority === settings.authorityKey.value)
      savePending.value = false
  }
}

async function reviewLatestDefault() {
  if (!reviewConflict.value || !settings.canRead.value || settings.pending.value || reviewPending.value)
    return
  const authority = settings.authorityKey.value
  reviewPending.value = true
  loadError.value = ''
  try {
    const response = await settings.refresh()
    if (!response || authority !== settings.authorityKey.value || !settings.canRead.value)
      return
    reviewConflict.value = false
    saveError.value = ''
    fieldErrors.value = {}
    reviewNotice.value = hasSelectionChanges.value
      ? 'Configuração atualizada. Confira a escolha mantida e salve novamente para aplicá-la.'
      : 'Configuração atualizada. A escolha mantida já corresponde ao padrão da empresa.'
  } catch {
    if (authority === settings.authorityKey.value)
      loadError.value = 'Não foi possível atualizar a configuração tributária.'
  } finally {
    if (authority === settings.authorityKey.value)
      reviewPending.value = false
  }
}

async function refreshSettings() {
  if (!settings.canRead.value || settings.pending.value)
    return
  loadError.value = ''
  const authority = settings.authorityKey.value
  try {
    await settings.refresh()
  } catch {
    if (authority === settings.authorityKey.value && settings.canRead.value)
      loadError.value = 'Não foi possível carregar o perfil padrão da empresa.'
  }
}
</script>

<template>
  <section class="mb-6 space-y-4">
    <UPageCard
      title="Perfil tributário padrão"
      description="Escolha o perfil sugerido para novos produtos desta empresa. A escolha não altera produtos já cadastrados."
      variant="subtle"
    >
      <div v-if="!settings.canRead.value" class="space-y-2 text-sm text-muted">
        <p>Seu acesso não permite consultar o perfil tributário padrão da empresa.</p>
      </div>
      <div v-else-if="!settings.state.value" class="space-y-3">
        <p class="text-sm text-muted" role="status">
          {{ loadError || (settings.pending.value ? 'Carregando perfil padrão…' : 'As configurações ainda não foram confirmadas.') }}
        </p>
        <UButton
          v-if="!settings.pending.value"
          label="Atualizar configurações"
          color="neutral"
          variant="outline"
          @click="refreshSettings"
        />
      </div>
      <div v-else class="space-y-4">
        <p v-if="!settings.canWrite.value" class="text-sm text-muted">
          Seu acesso permite apenas consultar esta configuração.
        </p>
        <ErpCatalogReferenceSelect
          v-model="selectedProfileId"
          path="/erp/tax-settings/product-tax-profiles"
          label="Perfil sugerido para novos produtos"
          label-key="name"
          name="default_product_tax_profile_id"
          :error="fieldErrors.default_product_tax_profile_id?.[0] || profileError"
          :disabled="!settings.canWrite.value || settings.pending.value || savePending"
          optional
          @selection-validity="updateSelectionValidity"
        />
        <p v-if="hasSelectionChanges" role="status" class="text-sm text-muted">
          Esta escolha está em rascunho e só será aplicada depois de salvar explicitamente.
        </p>
        <p class="text-xs text-muted">
          O produto ainda exige a confirmação do perfil ou a escolha de uma configuração diferente antes de salvar.
        </p>
        <p v-if="saveError" role="alert" class="text-sm text-error">
          {{ saveError }}
        </p>
        <p v-if="loadError" role="alert" class="text-sm text-error">
          {{ loadError }}
        </p>
        <p v-if="reviewNotice" role="status" class="text-sm text-muted">
          {{ reviewNotice }}
        </p>
        <div v-if="reviewConflict" class="flex flex-wrap items-center justify-between gap-3">
          <p class="text-sm text-warning">
            Revise a configuração atual antes de tentar salvar novamente.
          </p>
          <UButton
            label="Recarregar e revisar"
            color="neutral"
            variant="outline"
            :loading="reviewPending || settings.pending.value"
            :disabled="reviewPending || settings.pending.value || !settings.canRead.value"
            @click="reviewLatestDefault"
          />
        </div>
        <div class="flex justify-end">
          <UButton
            label="Salvar perfil padrão"
            :disabled="!canSave || !hasSelectionChanges"
            :loading="savePending"
            @click="saveDefault"
          />
        </div>
      </div>
    </UPageCard>
  </section>
</template>
