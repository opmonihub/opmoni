<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { apiMessage, apiStatus } from '~/composables/useApiError'
import type { SerproConnectionMetadata, SerproConnectivityResult } from '~/types/serpro'
import { formatMonitoringDate } from '~/utils/monitoringPresentation'

/**
 * No `definePageMeta` and no role check on this page. `app/pages/admin.vue`
 * already declares `middleware: ['auth', 'super-admin']` for everything under
 * `/admin`, so a second copy of the rule would be a second thing to keep in
 * sync. The enablement control of each office is not here either: that belongs
 * to the Account admin, not to the platform credential.
 */

const toast = useToast()
const { connection, saveConnection, testConnectivity } = useSerpro()

const schema = z.object({
  consumer_key: z.string().min(1, 'Informe a chave de integração'),
  consumer_secret: z.string().optional(),
  password: z.string().optional()
})

type Schema = z.output<typeof schema>

const state = reactive<Partial<Schema>>({ consumer_key: '' })
const certificate = ref<File | null>(null)

const submitting = ref(false)
const testing = ref(false)
/** True only while the operator is deliberately changing a stored credential. */
const editing = ref(false)
const connectivity = ref<SerproConnectivityResult | null>(null)

/**
 * The first rung of the ladder, 404-exempt like the other monitoring screens:
 * the read endpoint not having shipped yet is the inert state — an empty form
 * and no invented credential — not a failure worth shouting about.
 */
const { data: metadata, status, error, refresh } = await useAsyncData<SerproConnectionMetadata | null>(
  'serpro-connection',
  async () => {
    try {
      return await connection()
    } catch (e) {
      if (apiStatus(e) === 404) return null
      throw e
    }
  },
  { default: () => null, getCachedData: () => undefined }
)

const isLoading = computed(() => status.value === 'pending')
const showError = computed(() => !!error.value && apiStatus(error.value) !== 404)

/** The metadata only ever renders for a credential the API says is configured. */
const configuredCredential = computed(() => (metadata.value?.configured ? metadata.value : null))
const isConfigured = computed(() => !!configuredCredential.value)
/** No stored secret to show, so the form is the honest thing to draw. */
const showForm = computed(() => !isConfigured.value || editing.value)

/**
 * The notice is worded by what the read actually told us. A 404 told us
 * nothing, so it gets the instruction; only a `configured: false` from the API
 * licenses saying that nothing is stored.
 */
const unconfiguredNotice = computed(() => (metadata.value
  ? {
      title: 'A credencial da plataforma ainda não foi cadastrada',
      description: 'Sem chave, segredo e certificado contratante a integração não fala com o provedor em nome de nenhuma conta.'
    }
  : {
      title: 'Cadastre a credencial da plataforma',
      description: 'Informe a chave, o segredo e o certificado contratante. O que for enviado em branco preserva o que já estiver gravado.'
    }))

/** One bound or the other is a partial window; saying "—" for both is a lie. */
const validityWindow = computed(() => {
  const notBefore = configuredCredential.value?.certificate_not_before
  const notAfter = configuredCredential.value?.certificate_not_after
  if (!notBefore && !notAfter) return '—'
  if (!notAfter) return `Desde ${formatMonitoringDate(notBefore)}`
  if (!notBefore) return `Até ${formatMonitoringDate(notAfter)}`
  return `${formatMonitoringDate(notBefore)} a ${formatMonitoringDate(notAfter)}`
})

/**
 * What the provider rejected, in the operator's words. An element this client
 * does not know falls back to the code itself: a fault nobody can read is worse
 * than one that is merely untranslated.
 */
const failedElementLabels: Record<string, string> = {
  configuracao: 'Configuração',
  certificado: 'Certificado',
  credencial: 'Credencial',
  provedor: 'Provedor'
}

const failedElementName = computed(() => {
  const element = connectivity.value?.failed_element
  if (!element) return null
  return failedElementLabels[element] ?? element
})

/** A gateway outage is recoverable and is not an invalid credential. */
const isProviderFailure = computed(() => connectivity.value?.failed_element === 'provedor')

const connectivityTone = computed(() => {
  if (connectivity.value?.ok) return 'success' as const
  return isProviderFailure.value ? 'warning' as const : 'error' as const
})

const connectivityIcon = computed(() => {
  if (connectivity.value?.ok) return 'i-lucide-circle-check'
  return isProviderFailure.value ? 'i-lucide-cloud-off' : 'i-lucide-circle-alert'
})

const connectivityTitle = computed(() => {
  if (connectivity.value?.ok) return 'Conexão autenticada com sucesso'
  if (isProviderFailure.value) return 'O provedor não respondeu'
  return 'Não foi possível autenticar'
})

const connectivityDescription = computed(() => {
  const result = connectivity.value
  if (!result) return ''
  const parts: string[] = []
  if (!result.ok && failedElementName.value) parts.push(`Elemento: ${failedElementName.value}.`)
  if (result.message) parts.push(result.message)
  parts.push(`Verificado em ${new Date(result.checked_at).toLocaleString('pt-BR')}.`)
  return parts.join(' ')
})

/**
 * The form is write-only: it is never hydrated from the API — there is no stored
 * secret to hydrate with — and it is emptied the moment a save lands, so a
 * re-opened form cannot look like it still holds one.
 */
function resetForm() {
  state.consumer_key = ''
  state.consumer_secret = ''
  state.password = ''
  certificate.value = null
}

function startEditing() {
  editing.value = true
}

function cancelEditing() {
  editing.value = false
  resetForm()
}

async function onSubmit(event: FormSubmitEvent<Schema>) {
  submitting.value = true
  try {
    // The secret is sent only when typed. `saveConnection` drops the field
    // entirely when it is blank, which is what preserves the stored one.
    const saved = await saveConnection({
      consumer_key: event.data.consumer_key,
      consumer_secret: event.data.consumer_secret || undefined,
      password: event.data.password || undefined,
      certificate: certificate.value ?? undefined
    })
    metadata.value = saved
    resetForm()
    editing.value = false
    // The old verdict says nothing about the credential just stored.
    connectivity.value = null
    toast.add({ title: 'Conexão com o Integra Contador salva', color: 'success' })
  } catch (err) {
    toast.add({ title: 'Não foi possível salvar a conexão', description: apiMessage(err), color: 'error' })
  } finally {
    submitting.value = false
  }
}

async function onTestConnectivity() {
  testing.value = true
  try {
    connectivity.value = await testConnectivity()
  } catch (err) {
    toast.add({ title: 'Não foi possível testar a conexão', description: apiMessage(err), color: 'error' })
  } finally {
    testing.value = false
  }
}

async function onRefresh() {
  try {
    await refresh()
  } catch {
    toast.add({ title: 'Não foi possível atualizar a conexão', color: 'error' })
  }
}

watch(error, (value) => {
  if (value && apiStatus(value) !== 404) {
    toast.add({ title: 'Não foi possível carregar a conexão', color: 'error' })
  }
})
</script>

<template>
  <div class="flex flex-col gap-6">
    <div>
      <h2 class="text-lg font-semibold text-highlighted">
        Conexão com o Integra Contador
      </h2>
      <p class="text-sm text-muted">
        A credencial é única, pertence à plataforma e vale para todas as contas. O segredo é somente escrita: a API nunca o devolve e esta tela não o exibe.
      </p>
    </div>

    <UAlert
      v-if="showError"
      color="error"
      variant="subtle"
      icon="i-lucide-circle-alert"
      title="Não foi possível carregar a conexão"
      description="Verifique sua conexão e tente novamente."
      :actions="[{ label: 'Tentar novamente', color: 'error', variant: 'solid', onClick: () => onRefresh() }]"
    />

    <UPageSkeleton v-else-if="isLoading" :rows="4" />

    <template v-else>
      <UCard
        v-if="configuredCredential && !editing"
        :ui="{ body: 'p-4 sm:p-5 flex flex-col gap-4' }"
      >
        <div>
          <p class="text-sm text-muted">
            Credencial
          </p>
          <UBadge
            color="success"
            icon="i-lucide-circle-check"
            variant="subtle"
            label="Configurada"
            size="lg"
          />
        </div>

        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <dt class="text-sm text-muted">
              Titular do certificado
            </dt>
            <dd class="text-sm font-medium text-default">
              {{ configuredCredential.certificate_subject ?? '—' }}
            </dd>
          </div>
          <div>
            <dt class="text-sm text-muted">
              Número de série
            </dt>
            <dd class="text-sm font-medium text-default tabular-nums">
              {{ configuredCredential.certificate_serial ?? '—' }}
            </dd>
          </div>
          <div>
            <dt class="text-sm text-muted">
              Validade do certificado
            </dt>
            <dd class="text-sm font-medium text-default tabular-nums">
              {{ validityWindow }}
            </dd>
          </div>
          <div>
            <dt class="text-sm text-muted">
              Documento contratante
            </dt>
            <dd class="text-sm font-medium text-default">
              {{ configuredCredential.contracting_document ?? '—' }}
            </dd>
          </div>
        </dl>

        <p class="text-sm text-muted">
          O segredo, a senha e o arquivo do certificado não são devolvidos pela API e não aparecem aqui.
        </p>

        <div class="flex justify-end">
          <UButton
            label="Atualizar credencial"
            icon="i-lucide-pencil"
            color="neutral"
            variant="outline"
            type="button"
            @click="startEditing"
          />
        </div>
      </UCard>

      <UAlert
        v-else-if="!editing"
        color="neutral"
        variant="subtle"
        icon="i-lucide-plug"
        :title="unconfiguredNotice.title"
        :description="unconfiguredNotice.description"
      />

      <UPageCard
        v-if="showForm"
        :title="isConfigured ? 'Atualizar credencial' : 'Credencial da plataforma'"
        :description="isConfigured
          ? 'Segredo e senha em branco continuam como estão guardados; a chave é obrigatória.'
          : 'Chave, segredo e certificado do provedor.'"
        variant="subtle"
      >
        <UForm
          id="serpro-connection"
          :schema="schema"
          :state="state"
          class="flex flex-col gap-4"
          @submit="onSubmit"
        >
          <UFormField
            label="Chave de integração"
            name="consumer_key"
            help="Consumer key fornecida pelo Integra Contador."
            required
          >
            <UInput
              v-model="state.consumer_key"
              placeholder="Chave de integração"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Segredo do consumidor"
            name="consumer_secret"
            help="Somente escrita: a API nunca devolve o segredo guardado, e um campo em branco preserva o que já está gravado."
          >
            <UInput
              v-model="state.consumer_secret"
              type="password"
              autocomplete="off"
              placeholder="Deixe em branco para preservar"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Certificado contratante"
            name="certificate"
            help="Certificado do escritório contratante (.pfx ou .p12)."
          >
            <UFileUpload
              v-model="certificate"
              accept=".pfx,.p12"
              label="Selecionar arquivo"
              description="Arraste o arquivo ou clique para selecionar"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Senha do certificado"
            name="password"
            help="Senha do arquivo, quando o certificado é protegido. Em branco, a senha guardada é preservada."
          >
            <UInput
              v-model="state.password"
              type="password"
              autocomplete="off"
              placeholder="Deixe em branco para preservar"
              class="w-full"
            />
          </UFormField>
        </UForm>

        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton
              v-if="isConfigured"
              label="Cancelar"
              color="neutral"
              variant="subtle"
              type="button"
              @click="cancelEditing"
            />
            <UButton
              label="Salvar conexão"
              type="submit"
              form="serpro-connection"
              :loading="submitting"
            />
          </div>
        </template>
      </UPageCard>

      <UPageCard
        title="Conectividade"
        description="Testa a autenticação no provedor sem sincronizar nada."
        variant="subtle"
      >
        <div class="flex flex-col gap-4">
          <UButton
            label="Testar conectividade"
            icon="i-lucide-plug"
            color="neutral"
            variant="outline"
            type="button"
            :loading="testing"
            @click="onTestConnectivity"
          />

          <UAlert
            v-if="connectivity"
            :color="connectivityTone"
            variant="subtle"
            :icon="connectivityIcon"
            :title="connectivityTitle"
            :description="connectivityDescription"
          />
        </div>
      </UPageCard>
    </template>
  </div>
</template>
