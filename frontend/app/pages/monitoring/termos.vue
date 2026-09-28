<script setup lang="ts">
import type { MetaListItem } from '~/components/data-table/MetaList.vue'
import { apiStatus } from '~/composables/useApiError'
import type { SerproAuthorizationTerm } from '~/types/serpro'
import { formatMonitoringDate, serproTermGuidance, serproTermStatePresentation } from '~/utils/monitoringPresentation'
import { pageDetailClass, pageRecordScrollClass } from '~/utils/pageShell'

definePageMeta({ middleware: 'auth' })

const { authorizationTerm } = useSerpro()

/**
 * The term belongs to the office, not to a client: it is built, signed and
 * submitted by the platform with the office's own e-CNPJ, so there is nothing
 * here for a member to upload and nothing to sign.
 *
 * `getCachedData: () => undefined` for the same reason as the overview — a term
 * renewed minutes ago must never be read from the SSR payload.
 */
const { data, status, error, refresh: reload } = await useAsyncData<SerproAuthorizationTerm | null>(
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

const term = computed(() => data.value)
/**
 * The badge below lives inside `v-else-if="term"`, so the no-term case is the
 * alert that follows, never this badge: there is no `ausente` reading to fall
 * back to, and pretending otherwise would put a "Termo não emitido" badge on a
 * path that renders no badge at all.
 */
const presentation = computed(() => serproTermStatePresentation[term.value!.state])

/** Renewal is the office's own act only in these two states; the rest is the platform's. */
const needsAction = computed(() => term.value?.state === 'vencido' || term.value?.state === 'recusado')

/** The three recorded facts of the term, read by the card below them. */
const termFacts = computed<MetaListItem[]>(() => {
  if (!term.value) return []
  return [
    { label: 'Estado', value: presentation.value.label },
    { label: 'Vencimento', value: formatMonitoringDate(term.value.expires_on), mono: true },
    { label: 'Assinatura', value: 'Do escritório' }
  ]
})

/**
 * `ignoreStatus: 404` keeps the same exemption the loader above already applied
 * by answering `null`: the term screen sits in its "ainda não tem termo" state
 * rather than shouting over an endpoint that has not shipped.
 */
const { isLoading, showError, refresh, retry } = useRetryableLoad({
  refresh: reload,
  error,
  loading: computed(() => status.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar o termo',
  refreshErrorTitle: 'Não foi possível atualizar o termo',
  ignoreStatus: 404
})

useMonitoringActions({ refresh, loading: isLoading })
</script>

<template>
  <div :class="pageRecordScrollClass">
    <div :class="pageDetailClass">
      <ErrorRetryAlert
        v-if="showError"
        title="Não foi possível carregar o termo"
        @retry="retry"
      />

      <template v-else-if="isLoading">
        <USkeleton class="h-28 w-full rounded-xl" />
        <USkeleton class="h-40 w-full rounded-xl" />
      </template>

      <template v-else-if="term">
        <UCard :ui="{ body: 'p-3 sm:p-4' }">
          <div class="flex items-start gap-3">
            <span
              aria-hidden="true"
              class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary ring-1 ring-inset ring-primary/20"
            >
              <UIcon name="i-lucide-file-signature" class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
              <h1 class="truncate text-lg font-semibold tracking-tight text-highlighted">
                Termo do escritório
              </h1>
              <p class="text-xs text-muted">
                A plataforma monta, assina e submete uma vez, com o e-CNPJ do próprio escritório.
              </p>
              <div class="mt-2 flex flex-wrap items-center gap-1.5">
                <UBadge
                  :color="presentation.color"
                  :icon="presentation.icon"
                  variant="subtle"
                  size="sm"
                  :label="presentation.label"
                />
              </div>
            </div>
          </div>
        </UCard>

        <UAlert
          v-if="needsAction"
          :color="presentation.color"
          variant="subtle"
          :icon="presentation.icon"
          :title="presentation.label"
          :description="serproTermGuidance[term.state]"
        />

        <UCard :ui="{ body: 'p-3 sm:p-4' }">
          <h2 class="mb-3 text-sm font-semibold text-highlighted">
            Dados do termo
          </h2>
          <DataTableMetaList :items="termFacts" />
          <template v-if="!needsAction">
            <USeparator class="my-3" />
            <p class="text-xs text-muted">
              {{ serproTermGuidance[term.state] }}
            </p>
          </template>
        </UCard>
      </template>

      <UAlert
        v-else
        color="warning"
        variant="subtle"
        icon="i-lucide-file-x"
        title="O escritório ainda não tem termo"
        description="Sem o certificado do escritório não há termo, e sem termo a integração não fala com o provedor em nome dos clientes."
      />
    </div>
  </div>
</template>
