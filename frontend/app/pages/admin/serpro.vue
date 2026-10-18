<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { AccountListResponse, DetailResponse, ExtendedPlatformAccount } from '#shared/types/admin'
import { apiErrorMessage, apiFieldErrors, apiStatus } from '~/composables/useApiError'
import type { SerproConnectionMetadata, SerproConnectionPayload, SerproConnectivityResult, SerproContractingCertificateMetadata } from '~/types/serpro'
import { createLatestRequestRunner, readPaginatedMeta } from '~/utils/adminListFilters'
import { formatMonitoringDate } from '~/utils/monitoringPresentation'
import {
  connectivityIcon as serproConnectivityIcon,
  connectivityTitle as serproConnectivityTitle,
  connectivityTone as serproConnectivityTone,
  failedElementName as serproFailedElementName
} from '~/utils/serproConnectivityPresentation'

// The parent Admin route owns authorization; all reads here are safe metadata only.
const toast = useToast()
const { $api } = useNuxtApp()
const { connection, saveConnection, testConnectivity, contractingCertificate, uploadContractingCertificate } = useSerpro()
const metadata = ref<SerproConnectionMetadata | null>(null)
const loading = ref(true)
const loadError = ref<unknown>(null)
const editing = ref(false)
const submitting = ref(false)
const testing = ref(false)
const uploadingSource = ref(false)
const connectivity = ref<SerproConnectivityResult | null>(null)
const connectivityError = ref('')
const operationError = ref('')
const fieldErrors = ref<Record<string, string>>({})
const sourceError = ref('')
const sourceFieldErrors = ref<Record<string, string>>({})
const certificateSource = ref<'preserve' | 'upload' | 'account'>('upload')
const certificate = ref<File | null>(null)
const sourceCertificate = ref<File | null>(null)
const sourcePassword = ref('')
const selectedAccount = ref<ExtendedPlatformAccount | null>(null)
const sourceMetadata = ref<SerproContractingCertificateMetadata | null>(null)
const sourceLoading = ref(false)
const sourceAccountId = ref<number | null>(null)
const accountQuery = ref('')
const appliedQuery = ref('')
const accountPage = ref(1)
const accountTotal = ref(0)
const accountPerPage = ref(15)
const accountResults = ref<ExtendedPlatformAccount[]>([])
const catalogLoading = ref(false)
const catalogError = ref('')
const catalogLoaded = ref(false)
const runRead = createLatestRequestRunner()
const runSource = createLatestRequestRunner()
const runCatalog = createLatestRequestRunner()
const runDiagnostic = createLatestRequestRunner()
let active = true
const configuredCredential = computed(() => metadata.value?.configured ? metadata.value : null)
const isConfigured = computed(() => !!configuredCredential.value)
const showForm = computed(() => !!metadata.value && (!isConfigured.value || editing.value))
const busy = computed(() => submitting.value || testing.value || uploadingSource.value)
const sourceUsable = computed(() => {
  const value = sourceMetadata.value
  if (selectedAccount.value?.status !== 'active' || !value?.configured)
    return false
  const now = Date.now()
  return !!value.not_before && !!value.not_after && Date.parse(value.not_before) <= now && Date.parse(value.not_after) > now
})
const formDisabled = computed(() => busy.value || loading.value || sourceLoading.value || catalogLoading.value
  || !metadata.value || (certificateSource.value === 'account' && !sourceUsable.value))
const currentSourceLabel = computed(() => {
  if (metadata.value?.certificate_source === 'account') {
    const id = metadata.value.contracting_account_id
    return metadata.value.legacy_account_certificate
      ? `Certificado legado do escritório #${id}. Mantido até substituição explícita.`
      : `Certificado contratante da plataforma no escritório #${id}.`
  }
  return 'Arquivo dedicado à conexão da plataforma.'
})
const schema = z.object({
  consumer_key: z.string().default('').refine(value => isConfigured.value || value.length > 0, 'Informe a chave de integração'),
  consumer_secret: z.string().optional(),
  password: z.string().optional()
})
type Schema = z.output<typeof schema>
const state = reactive<Partial<Schema>>({ consumer_key: '' })
const validityWindow = computed(() => {
  const from = configuredCredential.value?.certificate_not_before
  const until = configuredCredential.value?.certificate_not_after
  if (!from && !until)
    return '—'
  if (!until)
    return `Desde ${formatMonitoringDate(from)}`
  if (!from)
    return `Até ${formatMonitoringDate(until)}`
  return `${formatMonitoringDate(from)} a ${formatMonitoringDate(until)}`
})
const failedElementName = computed(() => serproFailedElementName(connectivity.value))
const connectivityDescription = computed(() => {
  const result = connectivity.value
  if (!result)
    return ''
  return [!result.ok && failedElementName.value ? `Elemento: ${failedElementName.value}.` : '', result.message,
    `Verificado em ${new Date(result.checked_at).toLocaleString('pt-BR')}.`].filter(Boolean).join(' ')
})
function failureMessage(error: unknown) {
  if (apiStatus(error) === 413)
    return 'Envie um arquivo de até 2 MiB; a requisição deve ter até 4 MiB.'
  return apiErrorMessage(error) ?? 'Tente novamente.'
}
function fileError(file: File | null) {
  if (!file)
    return 'Selecione um arquivo .pfx ou .p12.'
  if (file.size > 2 * 1024 * 1024)
    return 'O arquivo deve ter até 2 MiB.'
  if (!/\.(pfx|p12)$/i.test(file.name))
    return 'Selecione um arquivo .pfx ou .p12.'
  return ''
}
function clearSensitive() {
  state.consumer_secret = ''
  state.password = ''
  certificate.value = null
  sourceCertificate.value = null
  sourcePassword.value = ''
}
function resetForm() {
  clearSensitive()
  state.consumer_key = ''
  certificateSource.value = isConfigured.value ? 'preserve' : 'upload'
  selectedAccount.value = null
  sourceMetadata.value = null
  sourceError.value = ''
  sourceFieldErrors.value = {}
  fieldErrors.value = {}
  operationError.value = ''
}
function startEditing() {
  if (busy.value || loading.value)
    return
  resetForm()
  editing.value = true
}
function cancelEditing() {
  if (busy.value)
    return
  runSource.invalidate()
  sourceLoading.value = false
  editing.value = false
  resetForm()
}
function load() {
  if (submitting.value || testing.value)
    return
  runDiagnostic.invalidate()
  testing.value = false
  connectivity.value = null
  connectivityError.value = ''
  loading.value = true
  loadError.value = null
  return runRead(connection, {
    onSuccess: (value) => {
      metadata.value = value
    },
    onError: (error) => {
      loadError.value = error
    },
    onSettled: () => {
      loading.value = false
    }
  })
}
function loadAccounts() {
  if (busy.value)
    return
  catalogLoading.value = true
  catalogError.value = ''
  return runCatalog(() => $api<AccountListResponse>('/admin/accounts', { query: { q: appliedQuery.value, page: accountPage.value } }), {
    onSuccess: (value) => {
      accountResults.value = value.data
      const meta = readPaginatedMeta(value)
      accountTotal.value = meta.total
      accountPerPage.value = meta.per_page
      catalogLoaded.value = true
    },
    onError: (error) => {
      catalogError.value = failureMessage(error)
    },
    onSettled: () => {
      catalogLoading.value = false
    }
  })
}
function searchAccounts() {
  if (busy.value || (catalogLoading.value && appliedQuery.value === accountQuery.value.trim() && accountPage.value === 1))
    return
  appliedQuery.value = accountQuery.value.trim()
  if (accountPage.value !== 1) {
    accountPage.value = 1
    return
  }
  return loadAccounts()
}
function selectAccount(id: number) {
  if (busy.value || (sourceLoading.value && sourceAccountId.value === id))
    return
  sourceAccountId.value = id
  sourceLoading.value = true
  sourceError.value = ''
  sourceFieldErrors.value = {}
  selectedAccount.value = null
  sourceMetadata.value = null
  sourceCertificate.value = null
  sourcePassword.value = ''
  return runSource(async () => {
    const [detail, source] = await Promise.all([
      $api<DetailResponse<ExtendedPlatformAccount>>(`/admin/accounts/${id}`), contractingCertificate(id)
    ])
    return { account: detail.data, source }
  }, {
    onSuccess: (value) => {
      selectedAccount.value = value.account
      sourceMetadata.value = value.source
    },
    onError: (error) => {
      sourceError.value = apiStatus(error) === 404 ? 'Escritório não encontrado. Selecione outro ou tente novamente.' : failureMessage(error)
    },
    onSettled: () => {
      sourceLoading.value = false
    }
  })
}
async function uploadSource() {
  const target = selectedAccount.value
  if (busy.value || loading.value || sourceLoading.value || catalogLoading.value || target?.status !== 'active')
    return
  sourceFieldErrors.value = {}
  sourceError.value = ''
  const invalid = fileError(sourceCertificate.value)
  if (invalid) {
    sourceFieldErrors.value.certificate = invalid
    return
  }
  uploadingSource.value = true
  // Replacing an attached vault rotates the existing connection too.
  runRead.invalidate()
  runDiagnostic.invalidate()
  connectivity.value = null
  connectivityError.value = ''
  try {
    const value = await uploadContractingCertificate(target.id, sourceCertificate.value!, sourcePassword.value)
    if (!active)
      return
    sourceMetadata.value = value
    await load()
    if (!active)
      return
    toast.add({ title: 'Certificado contratante salvo', color: 'success' })
  } catch (error) {
    if (!active)
      return
    sourceFieldErrors.value = apiFieldErrors(error)
    sourceError.value = failureMessage(error)
  } finally {
    clearSensitive()
    if (active)
      uploadingSource.value = false
  }
}
async function onSubmit(event: FormSubmitEvent<Schema>) {
  if (formDisabled.value)
    return
  fieldErrors.value = {}
  operationError.value = ''
  if (!isConfigured.value && !event.data.consumer_secret) {
    fieldErrors.value.consumer_secret = 'Informe o segredo do consumidor.'
    return
  }
  const payload: SerproConnectionPayload = {
    consumer_key: event.data.consumer_key ?? '', consumer_secret: event.data.consumer_secret || undefined
  }
  if (certificateSource.value === 'account') {
    payload.certificate_source = 'account'
    payload.contracting_account_id = selectedAccount.value!.id
  } else if (certificateSource.value === 'upload') {
    if (certificate.value || !isConfigured.value || metadata.value?.certificate_source !== 'upload') {
      const invalid = fileError(certificate.value)
      if (invalid) {
        fieldErrors.value.certificate = invalid
        return
      }
    }
    payload.certificate_source = 'upload'
    if (certificate.value) {
      payload.certificate = certificate.value
      payload.password = event.data.password ?? ''
    }
  }
  submitting.value = true
  runRead.invalidate()
  runDiagnostic.invalidate()
  try {
    const value = await saveConnection(payload)
    if (!active)
      return
    runRead.invalidate()
    metadata.value = value
    connectivity.value = null
    connectivityError.value = ''
    resetForm()
    editing.value = false
    toast.add({ title: 'Conexão com o Integra Contador salva', color: 'success' })
  } catch (error) {
    if (!active)
      return
    fieldErrors.value = apiFieldErrors(error)
    operationError.value = failureMessage(error)
  } finally {
    clearSensitive()
    if (active)
      submitting.value = false
  }
}
function onTestConnectivity() {
  if (busy.value || loading.value || !metadata.value)
    return
  testing.value = true
  connectivity.value = null
  connectivityError.value = ''
  return runDiagnostic(testConnectivity, {
    onSuccess: (value) => {
      connectivity.value = value
    },
    onError: (error) => {
      const status = apiStatus(error)
      connectivityError.value = status === 409
        ? 'A conexão mudou durante a verificação. Atualize os dados e tente novamente.'
        : status === 429 ? 'Limite de verificações atingido. Aguarde um minuto e tente novamente.' : failureMessage(error)
    },
    onSettled: () => {
      testing.value = false
    }
  })
}
watch(accountPage, loadAccounts)
watch(certificateSource, () => {
  clearSensitive()
  fieldErrors.value = {}
  sourceFieldErrors.value = {}
  operationError.value = ''
}, { flush: 'sync' })
onMounted(load)
onBeforeUnmount(() => {
  active = false
  runRead.invalidate()
  runSource.invalidate()
  runCatalog.invalidate()
  runDiagnostic.invalidate()
  clearSensitive()
  state.consumer_key = ''
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

    <ErrorRetryAlert
      v-if="loadError"
      title="Não foi possível carregar a conexão"
      @retry="load"
    />

    <USkeleton v-if="loading && !metadata" class="h-64 w-full" />

    <template v-if="metadata">
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
          <!-- Only the API's hint is displayed; stored credentials never hydrate the write-only form. -->
          <div>
            <dt class="text-sm text-muted">
              Chave de integração
            </dt>
            <dd class="text-sm font-medium text-default tabular-nums">
              {{ configuredCredential.consumer_key_hint ?? '—' }}
            </dd>
          </div>
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
          <div class="sm:col-span-2">
            <dt class="text-sm text-muted">
              Origem do certificado
            </dt>
            <dd class="text-sm font-medium">
              {{ currentSourceLabel }}
            </dd>
          </div>
        </dl>

        <p class="text-sm text-muted">
          O segredo, a senha e o arquivo do certificado não são devolvidos pela API e não aparecem aqui. A chave aparece apenas como pista: o que está gravado não é devolvido.
        </p>

        <div class="flex justify-end">
          <UButton
            label="Atualizar credencial"
            icon="i-lucide-pencil"
            color="neutral"
            variant="outline"
            type="button"
            :disabled="busy || loading"
            @click="startEditing"
          />
        </div>
      </UCard>

      <UAlert
        v-else-if="!editing"
        color="neutral"
        variant="subtle"
        icon="i-lucide-plug"
        title="A credencial da plataforma ainda não foi cadastrada"
        description="Informe chave, segredo e um certificado contratante válido para configurar a integração."
      />

      <UPageCard
        v-if="showForm"
        :title="isConfigured ? 'Atualizar credencial' : 'Credencial da plataforma'"
        :description="isConfigured
          ? 'Chave e segredo em branco preservam os valores guardados. Escolha explicitamente se deseja substituir o certificado.'
          : 'Chave, segredo e certificado do provedor.'"
        variant="subtle"
      >
        <UForm
          id="serpro-connection"
          :schema="schema"
          :state="state"
          :disabled="busy"
          class="flex flex-col gap-4"
          @submit="onSubmit"
        >
          <UFormField
            label="Chave de integração"
            name="consumer_key"
            :error="fieldErrors.consumer_key"
            help="Consumer key fornecida pelo Integra Contador. Em branco, a chave guardada é preservada."
            :required="!isConfigured"
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
            :error="fieldErrors.consumer_secret"
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
            name="certificate_source"
            help="De onde vem o certificado que assina as chamadas da plataforma."
          >
            <URadioGroup
              v-model="certificateSource"
              :items="[
                ...(isConfigured ? [{ value: 'preserve', label: 'Manter certificado atual', description: currentSourceLabel }] : []),
                { value: 'account', label: 'Selecionar escritório', description: 'Usar o cofre contratante da plataforma no escritório escolhido.' },
                { value: 'upload', label: 'Enviar arquivo para esta conexão', description: 'Arquivo dedicado à credencial da plataforma.' }
              ]"
              variant="card"
              class="w-full"
            />
          </UFormField>

          <template v-if="certificateSource === 'upload'">
            <UFormField
              label="Arquivo do certificado"
              name="certificate"
              :error="fieldErrors.certificate"
              help="Arquivo .pfx ou .p12, até 2 MiB. Sem novo arquivo, o upload dedicado atual é preservado."
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
              :error="fieldErrors.password"
              help="Senha do novo arquivo. Deixe em branco para um arquivo sem senha."
            >
              <UInput
                v-model="state.password"
                type="password"
                autocomplete="off"
                placeholder="Senha do novo arquivo (pode ficar vazia)"
                class="w-full"
              />
            </UFormField>
          </template>
          <template v-if="certificateSource === 'account'">
            <UFormField label="Buscar escritório" name="contracting_account_id" :error="fieldErrors.contracting_account_id || fieldErrors.certificate_source">
              <div class="flex flex-wrap gap-2">
                <UInput
                  v-model="accountQuery"
                  aria-label="Nome do escritório contratante"
                  placeholder="Nome do escritório"
                  class="min-w-0 flex-1"
                  :disabled="busy"
                  @keydown.enter.prevent="searchAccounts"
                />
                <UButton
                  label="Buscar"
                  type="button"
                  :loading="catalogLoading"
                  :disabled="busy"
                  @click="searchAccounts"
                />
              </div>
            </UFormField>
            <UAlert
              v-if="catalogError"
              color="error"
              :title="catalogError"
              :actions="[{ label: 'Tentar novamente', onClick: loadAccounts }]"
            />
            <p v-if="catalogLoaded && !accountResults.length" class="text-sm text-muted">
              Nenhum escritório encontrado.
            </p>
            <div v-for="item in accountResults" :key="item.id" class="flex flex-wrap items-center justify-between gap-2">
              <span class="text-sm">#{{ item.id }} · {{ item.name }}{{ item.status === 'suspended' ? ' (suspenso)' : '' }}</span>
              <UButton
                :label="selectedAccount?.id === item.id ? 'Selecionado' : 'Selecionar'"
                type="button"
                color="neutral"
                variant="outline"
                :disabled="busy || item.status !== 'active'"
                @click="selectAccount(item.id)"
              />
            </div>
            <UPagination
              v-if="accountTotal > accountPerPage"
              v-model:page="accountPage"
              :total="accountTotal"
              :items-per-page="accountPerPage"
              :disabled="busy || catalogLoading"
            />
            <USkeleton v-if="sourceLoading" class="h-16 w-full" />
            <UAlert
              v-if="sourceError"
              color="error"
              :title="sourceError"
              :actions="sourceAccountId ? [{ label: 'Tentar novamente', disabled: busy || sourceLoading, onClick: () => selectAccount(sourceAccountId!) }] : []"
            />
            <template v-if="selectedAccount && !sourceLoading">
              <p class="text-sm font-medium">
                Escritório #{{ selectedAccount.id }} · {{ selectedAccount.name }}
              </p>
              <UAlert v-if="selectedAccount.status !== 'active'" color="warning" title="Escritório suspenso: selecione outro escritório ativo." />
              <UAlert v-else-if="!sourceUsable" color="warning" title="Este escritório não tem um certificado contratante válido no cofre da plataforma. Envie um arquivo abaixo." />
              <dl v-if="sourceMetadata?.configured" class="grid gap-2 text-sm sm:grid-cols-2">
                <div>
                  <dt class="text-muted">
                    Titular
                  </dt><dd>{{ sourceMetadata.subject }}</dd>
                </div>
                <div>
                  <dt class="text-muted">
                    Documento
                  </dt><dd>{{ sourceMetadata.document }}</dd>
                </div>
                <div>
                  <dt class="text-muted">
                    Série
                  </dt><dd>{{ sourceMetadata.serial }}</dd>
                </div>
                <div>
                  <dt class="text-muted">
                    Validade
                  </dt><dd>{{ formatMonitoringDate(sourceMetadata.not_before) }} a {{ formatMonitoringDate(sourceMetadata.not_after) }}</dd>
                </div>
              </dl>
              <template v-if="selectedAccount.status === 'active'">
                <UFormField
                  label="Enviar certificado ao cofre contratante da plataforma"
                  name="source_certificate"
                  :error="sourceFieldErrors.certificate"
                  help=".pfx ou .p12, até 2 MiB. Substitui o cofre e atualiza a conexão que já o utiliza."
                >
                  <UFileUpload
                    v-model="sourceCertificate"
                    accept=".pfx,.p12"
                    label="Selecionar arquivo"
                    :disabled="busy"
                    class="w-full"
                  />
                </UFormField>
                <UFormField
                  label="Senha do arquivo do cofre"
                  name="source_password"
                  :error="sourceFieldErrors.password"
                  help="Pode ficar vazia para arquivos sem senha."
                >
                  <UInput
                    v-model="sourcePassword"
                    type="password"
                    autocomplete="off"
                    :disabled="busy"
                    class="w-full"
                  />
                </UFormField>
                <UButton
                  label="Salvar certificado no cofre"
                  type="button"
                  :loading="uploadingSource"
                  :disabled="busy || sourceLoading || catalogLoading || !sourceCertificate"
                  @click="uploadSource"
                />
              </template>
            </template>
          </template>
          <UAlert
            v-if="operationError"
            color="error"
            title="Não foi possível salvar a conexão"
            :description="operationError"
          />
        </UForm>

        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton
              v-if="isConfigured"
              label="Cancelar"
              color="neutral"
              variant="subtle"
              type="button"
              :disabled="busy"
              @click="cancelEditing"
            />
            <UButton
              label="Salvar conexão"
              type="submit"
              form="serpro-connection"
              :loading="submitting"
              :disabled="formDisabled"
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
            :disabled="busy || loading"
            @click="onTestConnectivity"
          />

          <UAlert
            v-if="connectivityError"
            color="warning"
            title="Verificação não concluída"
            :description="connectivityError"
            :actions="[{ label: 'Tentar novamente', disabled: busy || loading, onClick: onTestConnectivity }]"
          />
          <UAlert
            v-if="connectivity"
            :color="serproConnectivityTone(connectivity)"
            variant="subtle"
            :icon="serproConnectivityIcon(connectivity)"
            :title="serproConnectivityTitle(connectivity)"
            :description="connectivityDescription"
          />
        </div>
      </UPageCard>
    </template>
  </div>
</template>
