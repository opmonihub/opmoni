<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { apiErrorMessage, apiStatus } from '~/composables/useApiError'
import type { SerproConnectionMetadata, SerproConnectivityResult } from '~/types/serpro'
import { formatMonitoringDate } from '~/utils/monitoringPresentation'
import {
  connectivityIcon as serproConnectivityIcon,
  connectivityTitle as serproConnectivityTitle,
  connectivityTone as serproConnectivityTone,
  failedElementName as serproFailedElementName
} from '~/utils/serproConnectivityPresentation'

/**
 * No `definePageMeta` and no role check on this page. `app/pages/admin.vue`
 * already declares `middleware: ['auth', 'super-admin']` for everything under
 * `/admin`, so a second copy of the rule would be a second thing to keep in
 * sync. The per-Account enablement switch is not on this screen either: only
 * `is_super_admin` may enable or disable integration (see `serpro-connection`).
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

/**
 * A fonte do certificado contratante: o e-CNPJ que a conta 1 já gravou em
 * Configurações, sem uma segunda cópia do arquivo, ou um arquivo diferente
 * enviado aqui. `file` é o padrão porque é a opção que sempre existe — a outra
 * depende de a conta ter certificado, e quem confirma isso é a recusa nomeada
 * do `PUT`.
 */
const certificateSource = ref<'account' | 'file'>('file')

const submitting = ref(false)
const testing = ref(false)
/** True only while the operator is deliberately changing a stored credential. */
const editing = ref(false)
const connectivity = ref<SerproConnectivityResult | null>(null)

/**
 * The first rung of the ladder: a 404 answers `null` rather than throwing, so
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

/**
 * 404-exempt like the other monitoring screens, and `sticky` is deliberately
 * left off: this alert is a sibling of the skeleton and the form, not the `v-else`
 * of a list, so it only has to hold while `error` itself holds. The second toast
 * is the manual retry, which is why it is worded apart from the load failure.
 */
const { isLoading, showError, retry } = useRetryableLoad({
  refresh,
  error,
  loading: computed(() => status.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar a conexão',
  refreshErrorTitle: 'Não foi possível atualizar a conexão',
  ignoreStatus: 404
})

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

const failedElementName = computed(() => serproFailedElementName(connectivity.value))

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
  certificateSource.value = 'file'
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
      // A escolha é excludente: o flag aponta para o e-CNPJ da conta 1, e o
      // arquivo — quando é a fonte — leva a senha junto. Mandar os dois é a
      // ambiguidade que o backend recusa, e o formulário não a produz.
      use_account_certificate: certificateSource.value === 'account',
      certificate: certificateSource.value === 'file' ? (certificate.value ?? undefined) : undefined,
      password: certificateSource.value === 'file' ? (event.data.password || undefined) : undefined
    })
    metadata.value = saved
    resetForm()
    editing.value = false
    // The old verdict says nothing about the credential just stored.
    connectivity.value = null
    toast.add({ title: 'Conexão com o Integra Contador salva', color: 'success' })
  } catch (err) {
    // A recusa nomeada do `PUT` — a gravação perdedora da corrida, a senha que
    // não abre o certificado, a credencial incompleta na primeira vez — está em
    // `errors.<campo>`, e o `message` de topo de um 422 é sempre a mesma frase
    // genérica. Ler só o `message` deixava o operador sem nenhuma pista.
    toast.add({ title: 'Não foi possível salvar a conexão', description: apiErrorMessage(err), color: 'error' })
  } finally {
    submitting.value = false
  }
}

async function onTestConnectivity() {
  testing.value = true
  try {
    connectivity.value = await testConnectivity()
  } catch (err) {
    toast.add({ title: 'Não foi possível testar a conexão', description: apiErrorMessage(err), color: 'error' })
  } finally {
    testing.value = false
  }
}
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
      v-if="showError"
      title="Não foi possível carregar a conexão"
      @retry="retry"
    />

    <USkeleton v-else-if="isLoading" class="h-64 w-full" />

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
          <!--
            The key's own hint, which the API returns as `consumer_key_hint` and
            which is a hint precisely because it is not the stored secret. It is
            what makes the rotation path reachable: `consumer_key` is required on
            every save, so without it only an operator who already knows the key
            being replaced can rotate — not the one the "key lost or compromised"
            scenario is about, who has the new key in hand and no idea what the
            old one was.
          -->
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
            name="certificate_source"
            help="De onde vem o certificado que assina as chamadas da plataforma."
          >
            <URadioGroup
              v-model="certificateSource"
              :items="[
                { value: 'account', label: 'Usar o e-CNPJ do escritório', description: 'O certificado já gravado em Configurações da conta 1, sem segunda cópia do arquivo.' },
                { value: 'file', label: 'Enviar outro arquivo', description: 'Um certificado diferente (.pfx ou .p12), gravado só nesta credencial.' }
              ]"
              variant="card"
              class="w-full"
            />
          </UFormField>

          <template v-if="certificateSource === 'file'">
            <UFormField
              label="Arquivo do certificado"
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
          </template>
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
