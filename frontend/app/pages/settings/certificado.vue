<script setup lang="ts">
import type { MetaListItem } from '~/components/data-table/MetaList.vue'
import { apiErrorMessage, apiStatus } from '~/composables/useApiError'
import type { SerproAccountCertificate } from '~/types/serpro'
import { monitoringObligationUnserved, monitoringObligations } from '~/utils/monitoringNav'
import { formatMonitoringDate } from '~/utils/monitoringPresentation'
import { scheduleDayMax, scheduleDayMin, schedulePayloadFromInputs } from '~/utils/serproSchedules'
import { pageScrollClass } from '~/utils/pageShell'
import { enablementAction, enablementConfirm, enablementState } from '~/utils/serproEnablement'

/**
 * O e-CNPJ do escritório mora em Configurações, não no Painel Global e não no
 * Monitoramento — é um arquivo por Account, e quem o entrega é o `admin` da
 * Account (ou super_admin em suporte).
 * The certificate form sends only the file and password; the platform manages
 * the authorization term. A separate integration card lets a Super Admin with
 * an administrator membership enable or disable the selected office.
 *
 * `middleware: ['auth', 'account-admin']` é explícito porque o shell
 * `pages/settings.vue` é só `auth`.
 */
definePageMeta({ middleware: ['auth', 'account-admin'] })

const toast = useToast()
const { isSuperAdmin, currentRole, currentAccount } = useAuth()
const { accountCertificate, uploadAccountCertificate, removeAccountCertificate, schedules: fetchSchedules, setSchedules, enablement, setEnablement } = useSerpro()
const canSetEnablement = computed(() => isSuperAdmin.value && currentRole.value === 'admin')
const integrationEnabled = ref<boolean | null>(null)
const enablementLoading = ref(false)
const enablementError = ref(false)
const enablementSaving = ref(false)
const confirmingDisable = ref(false)
const integrationState = computed(() => integrationEnabled.value === null ? null : enablementState(integrationEnabled.value))
const disableConfirmation = computed(() => enablementConfirm(integrationEnabled.value === true))

async function loadEnablement() {
  if (enablementLoading.value || enablementSaving.value) return
  enablementLoading.value = true
  enablementError.value = false
  try {
    integrationEnabled.value = (await enablement()).enabled
  } catch {
    enablementError.value = true
  } finally {
    enablementLoading.value = false
  }
}

async function toggleEnablement(confirmed = false) {
  if (!canSetEnablement.value || integrationEnabled.value === null || enablementLoading.value || enablementError.value || enablementSaving.value) return
  if (integrationEnabled.value && !confirmed) {
    confirmingDisable.value = true
    return
  }
  enablementSaving.value = true
  try {
    integrationEnabled.value = (await setEnablement(!integrationEnabled.value)).enabled
    confirmingDisable.value = false
    toast.add({ title: integrationEnabled.value ? 'Integração habilitada' : 'Integração desabilitada', color: 'success' })
  } catch (err) {
    toast.add({ title: 'Não foi possível atualizar a integração', description: apiErrorMessage(err), color: 'error' })
  } finally {
    enablementSaving.value = false
  }
}

onMounted(() => {
  void loadEnablement()
})

/**
 * O certificado que o escritório já entregou, e `null` quando não entregou
 * nenhum — que é a resposta normal desta rota, não uma falha. Sem esta leitura a
 * tela não distingue "ainda não entregou nada" de "entregou e está guardado", e
 * são dois textos opostos: um pede a entrega, o outro mostra o que já está
 * gravado.
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
  { default: () => null, getCachedData: (key, nuxtApp) => nuxtApp.isHydrating ? nuxtApp.payload.data[key] : undefined }
)

const hasCertificate = computed(() => certificate.value !== null)

const { isLoading, showError, retry } = useRetryableLoad({
  refresh: reloadCertificate,
  error: certificateError,
  loading: computed(() => certificateStatus.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar o certificado',
  refreshErrorTitle: 'Não foi possível atualizar o certificado',
  ignoreStatus: 404
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
  if (!file.value || !password.value) return
  submitting.value = true
  try {
    await uploadAccountCertificate(file.value, password.value)
    toast.add({ title: 'Certificado do escritório entregue', color: 'success' })
    await reloadCertificate()
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
    await reloadCertificate()
  } catch (err) {
    toast.add({ title: 'Não foi possível remover o certificado', description: apiErrorMessage(err), color: 'error' })
  } finally {
    removing.value = false
  }
}

/**
 * Os agendamentos de busca automática, um dia do mês por documento servido.
 *
 * The editor is unlocked only after reading the full saved schedule: the PUT
 * replaces it, so an unread empty form must never stand in for a saved one.
 */
const servedObligations = monitoringObligations.filter(obligation => !monitoringObligationUnserved(obligation))

/** A entrada digitada, por documento; vazio é "sem agendamento". */
const scheduleInputs = reactive<Record<string, string>>(
  Object.fromEntries(servedObligations.map(obligation => [obligation.slug, '']))
)
const schedulesError = ref(false)
const schedulesLoading = ref(false)
const schedulesLoaded = ref(false)
const savingSchedules = ref(false)
const schedulesEditable = computed(() => schedulesLoaded.value && !schedulesLoading.value && !savingSchedules.value)

async function loadSchedules() {
  // Initialization is single-flight and one-time; retries cannot overwrite edits.
  if (schedulesLoading.value || schedulesLoaded.value) return
  schedulesLoading.value = true
  schedulesError.value = false
  try {
    const map = await fetchSchedules()
    for (const obligation of servedObligations) {
      const day = map?.[obligation.slug]
      scheduleInputs[obligation.slug] = day == null ? '' : String(day)
    }
    schedulesLoaded.value = true
  } catch {
    schedulesError.value = true
  } finally {
    schedulesLoading.value = false
  }
}

onMounted(() => {
  void loadSchedules()
})

async function submitSchedules() {
  if (!schedulesEditable.value) return
  const { schedules, invalid } = schedulePayloadFromInputs(scheduleInputs)
  if (invalid.length) {
    // A recusa é nomeada, não um clamp silencioso: salvar "32" como 28 diria
    // que o escritório escolheu o dia em que a busca passa, e não escolheu.
    const names = invalid.map(slug => servedObligations.find(obligation => obligation.slug === slug)?.label ?? slug)
    toast.add({
      title: 'Dia do mês inválido',
      description: `Use um dia entre ${scheduleDayMin} e ${scheduleDayMax} em: ${names.join(', ')}.`,
      color: 'error'
    })
    return
  }
  savingSchedules.value = true
  try {
    await setSchedules(schedules)
    toast.add({ title: 'Agendamentos salvos', color: 'success' })
  } catch (err) {
    toast.add({ title: 'Não foi possível salvar os agendamentos', description: apiErrorMessage(err), color: 'error' })
  } finally {
    savingSchedules.value = false
  }
}
</script>

<template>
  <div :class="pageScrollClass">
    <ErrorRetryAlert
      v-if="showError"
      title="Não foi possível carregar o certificado"
      @retry="retry"
    />

    <USkeleton v-else-if="isLoading" class="h-64 w-full rounded-xl" />

    <template v-else>
      <UPageCard
        title="Integração com o Integra Contador"
        :description="`Estado da integração de ${currentAccount?.name ?? 'este escritório'}.`"
        variant="subtle"
      >
        <ErrorRetryAlert
          v-if="enablementError"
          title="Não foi possível carregar o estado da integração"
          :loading="enablementLoading"
          @retry="loadEnablement"
        />
        <p v-else-if="enablementLoading" role="status">
          Carregando…
        </p>
        <template v-else-if="integrationState">
          <UBadge :label="integrationState.label" :color="integrationEnabled ? 'success' : 'neutral'" variant="subtle" />
          <p class="text-sm text-muted">
            {{ integrationState.description }}
          </p>
          <UButton
            v-if="canSetEnablement"
            :label="enablementAction(integrationEnabled === true)"
            color="neutral"
            variant="outline"
            :loading="enablementSaving"
            @click="toggleEnablement()"
          />
          <p v-else class="text-sm text-muted">
            A habilitação é administrada pelo Super Admin da plataforma.
          </p>
          <UAlert
            v-if="confirmingDisable && disableConfirmation"
            v-bind="disableConfirmation"
            color="warning"
            variant="subtle"
          >
            <template #actions>
              <UButton
                label="Manter habilitada"
                color="neutral"
                variant="ghost"
                :disabled="enablementSaving"
                @click="confirmingDisable = false"
              />
              <UButton
                label="Confirmar desabilitação"
                color="warning"
                :loading="enablementSaving"
                @click="toggleEnablement(true)"
              />
            </template>
          </UAlert>
        </template>
      </UPageCard>

      <UPageCard
        title="Certificado do escritório (e-CNPJ)"
        description="Arquivo .pfx ou .p12 e senha ficam cifrados; a plataforma assina o termo de autorização com este e-CNPJ."
        variant="subtle"
      >
        <div class="flex flex-col gap-4">
          <template v-if="certificate">
            <DataTableMetaList :items="certificateFacts" />

            <div class="flex flex-wrap items-center justify-between gap-2">
              <UButton
                v-if="!confirmingRemove"
                label="Remover certificado"
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
              title="Remover o certificado do escritório?"
              description="O conteúdo cifrado do e-CNPJ é apagado e fica só o metadado. O termo já assinado continua valendo, mas a plataforma não poderá emitir um novo quando ele vencer."
            />
            <div
              v-if="confirmingRemove"
              class="flex justify-end gap-2"
            >
              <UButton
                label="Manter certificado"
                color="neutral"
                variant="subtle"
                type="button"
                @click="confirmingRemove = false"
              />
              <UButton
                label="Confirmar remoção"
                color="error"
                variant="solid"
                type="button"
                :loading="removing"
                @click="confirmRemove"
              />
            </div>
          </template>

          <UFormField
            label="Arquivo do certificado (.pfx ou .p12)"
            name="certificate"
            :help="hasCertificate
              ? 'Substitui o certificado guardado: a linha anterior continua no histórico, sem o conteúdo cifrado.'
              : 'A API decide pela extensão do nome do arquivo, e não pelo conteúdo.'"
          >
            <UFileUpload
              v-model="file"
              accept=".pfx,.p12"
              :label="hasCertificate ? 'Selecionar novo arquivo' : 'Selecionar arquivo'"
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
              :label="hasCertificate ? 'Substituir certificado' : 'Entregar certificado'"
              icon="i-lucide-upload"
              type="button"
              :loading="submitting"
              :disabled="!canSubmit"
              @click="submitCertificate"
            />
          </div>
        </div>
      </UPageCard>

      <UPageCard
        title="Agendamentos do monitoramento"
        description="O dia do mês em que cada documento é buscado automaticamente pelo Integra Contador. Em branco, o documento não entra na busca automática."
        variant="subtle"
      >
        <div class="flex flex-col gap-3">
          <UAlert
            v-if="schedulesError"
            color="warning"
            variant="subtle"
            icon="i-lucide-circle-alert"
            title="Não foi possível carregar os agendamentos"
            description="Tente novamente para carregar a agenda existente antes de editar."
          >
            <template #actions>
              <UButton
                label="Tentar de novo"
                color="neutral"
                variant="ghost"
                size="sm"
                @click="loadSchedules"
              />
            </template>
          </UAlert>

          <div class="flex flex-col divide-y divide-default">
            <div
              v-for="obligation in servedObligations"
              :key="obligation.slug"
              class="flex flex-wrap items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0"
            >
              <p class="min-w-0 flex-1 truncate text-sm font-medium text-default">
                {{ obligation.label }}
              </p>
              <UInput
                v-model="scheduleInputs[obligation.slug]"
                type="number"
                :min="scheduleDayMin"
                :max="scheduleDayMax"
                placeholder="Sem agendamento"
                aria-label="Dia do mês"
                class="w-40"
                :disabled="!schedulesEditable"
              />
            </div>
          </div>
        </div>

        <template #footer>
          <div class="flex w-full justify-end">
            <UButton
              label="Salvar agendamentos"
              type="button"
              :loading="savingSchedules"
              :disabled="!schedulesEditable"
              @click="submitSchedules"
            />
          </div>
        </template>
      </UPageCard>
    </template>
  </div>
</template>
