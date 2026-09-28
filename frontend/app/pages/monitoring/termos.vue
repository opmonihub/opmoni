<script setup lang="ts">
import type { MetaListItem } from '~/components/data-table/MetaList.vue'
import { apiErrorMessage, apiStatus } from '~/composables/useApiError'
import type { SerproAccountCertificate, SerproAuthorizationTerm } from '~/types/serpro'
import { formatMonitoringDate, serproCertificateAsk, serproCertificateMissingNotice, serproCertificateRemoval, serproCertificateReplacement, serproTermGuidance, serproTermRequest, serproTermStatePresentation } from '~/utils/monitoringPresentation'
import { pageDetailClass, pageRecordScrollClass } from '~/utils/pageShell'

definePageMeta({ middleware: 'auth' })

/**
 * O e-CNPJ é do escritório, e o termo é da plataforma. Esta tela é o único lugar
 * onde o primeiro é entregue e onde se acompanha o segundo — e a única coisa que
 * ela jamais pede é uma assinatura: o termo é montado, assinado, enviado e
 * renovado pelo backend, com o certificado que o escritório entregou uma vez
 * (D3, e o cenário "Escritório não assina nada").
 */
const { canManageClients } = useAuth()
const toast = useToast()
const { authorizationTerm, accountCertificate, uploadAccountCertificate, removeAccountCertificate } = useSerpro()

/**
 * O termo, com `getCachedData: () => undefined` pelo mesmo motivo do
 * monitoramento: um termo renovado há minutos não pode ser lido do payload do
 * SSR. O `404` é a tolerância do mesmo tipo do certificado abaixo — a rota ainda
 * não existir é o estado inerte, e não uma falha para gritar.
 */
const { data: term, status: termStatus, error: termError, refresh: reloadTerm } = await useAsyncData<SerproAuthorizationTerm | null>(
  'serpro-authorization-term',
  async () => {
    try {
      return await authorizationTerm()
    } catch (e) {
      if (apiStatus(e) === 404) return null
      throw e
    }
  },
  { default: () => null, getCachedData: () => undefined }
)

/**
 * O certificado que o escritório já entregou, e `null` quando não entregou
 * nenhum — que é a resposta normal desta rota, não uma falha. Sem ele a tela não
 * consegue distinguir "ainda não entregou" de "entregou e o termo está em
 * emissão", e as duas precisam de textos opostos.
 */
const { data: certificate, status: certificateStatus, error: certificateError, refresh: reloadCertificate } = await useAsyncData<SerproAccountCertificate | null>(
  'serpro-account-certificate',
  async () => {
    try {
      return await accountCertificate()
    } catch (e) {
      if (apiStatus(e) === 404) return null
      throw e
    }
  },
  { default: () => null, getCachedData: () => undefined }
)

const hasCertificate = computed(() => certificate.value !== null)
const termState = computed(() => term.value?.state ?? 'ausente')

/**
 * O que a tela pede ao escritório, e a decisão que impede a tela de virar tarefa
 * recorrente. Sem certificado, o pedido é o certificado; com o certificado já
 * entregue, o pedido só existe em `vencido` e `recusado`, e é a **reentrega** —
 * inclusive no `ausente` que o gate de emissão ainda fechado produz, que é um
 * estado real do produto e não um atraso que alguém da conta possa resolver.
 */
const request = computed(() => serproTermRequest(termState.value, hasCertificate.value))
const ask = computed(() => serproCertificateAsk(termState.value, hasCertificate.value))

/**
 * O que o cartão do certificado diz quando há pedido, e o que ele diz quando não
 * há certificado e também não há pedido. São textos diferentes porque as
 * consequências são diferentes — o texto que diz "a integração parou" acima de um
 * termo válido seria mentira, e é a remoção que produz essa situação.
 */
const certificateNotice = computed(() => (ask.value
  ?? (hasCertificate.value ? null : serproCertificateMissingNotice(termState.value))))

/**
 * Os rótulos do formulário. `substituir` é mentira numa conta que nunca entregou
 * certificado, e o rótulo do pedido tem a precedência porque é ele que sabe se o
 * que se pede é uma reentrega por termo vencido ou recusado.
 */
const isReplacing = computed(() => hasCertificate.value)
const fileLabel = computed(() => (isReplacing.value ? 'Selecionar novo arquivo' : 'Selecionar arquivo'))
const submitLabel = computed(() => ask.value?.label
  ?? certificateNotice.value?.label
  ?? serproCertificateReplacement.label)

/**
 * O badge fica dentro de `v-if="term"`, e o caso sem termo não desenha badge
 * nenhum: não há leitura `ausente` para onde cair, e fingir que há colocaria
 * "Termo não emitido" num caminho que não mostra estado.
 */
const presentation = computed(() => serproTermStatePresentation[term.value!.state])

/**
 * Quem entrega e quem remove: `admin` e `operador`, que é o que
 * `AccountCertificatePolicy` concede para `create` e para `delete`. O papel `user`
 * é somente leitura na prática e não aparece em nenhuma policy de escrita — por
 * isso o formulário inteiro some para ele, e não apenas o botão.
 */
const canWriteCertificate = canManageClients

const termFacts = computed<MetaListItem[]>(() => {
  if (!term.value) return []
  return [
    { label: 'Estado', value: presentation.value.label },
    { label: 'Vencimento', value: formatMonitoringDate(term.value.expires_on), mono: true },
    { label: 'Assinado em', value: formatMonitoringDate(term.value.signed_at), mono: true },
    /*
     * Quem assina é a plataforma, com o e-CNPJ do escritório. Escrever "do
     * escritório" na linha da assinatura sugeriria um documento assinado à mão
     * por alguém da conta, que é exatamente o que o produto não faz e não deve
     * parecer que faz.
     */
    { label: 'Assinatura', value: 'Pela plataforma, com o e-CNPJ do escritório' },
    /*
     * `document_present` é booleano porque o documento assinado não sai do
     * backend: a tela afirma que ele existe e nada mais. O outro ramo diz
     * "ainda não assinado" em vez de repetir o estado, porque quem lê esta
     * linha está olhando o documento e não a situação do termo.
     */
    { label: 'Documento assinado', value: term.value.document_present ? 'Guardado no servidor' : 'Ainda não assinado' }
  ]
})

const certificateFacts = computed<MetaListItem[]>(() => {
  const stored = certificate.value
  if (!stored) return []
  return [
    { label: 'Titular', value: stored.subject, truncate: true },
    { label: 'CNPJ do certificado', value: stored.document, mono: true },
    { label: 'Número de série', value: stored.serial_number, mono: true },
    { label: 'Validade', value: `${formatMonitoringDate(stored.valid_from)} a ${formatMonitoringDate(stored.valid_until)}`, mono: true },
    { label: 'Arquivo enviado', value: stored.original_filename, truncate: true },
    { label: 'Entregue em', value: formatMonitoringDate(stored.uploaded_at), mono: true }
  ]
})

/**
 * Os dois carregamentos compartilham um alerta e um esqueleto, porque aparecem
 * juntos e um erro em qualquer um dos dois deixa a tela inteira inutilizável.
 */
const { isLoading, showError, refresh, retry } = useRetryableLoad({
  refresh: async () => {
    await Promise.all([reloadTerm(), reloadCertificate()])
  },
  error: computed(() => certificateError.value ?? termError.value),
  loading: computed(() => termStatus.value === 'pending' || certificateStatus.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar o termo',
  refreshErrorTitle: 'Não foi possível atualizar o termo',
  ignoreStatus: 404
})

useMonitoringActions({ refresh, loading: isLoading })

/**
 * `shallowRef` e não `ref`: o `File` é enviado tal como foi escolhido, e a
 * identidade do objeto é a que vai no `FormData` — um proxy reativo em volta
 * dele não acrescentaria nada. O arquivo fica no estado do componente, nunca em
 * `useState`, que é serializado no HTML do SSR, e é esvaziado assim que a
 * requisição termina.
 */
const file = shallowRef<File | null>(null)
const password = ref('')
const submitting = ref(false)
const removing = ref(false)
const confirmingRemove = ref(false)

const canSubmit = computed(() => !!file.value && password.value.length > 0)

async function submitCertificate() {
  if (!file.value || !password.value || !canWriteCertificate.value) return
  submitting.value = true
  try {
    await uploadAccountCertificate(file.value, password.value)
    toast.add({ title: 'Certificado do escritório entregue', color: 'success' })
    // As duas leituras são refeitas porque a entrega muda as duas: o certificado
    // passa a existir e o termo é reemitido. Com o gate de emissão ainda fechado
    // ele volta como `ausente`, e essa é a leitura honesta — o escritório não
    // pode resolver um gate que é do produto, e a tela não pinta isso de erro.
    await Promise.all([reloadCertificate(), reloadTerm()])
  } catch (err) {
    // A recusa nomeada do `POST` — a senha que não abre o e-CNPJ, o arquivo que
    // não é PKCS#12, a extensão que não é `.pfx`/`.p12`, o certificado vencido —
    // está em `errors.<campo>`, e o `message` de topo de um `422` é sempre a
    // mesma frase genérica: "The given data was invalid." Ler só o `message`
    // mostraria a mesma coisa para as quatro e não diria qual campo corrigir.
    toast.add({ title: 'Não foi possível entregar o certificado', description: apiErrorMessage(err), color: 'error' })
  } finally {
    // O arquivo e a senha saem do estado da página de qualquer jeito: um e-CNPJ
    // não fica à mão depois de uma recusa, e o que sobra é a recusa nomeada, que
    // é o que o escritório precisa.
    file.value = null
    password.value = ''
    submitting.value = false
  }
}

async function confirmRemove() {
  removing.value = true
  try {
    await removeAccountCertificate()
    confirmingRemove.value = false
    toast.add({ title: 'Certificado do escritório removido', color: 'success' })
    // As duas leituras: sem a do certificado, a tela continuaria mostrando o
    // e-CNPJ removido e o botão de remoção por cima dele. E a do termo é
    // refeita porque a tela não deve mostrar um estado que a remoção não
    // produziu — o estado não muda, e é isso que a tela vai dizer: o documento
    // já assinado continua gravado e continua sendo enviado pela plataforma,
    // que renova sem precisar do certificado. O que a remoção tira é a emissão
    // de um termo novo.
    await Promise.all([reloadCertificate(), reloadTerm()])
  } catch (err) {
    toast.add({ title: 'Não foi possível remover o certificado', description: apiErrorMessage(err), color: 'error' })
  } finally {
    removing.value = false
  }
}
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

      <template v-else>
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
                A plataforma monta, assina, envia e renova o termo sozinha, com o e-CNPJ do próprio escritório. Ninguém da conta assina o termo.
              </p>
              <div
                v-if="term"
                class="mt-2 flex flex-wrap items-center gap-1.5"
              >
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
          v-if="term && request !== 'nenhuma'"
          :color="presentation.color"
          variant="subtle"
          :icon="presentation.icon"
          :title="presentation.label"
          :description="serproTermGuidance[term.state]"
        />

        <UCard
          v-if="term"
          :ui="{ body: 'p-3 sm:p-4' }"
        >
          <h2 class="mb-3 text-sm font-semibold text-highlighted">
            Dados do termo
          </h2>
          <DataTableMetaList :items="termFacts" />
          <template v-if="request === 'nenhuma'">
            <USeparator class="my-3" />
            <p class="text-xs text-muted">
              {{ serproTermGuidance[term.state] }}
            </p>
          </template>
        </UCard>

        <UCard :ui="{ body: 'p-3 sm:p-4 flex flex-col gap-4' }">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
              <h2 class="text-sm font-semibold text-highlighted">
                Certificado do escritório (e-CNPJ)
              </h2>
              <p class="text-xs text-muted">
                Entregue uma vez e é ele que a plataforma usa para assinar e renovar o termo. O arquivo e a senha ficam cifrados no banco e a API não devolve nenhum dos dois.
              </p>
            </div>
            <UBadge
              v-if="hasCertificate"
              color="success"
              icon="i-lucide-shield-check"
              variant="subtle"
              size="sm"
              label="Entregue"
            />
          </div>

          <template v-if="certificate">
            <DataTableMetaList :items="certificateFacts" />

            <div
              v-if="canWriteCertificate"
              class="flex flex-wrap items-center justify-between gap-2"
            >
              <UButton
                v-if="!confirmingRemove"
                :label="serproCertificateRemoval.action"
                icon="i-lucide-trash-2"
                color="error"
                variant="ghost"
                type="button"
                @click="confirmingRemove = true"
              />
            </div>

            <UAlert
              v-if="confirmingRemove"
              color="error"
              variant="subtle"
              icon="i-lucide-trash-2"
              :title="serproCertificateRemoval.title"
              :description="serproCertificateRemoval.description"
            />
            <div
              v-if="confirmingRemove"
              class="flex justify-end gap-2"
            >
              <UButton
                :label="serproCertificateRemoval.keep"
                color="neutral"
                variant="subtle"
                type="button"
                @click="confirmingRemove = false"
              />
              <UButton
                :label="serproCertificateRemoval.confirm"
                color="error"
                variant="solid"
                type="button"
                :loading="removing"
                @click="confirmRemove"
              />
            </div>
          </template>

          <UAlert
            v-if="certificateNotice"
            :color="certificateNotice.color"
            :icon="certificateNotice.icon"
            variant="subtle"
            :title="certificateNotice.title"
            :description="certificateNotice.description"
          />

          <!--
            O formulário aparece para quem pode escrever **sempre**, e não só
            quando há pedido: o e-CNPJ expira sozinho, e uma tela que só
            aceitasse a entrega quando algo estivesse pendente deixaria o
            escritório sem caminho para trazer o certificado novo. O que muda
            com o estado é o texto acima e o rótulo do botão.
          -->
          <p
            v-if="canWriteCertificate && hasCertificate && !ask"
            class="text-xs text-muted"
          >
            <span class="font-medium text-default">{{ serproCertificateReplacement.title }}</span> — {{ serproCertificateReplacement.description }}
          </p>

          <template v-if="canWriteCertificate">
            <UFormField
              label="Arquivo do certificado (.pfx ou .p12)"
              name="certificate"
              :help="isReplacing
                ? 'Substitui o certificado guardado: a linha anterior continua no histórico, sem o conteúdo cifrado.'
                : 'A API decide pela extensão do nome do arquivo, e não pelo conteúdo — é o que faz um e-CNPJ de verdade passar.'"
            >
              <UFileUpload
                v-model="file"
                accept=".pfx,.p12"
                :label="fileLabel"
                description="Arraste o arquivo ou clique para selecionar"
                class="w-full"
              />
            </UFormField>

            <UFormField
              label="Senha do certificado"
              name="password"
              help="A senha que abre o arquivo. Ela não é guardada no navegador e a API não a devolve."
            >
              <UInput
                v-model="password"
                type="password"
                autocomplete="off"
                placeholder="Senha do arquivo"
                class="w-full"
              />
            </UFormField>

            <div class="flex justify-end">
              <UButton
                :label="submitLabel"
                icon="i-lucide-upload"
                type="button"
                :loading="submitting"
                :disabled="!canSubmit"
                @click="submitCertificate"
              />
            </div>
          </template>

          <p
            v-else-if="ask"
            class="text-xs text-muted"
          >
            A entrega do certificado é do administrador e do operador do escritório. O seu papel aqui é somente leitura.
          </p>
        </UCard>
      </template>
    </div>
  </div>
</template>
