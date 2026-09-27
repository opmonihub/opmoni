<script setup lang="ts">
import { apiStatus } from '~/composables/useApiError'
import type { SerproAuthorizationTerm } from '~/types/serpro'
import { formatMonitoringDate, serproTermStatePresentation } from '~/utils/monitoringPresentation'

definePageMeta({ middleware: 'auth' })

const toast = useToast()
const { authorizationTerm } = useSerpro()

/**
 * The term belongs to the office, not to a client: it is built, signed and
 * submitted by the platform with the office's own e-CNPJ, so there is nothing
 * here for a member to upload and nothing to sign.
 *
 * `getCachedData: () => undefined` for the same reason as the overview — a term
 * renewed minutes ago must never be read from the SSR payload.
 */
const { data, status, error, refresh } = await useAsyncData<SerproAuthorizationTerm | null>(
  'serpro-authorization-term',
  async () => {
    try {
      return await authorizationTerm()
    } catch (e) {
      // A 404 is the endpoint not having shipped yet, which is the inert state
      // and not a failure to shout about: `tasks.md` 10.3 requires an empty
      // screen in exactly that situation.
      if (apiStatus(e) === 404) return null
      throw e
    }
  },
  { default: () => null, getCachedData: () => undefined }
)

const isLoading = computed(() => status.value === 'pending')
const term = computed(() => data.value)
const presentation = computed(() => serproTermStatePresentation[term.value?.state ?? 'ausente'])

async function onRefresh() {
  try {
    await refresh()
  } catch {
    toast.add({ title: 'Não foi possível atualizar o termo', color: 'error' })
  }
}

watch(error, (value) => {
  if (value && apiStatus(value) !== 404) {
    toast.add({ title: 'Não foi possível carregar o termo', color: 'error' })
  }
})

const showError = computed(() => !!error.value && apiStatus(error.value) !== 404)
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 sm:p-6">
    <div>
      <h2 class="text-lg font-semibold text-highlighted">
        Termo de autorização
      </h2>
      <p class="text-sm text-muted">
        O termo é do escritório, não de cada cliente: a plataforma monta, assina e submete uma vez, com o e-CNPJ do próprio escritório.
      </p>
    </div>

    <UAlert
      v-if="showError"
      color="error"
      variant="subtle"
      icon="i-lucide-circle-alert"
      title="Não foi possível carregar o termo"
      description="Verifique sua conexão e tente novamente."
      :actions="[{ label: 'Tentar novamente', color: 'error', variant: 'solid', onClick: () => onRefresh() }]"
    />

    <USkeleton v-else-if="isLoading" class="h-32 w-full" />

    <UCard v-else-if="term" :ui="{ body: 'p-4 sm:p-5 flex flex-col gap-4' }">
      <div class="flex items-start justify-between gap-3">
        <div>
          <p class="text-sm text-muted">
            Estado
          </p>
          <UBadge
            :color="presentation.color"
            :icon="presentation.icon"
            variant="subtle"
            :label="presentation.label"
            size="lg"
          />
        </div>
        <div class="text-right">
          <p class="text-sm text-muted">
            Vencimento
          </p>
          <p class="text-sm font-medium text-default tabular-nums">
            {{ formatMonitoringDate(term.expires_on) }}
          </p>
        </div>
      </div>

      <p class="text-sm text-muted">
        A assinatura é do escritório. A renovação é feita pela plataforma, sem nenhuma ação sua.
      </p>
    </UCard>

    <UAlert
      v-else
      color="warning"
      variant="subtle"
      icon="i-lucide-file-x"
      title="O escritório ainda não tem termo"
      description="Sem o certificado do escritório não há termo, e sem termo a integração não fala com o provedor em nome dos clientes."
    />
  </div>
</template>
