<script setup lang="ts">
import type { MetaListItem } from '~/components/data-table/MetaList.vue'
import { apiErrorMessage, apiStatus } from '~/composables/useApiError'
import type { SerproAccountCertificate, SerproAuthorizationTerm } from '~/types/serpro'
import { formatMonitoringDate, serproCertificateRemoval, serproTermRequest, serproTermScreen, serproTermStatePresentation } from '~/utils/monitoringPresentation'
import { enablementAction, enablementConfirm, enablementNotice, enablementState } from '~/utils/serproEnablement'
import { pageDetailClass, pageRecordScrollClass } from '~/utils/pageShell'

/**
 * O e-CNPJ é do escritório, e o termo é da plataforma. Esta tela é o único lugar
 * onde o primeiro é entregue e onde se acompanha o segundo — e a única coisa que
 * ela jamais pede é uma assinatura: o termo é montado, assinado, enviado e
 * renovado pelo backend, com o certificado que o escritório entregou uma vez
 * (D3, e o cenário "Escritório não assina nada").
 *
 * A tela mora no Painel Global porque a entrega e a habilitação escrevem em nome
 * da plataforma, na Account corrente — quem escreve é o `is_super_admin`, e o
 * shell `pages/admin.vue` já aplica `middleware: ['auth', 'super-admin']` a tudo
 * sob `/admin`, então aqui não há `definePageMeta` nem checagem de papel a
 * repetir.
 */
const { canManageClients, canManageMembers } = useAuth()
const toast = useToast()
const { authorizationTerm, accountCertificate, uploadAccountCertificate, removeAccountCertificate, enablement, setEnablement } = useSerpro()

/**
 * O termo, com `getCachedData: () => undefined` pelo mesmo motivo das telas de
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
 * nenhum — que é a resposta normal desta rota, não uma falha. Sem esta segunda
 * leitura a tela não distingue "ainda não entregou nada" de "entregou e o termo é
 * emissão da plataforma", e são dois textos opostos: um pede a entrega, o outro
 * diz que nada está sendo pedido.
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

/**
 * O interruptor do escritório. O GET responde `404` num backend que ainda não
 * conhece a rota — o mesmo ramo das outras duas leituras desta tela — e a seção
 * inteira some nesse caso, porque um interruptor `null` não é "desligado": é um
 * backend que ainda não fala a língua, e pintar "desabilitada" sobre ele
 * sugeriria uma decisão que ninguém tomou.
 */
const { data: enablementData, refresh: reloadEnablement } = await useAsyncData<{ enabled: boolean } | null>(
  'serpro-enablement',
  async () => {
    try {
      return await enablement()
    } catch (e) {
      if (apiStatus(e) === 404) return null
      throw e
    }
  },
  { default: () => null, getCachedData: () => undefined }
)

const enablementAvailable = computed(() => enablementData.value !== null)
const isEnabled = computed(() => enablementData.value?.enabled ?? false)
const enablementStateView = computed(() => enablementState(isEnabled.value))
const confirmDisable = ref(false)
const toggling = ref(false)

async function toggleEnablement() {
  // A guarda fica no código e não só no `v-if`: aqui quem escreve é o
  // `is_super_admin`, e um clique que a API recusaria com `403` viraria uma
  // recusa anônima de quem não tinha o botão.
  if (!canManageMembers.value) return
  const target = !isEnabled.value
  toggling.value = true
  try {
    const res = await setEnablement(target)
    enablementData.value = res
    confirmDisable.value = false
    toast.add({ title: enablementNotice(res.enabled), color: 'success' })
  } catch (err) {
    toast.add({ title: 'Não foi possível alterar a habilitação', description: apiErrorMessage(err), color: 'error' })
  } finally {
    toggling.value = false
  }
}

const hasCertificate = computed(() => certificate.value !== null)
const termState = computed(() => term.value?.state ?? 'ausente')

/**
 * O que a tela pede ao escritório, e a decisão que impede a tela de virar tarefa
 * recorrente. Sem certificado, o pedido é o certificado; com o certificado já
 * entregue, o pedido só existe em `vencido` e `recusado`, e é a **reentrega**.
 *
 * O `ausente` com certificado é o par que o gate de emissão fechado produz para
 * todo escritório que entregou o e-CNPJ hoje, e nele o pedido é `'nenhuma'`:
 * um estado real do produto, não um atraso que alguém da conta possa resolver.
 */
const request = computed(() => serproTermRequest(termState.value, hasCertificate.value))

/**
 * **Toda a frase da tela vem daqui**, e não do template: é o enumerador que o
 * oráculo de consistência lê, e uma tela que desenhasse texto de outro lugar
 * estaria de fora da conferência. Se um bloco aparecer no template sem passar por
 * `serproTermScreen`, ele nasce sem guarda — que foi como a frase do cabeçalho
 * sobreviveu a três revisões.
 */
const screen = computed(() => serproTermScreen(termState.value, hasCertificate.value))

/**
 * O badge fica dentro de `v-if="term"`, e o caso sem termo não desenha badge
 * nenhum: não há leitura `ausente` para onde cair, e fingir que há colocaria
 * "Termo não emitido" num caminho que não mostra estado.
 */
const presentation = computed(() => serproTermStatePresentation[term.value!.state])

/**
 * Quem entrega e quem remove é só o `is_super_admin`: é o que
 * `AccountCertificatePolicy` concede para `create` e para `delete`. O shell já
 * barra o resto pela rota, e a guarda continua explícita aqui porque a recusa do
 * backend não pode ser a primeira coisa que um clique produz — e se a tela um
 * dia for aberta a quem só lê, o formulário inteiro some, e não apenas o botão.
 */
const canWriteCertificate = canManageClients

const termFacts = computed<MetaListItem[]>(() => {
  if (!term.value) return []
  return [
    { label: 'Estado', value: presentation.value.label },
    { label: 'Vencimento', value: formatMonitoringDate(term.value.expires_on), mono: true },
    { label: 'Assinado em', value: formatMonitoringDate(term.value.signed_at), mono: true },
    /*
     * Quem assina é a plataforma, com o e-CNPJ do escritório — e a linha
     * descreve o mecanismo, não uma assinatura que aconteceu: no estado
     * `ausente` o termo ainda não existe, e o que a linha diz é de quem seria a
     * assinatura quando ele existir. Escrever "do escritório" aqui sugeriria um
     * documento assinado à mão por alguém da conta, que é exatamente o que o
     * produto não faz e não deve parecer que faz.
     */
    { label: 'Assinatura', value: screen.value.signature },
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
 * A atualização é a da própria tela: a navbar do Admin não tem o botão do
 * monitoramento, e a tela não declara ações de navbar para o módulo.
 */
const { isLoading, showError, retry } = useRetryableLoad({
  refresh: async () => {
    await Promise.all([reloadTerm(), reloadCertificate(), reloadEnablement()])
  },
  error: computed(() => certificateError.value ?? termError.value),
  loading: computed(() => termStatus.value === 'pending' || certificateStatus.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar o termo',
  refreshErrorTitle: 'Não foi possível atualizar o termo',
  ignoreStatus: 404
})

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
    // passa a existir, e o termo é relido porque é o que a entrega agenda
    // emitir. Com o gate de emissão fechado essa emissão não sai, e o termo volta
    // como `ausente` — que é a leitura honesta: o escritório não pode resolver um
    // gate que é do produto, e a tela não pinta isso de erro nem de aviso de que
    // algo está sendo pedido.
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
  // A guarda está no código e não só no `v-if` do botão: `submitCertificate`
  // também tem, e uma remoção sem ela passaria pela `AccountCertificatePolicy`
  // como um `403` que a tela transformaria em recusa anônima. Um `v-if` é uma
  // convenção que só a revisão humana fiscaliza; a linha abaixo é o contrato.
  if (!canWriteCertificate.value) return
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
              <!--
                A frase que carrega a promessa mais forte da tela **não mora
                aqui**: ela é `serproTermScreen().header`, no módulo, porque
                `node --test` não importa `.vue` e uma frase escrita no template é
                uma frase que nenhum oráculo consegue ler. Foi exatamente por
                isso que ela sobreviveu a três rondas de revisão — em `ausente`,
                `vencido` e `recusado` ela prometia renovação, e `refresh()` não
                reenvia em nenhum dos três. O portão é `serproTermReenviado`, e o
                teste é `o cabeçalho só promete renovação onde refresh() ainda
                reenvia`, em `monitoringTermScreenConsistency.test.ts`.
              -->
              <p class="text-xs text-muted">
                {{ screen.header }}
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
                  :label="screen.badge"
                />
              </div>
            </div>
          </div>
        </UCard>

        <!--
          O controle do escritório, e só para `is_super_admin`: é para ele que a
          spec reserva a habilitação, e esta tela é o lugar porque a decisão é da
          plataforma sobre a Account corrente — `/admin/serpro`, ao lado, cuida
          da credencial compartilhada, que é outra coisa.
        -->
        <UCard
          v-if="canManageMembers && enablementAvailable"
          :ui="{ body: 'p-3 sm:p-4' }"
        >
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <h2 class="text-sm font-semibold text-highlighted">
                  Integração com o Integra Contador
                </h2>
                <UBadge
                  :color="isEnabled ? 'success' : 'neutral'"
                  variant="subtle"
                  size="sm"
                  :label="enablementStateView.label"
                />
              </div>
              <p class="mt-1 text-xs text-muted">
                {{ enablementStateView.description }}
              </p>
            </div>
            <UButton
              :label="enablementAction(isEnabled)"
              :color="isEnabled ? 'neutral' : 'primary'"
              :variant="isEnabled ? 'subtle' : 'solid'"
              type="button"
              :loading="toggling"
              @click="isEnabled ? (confirmDisable = true) : toggleEnablement()"
            />
          </div>

          <template v-if="confirmDisable">
            <UAlert
              class="mt-3"
              color="warning"
              variant="subtle"
              icon="i-lucide-power"
              :title="enablementConfirm(true)?.title"
              :description="enablementConfirm(true)?.description"
            />
            <div class="mt-3 flex justify-end gap-2">
              <UButton
                label="Manter habilitada"
                color="neutral"
                variant="subtle"
                type="button"
                @click="confirmDisable = false"
              />
              <UButton
                :label="enablementAction(true)"
                color="warning"
                variant="solid"
                type="button"
                :loading="toggling"
                @click="toggleEnablement"
              />
            </div>
          </template>
        </UCard>

        <UAlert
          v-if="term && screen.action"
          :color="presentation.color"
          variant="subtle"
          :icon="presentation.icon"
          :title="screen.action.title"
          :description="screen.action.description"
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
              {{ screen.state }}
            </p>
          </template>
        </UCard>

        <UCard :ui="{ body: 'p-3 sm:p-4 flex flex-col gap-4' }">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
              <h2 class="text-sm font-semibold text-highlighted">
                Certificado do escritório (e-CNPJ)
              </h2>
              <!--
                Do enumerador, e não do template, pelo mesmo motivo do cabeçalho da
                tela: uma frase escrita aqui nasce fora da conferência. É a
                descrição do mecanismo — quem assina com o e-CNPJ — e o que a
                plataforma faz neste termo em particular está no texto do estado e
                no pedido, logo abaixo.
              -->
              <p class="text-xs text-muted">
                {{ screen.certificateHeader }}
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
              v-if="confirmingRemove && screen.removal"
              color="error"
              variant="subtle"
              icon="i-lucide-trash-2"
              :title="screen.removal.title"
              :description="screen.removal.description"
            />
            <div
              v-if="confirmingRemove && screen.removal"
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
            v-if="screen.notice"
            :color="screen.notice.color"
            :icon="screen.notice.icon"
            variant="subtle"
            :title="screen.notice.title"
            :description="screen.notice.description"
          />

          <!--
            O formulário aparece para quem pode escrever **sempre**, e não só
            quando há pedido: o e-CNPJ expira sozinho, e uma tela que só
            aceitasse a entrega quando algo estivesse pendente deixaria o
            escritório sem caminho para trazer o certificado novo. O que muda
            com o estado é o texto acima e o rótulo do botão.
          -->
          <p
            v-if="canWriteCertificate && screen.replacement"
            class="text-xs text-muted"
          >
            <span class="font-medium text-default">{{ screen.replacement.title }}</span> — {{ screen.replacement.description }}
          </p>

          <template v-if="canWriteCertificate">
            <UFormField
              label="Arquivo do certificado (.pfx ou .p12)"
              name="certificate"
              :help="screen.form.fileHelp"
            >
              <UFileUpload
                v-model="file"
                accept=".pfx,.p12"
                :label="screen.form.fileLabel"
                description="Arraste o arquivo ou clique para selecionar"
                class="w-full"
              />
            </UFormField>

            <UFormField
              :label="screen.form.passwordLabel"
              name="password"
              :help="screen.form.passwordHelp"
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
                :label="screen.form.submitLabel"
                icon="i-lucide-upload"
                type="button"
                :loading="submitting"
                :disabled="!canSubmit"
                @click="submitCertificate"
              />
            </div>
          </template>

          <p
            v-else-if="screen.readOnly"
            class="text-xs text-muted"
          >
            {{ screen.readOnly }}
          </p>
        </UCard>
      </template>
    </div>
  </div>
</template>
