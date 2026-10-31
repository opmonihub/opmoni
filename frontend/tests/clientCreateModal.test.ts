// tests/clientCreateModal.test.ts
//
// O arquivo `.vue` não é importável pelo runner do Node. O script é lido e
// executado com Vue e serviços locais; o template mantém guardas de fonte.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { stripTypeScriptTypes } from 'node:module'
import { describe, it } from 'node:test'
import { setImmediate } from 'node:timers/promises'
import { runInNewContext } from 'node:vm'
import { computed, effectScope, nextTick, reactive, ref, shallowRef, watch } from 'vue'
import type { Ref } from 'vue'
import * as z from 'zod'
import type { Client, ClientWritePayload, CnpjPreview } from '../app/types/client.ts'
import type { ClientMonitoringModules } from '../app/types/serpro.ts'
import { apiErrorMessage, apiFieldErrors, apiStatus } from '../app/composables/useApiError.ts'
import { companyTaxIdEntry, formatTaxId, taxIdEditInvalidatesLookup } from '../app/utils/taxId.ts'
import { deadlinePresentation } from '../app/utils/portfolioLabels.ts'
import { initialMonitoringModuleSelection, monitoringModuleGroups, monitoringModulesPayload } from '../app/utils/monitoringModules.ts'

function modalSource(): string {
  return readFileSync(new URL('../app/components/customers/ClientCreateModal.vue', import.meta.url), 'utf8')
}

function fieldsetByLegend(template: string, legend: string): string {
  const found = new RegExp(`>\\s*${legend}\\s*<`).exec(template)
  assert.ok(found, legend)
  const open = template.lastIndexOf('<fieldset', found.index)
  assert.notEqual(open, -1, legend)
  const tags = /<\/?fieldset\b[^>]*>/g
  tags.lastIndex = open
  let depth = 0
  for (let match = tags.exec(template); match; match = tags.exec(template)) {
    depth += match[0].startsWith('</') ? -1 : 1
    if (depth === 0) return template.slice(open, tags.lastIndex)
  }
  assert.fail(`fieldset sem fechamento: ${legend}`)
}

const savedClient = { id: 7, person_type: 'company' } as Client
const availableModules: ClientMonitoringModules = {
  regime: 'simple_national',
  obligations: [{ slug: 'declaracoes/pgdas', label: 'PGDAS', category: 'direct', suggested: true, associated: false }]
}

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((done, fail) => {
    resolve = done
    reject = fail
  })
  return { promise, resolve, reject }
}

const companyPreview: CnpjPreview = {
  tax_id: '27865757000102',
  name: 'Empresa da Receita',
  trade_name: 'Empresa',
  registration_status: 'Ativa',
  registration_status_date: '2026-01-01',
  opened_at: '2020-01-01',
  company_size: 'Demais',
  legal_nature: 'Sociedade limitada',
  primary_activity_code: '6920601',
  primary_activity_description: 'Atividades de contabilidade',
  street_type: 'Rua',
  street: 'Rua Oficial',
  address_number: '10',
  address_complement: null,
  district: 'Centro',
  postal_code: '01001000',
  city: 'São Paulo',
  state: 'SP',
  email: 'receita@example.com',
  phone: '1133334444',
  mei: false,
  simple_national: false,
  source_updated_at: '2026-01-01T00:00:00Z',
  looked_up_at: '2026-10-13T00:00:00Z'
}

interface ModalState {
  person_type: 'company' | 'individual'
  tax_id: string
  name: string
  status: 'active' | 'inactive'
  tax_regime: string
  street_type: string
  street: string
  address_number: string
  address_complement: string
  district: string
  postal_code: string
  city: string
  state: string
}

function modalHarness(options: {
  create?: (payload: ClientWritePayload) => Promise<Client>
  lookupCnpj?: (taxId: string) => Promise<CnpjPreview>
  monitoringModules?: () => Promise<ClientMonitoringModules>
  confirmMonitoringModules?: () => Promise<unknown>
  createDispatchContact?: (clientId: number, payload: { label?: string | null, channel: 'email' | 'whatsapp', value: string }) => Promise<unknown>
  canManageClients?: boolean
} = {}) {
  // Executa o script real com serviços locais, sem importar o arquivo .vue
  // nem montar a interface. Os watchers usam a reatividade normal do Vue.
  const script = modalSource().match(/<script setup lang="ts">([\s\S]*?)<\/script>/)?.[1]
  assert.ok(script)
  const code = stripTypeScriptTypes(script.replace(/^import[\s\S]*?from ['"][^'"]+['"]\r?\n/gm, ''))
  const props = reactive({ open: true })
  const events: Array<{ name: string, value: unknown }> = []
  const toasts: Array<{ title: string, description?: string }> = []
  const serverErrors: Array<{ name: string, message: string }> = []
  const focusEvents: string[] = []
  const form = ref({
    setErrors: (errors: Array<{ name: string, message: string }>) => serverErrors.splice(0, serverErrors.length, ...errors),
    clear: () => serverErrors.splice(0)
  })
  const scope = effectScope()
  const modal = scope.run(() => runInNewContext(`${code}\n({ isOpen, state, schema, preview, activities, addActivity, removeActivity, contacts, contactChannel, addContact, removeContact, canLookup, regimeLocked, regimeOptions, onLookup, lookingUp, onValidationError, onSubmit, finishSaved, confirmModules, modules, modulesLoading, modulesError, modulesSubmitting, submitting, registrationSteps, registrationProgress })`, {
    z, computed, reactive, ref, shallowRef, watch,
    useTemplateRef: () => form,
    document: { getElementById: (id: string) => ({
      focus: () => focusEvents.push(`focus:${id}`),
      scrollIntoView: () => focusEvents.push(`scroll:${id}`)
    }) },
    apiErrorMessage, apiFieldErrors, apiStatus,
    companyTaxIdEntry, formatTaxId, taxIdEditInvalidatesLookup, deadlinePresentation,
    initialMonitoringModuleSelection, monitoringModuleGroups, monitoringModulesPayload,
    defineOptions: () => {},
    defineProps: () => props,
    defineEmits: () => (name: string, value: unknown) => {
      events.push({ name, value })
      if (name === 'update:open') props.open = value as boolean
    },
    useClients: () => ({
      create: options.create ?? (() => Promise.resolve(savedClient)),
      monitoringModules: options.monitoringModules ?? (() => Promise.resolve(availableModules)),
      confirmMonitoringModules: options.confirmMonitoringModules ?? (() => Promise.resolve({})),
      lookupCnpj: options.lookupCnpj ?? (() => Promise.reject(new Error('Consulta não esperada')))
    }),
    useAuth: () => ({ canManageClients: ref(options.canManageClients ?? true) }),
    useDispatchContacts: () => ({
      create: options.createDispatchContact ?? (() => Promise.resolve({ id: 1 }))
    }),
    useToast: () => ({ add: (value: { title: string, description?: string }) => toasts.push(value) })
  })) as {
    isOpen: Ref<boolean>
    state: ModalState
    schema: z.ZodType<ClientWritePayload>
    preview: Ref<CnpjPreview | null>
    activities: Ref<Array<{ key: number, code: string, description: string }>>
    addActivity: () => void
    removeActivity: (key: number) => void
    contacts: Ref<Array<{ key: number, channel: 'email' | 'whatsapp', name: string, value: string }>>
    contactChannel: Ref<'email' | 'whatsapp'>
    addContact: () => void
    removeContact: (key: number) => void
    canLookup: Ref<boolean>
    regimeLocked: Ref<string | null>
    regimeOptions: Ref<Array<{ label: string, value: string }>>
    onLookup: () => Promise<void>
    lookingUp: Ref<boolean>
    onValidationError: (event: { errors: Array<{ id?: string }> }) => void
    onSubmit: (event: { data: object }) => Promise<void>
    finishSaved: () => void
    confirmModules: () => Promise<void>
    modules: Ref<ClientMonitoringModules | null>
    modulesLoading: Ref<boolean>
    modulesError: Ref<boolean>
    modulesSubmitting: Ref<boolean>
    submitting: Ref<boolean>
    registrationSteps: Ref<Array<{ id: string, done: boolean, statusLabel: string }>>
    registrationProgress: Ref<number>
  }
  return { modal, props, toasts, serverErrors, focusEvents, saved: () => events.filter(event => event.name === 'saved'), dispose: () => scope.stop() }
}

describe('cadastro completo desde a abertura', () => {
  it('mantém identificação e cadastro no mesmo formulário sem etapa de consulta', () => {
    const source = modalSource()
    const template = source.slice(source.indexOf('<template>'))
    const form = template.match(/<UForm\b[\s\S]*?<\/UForm>/)?.[0]
    assert.ok(form)
    for (const name of ['person_type', 'tax_id', 'name', 'status', 'tax_regime']) {
      assert.match(form, new RegExp(`name="${name}"`))
    }
    assert.doesNotMatch(form, /name="email"|name="phone"/)
    assert.doesNotMatch(form, /hint="Opcional"/)
    assert.doesNotMatch(form, /<legend[^>]*>\s*Contato\s*<\/legend>/)
    assert.match(form, />\s*Contatos\s*</)
    assert.match(form, /<UTabs[\s\S]*variant="link"/)
    assert.match(source, /label: 'E-mail'/)
    assert.match(source, /label: 'WhatsApp'/)
    assert.match(form, /:label="addContactLabel"/)
    assert.match(source, /'Novo contato de e-mail'/)
    assert.match(source, /'Novo contato de WhatsApp'/)
    assert.doesNotMatch(form, /label="Adicionar contato"/)
    assert.match(form, /Remover contato \$\{contactLineName\(row, index\)\}/)
    for (const copy of [
      'Pode preencher sem consultar aqui',
      'Dados oficiais da empresa',
      'Endereço, nome fantasia, situação na Receita',
      'Nenhuma consulta exibida',
      'Letras e números. A consulta pública está disponível somente para CNPJ numérico.',
      'MEI e Simples Nacional são confirmados pela consulta.',
      'Preencha o cadastro. A consulta de CNPJ ajuda a conferir os dados oficiais.',
      'CNPJ alfanumérico: informe a razão social',
      'Somente números; pode informar com pontuação.',
      'Razão social confirmada pela fonte pública',
      'Definido pela Receita (MEI/Simples).'
    ]) {
      assert.equal(source.includes(copy), false, copy)
    }
    assert.match(form, /label="Consultar CNPJ"/)
    assert.match(source, /sm:max-w-4xl/)
    assert.doesNotMatch(source, /max-w-3xl/)
    assert.match(form, /grid grid-cols-1 items-start gap-6 sm:grid-cols-2/)
    assert.match(form, /<dl v-if="preview"[\s\S]*Nome fantasia[\s\S]*Situação na Receita[\s\S]*Endereço/)
    assert.match(form, /grid grid-cols-1 gap-4 sm:grid-cols-2/)
    assert.doesNotMatch(form, /Atividade principal/)
    assert.match(form, /<UTable/)
    assert.doesNotMatch(form, /<table\b/)
    assert.match(source, /header: 'Código'/)
    assert.match(source, /header: 'Descrição'/)
    assert.match(source, /header: 'Ações'/)
    assert.match(form, /#code-cell/)
    assert.match(form, /#description-cell/)
    assert.match(form, /#actions-cell/)
    assert.match(form, /label="Adicionar atividade"/)
    assert.match(form, /Remover atividade \$\{activityLineName\(row\.original, activityIndex\(row\.original\)\)\}/)
    assert.doesNotMatch(template, /step === [12]|Cadastrar sem consulta|label="Voltar"/)
    const button = template.match(/<UButton\b[^>]*label="Cadastrar cliente"[^>]*>/)?.[0]
    assert.ok(button)
    assert.doesNotMatch(button, /v-if=/)
    assert.match(button, /form="client-create-form"/)
    assert.match(source, /body: '[^']*min-h-0[^']*overflow-y-auto/)
    assert.match(source, /footer: '[^']*shrink-0/)
  })

  it('exige razão social já na abertura sem depender de uma escolha manual', (t) => {
    const harness = modalHarness()
    t.after(harness.dispose)
    harness.modal.state.tax_id = '27865757000102'
    const result = harness.modal.schema.safeParse(harness.modal.state)
    assert.equal(result.success, false)
    if (!result.success) assert.ok(result.error.issues.some(issue => issue.path[0] === 'name'))
  })

  it('descarta endereço de PF e campos oficiais do payload de empresa', (t) => {
    const harness = modalHarness()
    t.after(harness.dispose)
    const parsed = harness.modal.schema.parse({
      ...harness.modal.state, tax_id: '12.ABC.345/0001-88', name: 'Empresa Alfa',
      street: 'Endereço anterior', postal_code: '01001-000', email: 'contato@empresa.com', phone: '11999998888',
      trade_name: 'Nome não confiável', registration_status: 'Ativa', primary_activity_code: '123', mei: true
    })
    for (const field of ['street', 'postal_code', 'email', 'phone', 'trade_name', 'registration_status', 'primary_activity_code', 'mei']) {
      assert.equal(field in parsed, false)
    }
    assert.equal(parsed.tax_id, '12.ABC.345/0001-88')
  })

  it('pessoa física mantém endereço opcional e regime não aplicável', async (t) => {
    const harness = modalHarness()
    t.after(harness.dispose)
    harness.modal.state.person_type = 'individual'
    await nextTick()
    Object.assign(harness.modal.state, {
      tax_id: '529.982.247-25', name: 'Pessoa Teste', street: 'Rua Manual', postal_code: '01001-000', state: 'SP'
    })
    const parsed = harness.modal.schema.parse(harness.modal.state)
    assert.equal(parsed.tax_regime, 'not_applicable')
    assert.equal(parsed.street, 'Rua Manual')
    assert.equal(parsed.postal_code, '01001-000')
    assert.equal(harness.modal.canLookup.value, false)
  })
})

describe('layout do cadastro em duas colunas', () => {
  function registrationForm() {
    const source = modalSource()
    const template = source.slice(source.indexOf('<template>'))
    const form = template.match(/<UForm\b[\s\S]*?<\/UForm>/)?.[0]
    assert.ok(form)
    return { source, form }
  }

  it('troca o tipo de pessoa por um controle segmentado ligado ao estado', () => {
    const { source, form } = registrationForm()
    const cadastro = fieldsetByLegend(form, 'Cadastro')

    assert.match(source, /label: 'Pessoa jurídica', value: 'company'/)
    assert.match(source, /label: 'Pessoa física', value: 'individual'/)
    assert.match(cadastro, /<URadioGroup\b[\s\S]*v-model="state\.person_type"[\s\S]*name="person_type"[\s\S]*legend="Tipo de pessoa"[\s\S]*orientation="horizontal"[\s\S]*variant="table"[\s\S]*indicator="hidden"/)
    assert.match(cadastro, /has-data-\[state=checked\]:bg-primary/)
    assert.match(cadastro, /state\.person_type === item\.value \? 'text-inverted'/)
    assert.doesNotMatch(form, /<USelect\b[\s\S]*?v-model="state\.person_type"/)
    assert.doesNotMatch(source, /Pessoa jurídica \(CNPJ\)|Pessoa física \(CPF\)/)
  })

  it('mostra a contagem real do canal e o botão do estado vazio', () => {
    const { source, form } = registrationForm()
    const contatos = fieldsetByLegend(form, 'Contatos')

    assert.match(source, /function contactCount\(channel: 'email' \| 'whatsapp'\)[\s\S]*contacts\.value\.filter\(row => row\.channel === channel\)\.length/)
    assert.match(source, /label: 'E-mail'[\s\S]*badge: contactCount\('email'\)/)
    assert.match(source, /label: 'WhatsApp'[\s\S]*badge: contactCount\('whatsapp'\)/)
    assert.doesNotMatch(source, /0\/5/)
    assert.match(contatos, /v-if="visibleContacts\.length === 0"[\s\S]*rounded-lg bg-elevated\/50[\s\S]*:label="addContactLabel"/)
    assert.match(contatos, /<template v-else>[\s\S]*:label="addContactLabel"[\s\S]*in visibleContacts/)
    assert.match(source, /contactChannel\.value === 'email'[\s\S]*'Novo contato de e-mail'[\s\S]*'Novo contato de WhatsApp'/)
  })

  it('mantém o endereço da pessoa física no cadastro e as atividades fora dos contatos', () => {
    const { form } = registrationForm()
    const cadastro = fieldsetByLegend(form, 'Cadastro')
    const contatos = fieldsetByLegend(form, 'Contatos')
    const activitiesAt = form.indexOf('client-create-activities-title')

    assert.match(cadastro, /Tipo de logradouro/)
    assert.doesNotMatch(contatos, /Tipo de logradouro/)
    assert.doesNotMatch(cadastro, /<UTable/)
    assert.doesNotMatch(cadastro, /Adicionar atividade/)
    assert.doesNotMatch(contatos, /<UTable/)
    assert.doesNotMatch(contatos, /Adicionar atividade/)
    assert.doesNotMatch(contatos, /client-create-activities-title/)
    assert.ok(activitiesAt > form.indexOf(contatos) + contatos.length)
    for (const forbidden of ['Identificação interna', 'Inscrição estadual', 'Inscrição municipal', 'Honorário', 'Certificado A1']) {
      assert.equal(form.includes(forbidden), false, forbidden)
    }
  })

  it('descreve o cadastro sem alterar a etapa de obrigações', () => {
    const source = modalSource()

    assert.match(source, /\? 'Cliente cadastrado com sucesso\.' : 'Novo cliente'/)
    assert.match(source, /step === 'modules' \? registrationSummary : 'Cadastre o cliente na carteira do escritório\.'/)
    assert.match(source, /label="Cancelar"/)
    assert.match(source, /label="Cadastrar cliente"/)
  })
})

describe('consulta auxiliar sem perda de trabalho', () => {
  it('preserva a escolha de regime não oficial durante a consulta', async (t) => {
    const harness = modalHarness({ lookupCnpj: () => Promise.resolve(companyPreview) })
    t.after(harness.dispose)
    Object.assign(harness.modal.state, {
      tax_id: companyPreview.tax_id, tax_regime: 'actual_profit'
    })
    await nextTick()
    await harness.modal.onLookup()
    assert.equal(harness.modal.state.tax_regime, 'actual_profit')
  })

  it('consulta preenche a primeira atividade e o cadastro não envia linhas extras', async (t) => {
    const harness = modalHarness({ lookupCnpj: () => Promise.resolve(companyPreview) })
    t.after(harness.dispose)
    harness.modal.state.tax_id = companyPreview.tax_id
    await nextTick()
    await harness.modal.onLookup()
    assert.equal(harness.modal.activities.value.length, 1)
    assert.equal(harness.modal.activities.value[0]?.code, companyPreview.primary_activity_code)
    assert.equal(harness.modal.activities.value[0]?.description, companyPreview.primary_activity_description)
    harness.modal.addActivity()
    const extra = harness.modal.activities.value[1]
    assert.ok(extra)
    assert.equal(extra.code, '')
    assert.equal(extra.description, '')
    extra.code = '1234567'
    extra.description = 'Atividade digitada'
    harness.modal.addActivity()
    const parsed = harness.modal.schema.parse({ ...harness.modal.state, name: 'Empresa' })
    assert.equal('primary_activity_code' in parsed, false)
    assert.equal('primary_activity_description' in parsed, false)
    assert.equal('activities' in parsed, false)
    harness.modal.removeActivity(extra.key)
    assert.equal(harness.modal.activities.value.some(row => row.key === extra.key), false)
    assert.equal(harness.modal.activities.value.filter(row => row.code === '' && row.description === '').length, 1)
  })

  it('grava contatos preenchidos depois do cliente e ignora linha vazia', async (t) => {
    const created: ClientWritePayload[] = []
    const posted: Array<{ label?: string | null, channel: string, value: string }> = []
    const harness = modalHarness({
      create: (payload) => {
        created.push(payload)
        return Promise.resolve(savedClient)
      },
      createDispatchContact: async (_clientId, payload) => {
        posted.push(payload)
        return {}
      }
    })
    t.after(harness.dispose)
    harness.modal.addContact()
    Object.assign(harness.modal.contacts.value[0]!, { name: 'Maria Souza', value: 'Maria@Empresa.com' })
    harness.modal.addContact()
    harness.modal.contactChannel.value = 'whatsapp'
    harness.modal.addContact()
    Object.assign(harness.modal.contacts.value[2]!, { name: 'João', value: '(11) 98765-4321' })
    await harness.modal.onSubmit({ data: { person_type: 'company', name: 'Empresa' } })
    assert.equal(created.length, 1)
    assert.equal('contacts' in created[0]!, false)
    assert.equal('email' in created[0]!, false)
    assert.deepEqual(posted.map(item => ({ label: item.label, channel: item.channel, value: item.value })), [
      { label: 'Maria Souza', channel: 'email', value: 'maria@empresa.com' },
      { label: 'João', channel: 'whatsapp', value: '11987654321' }
    ])
    assert.equal(harness.props.open, true)
  })

  it('não cadastra o cliente quando um contato está pela metade', async (t) => {
    let calls = 0
    const harness = modalHarness({
      create: () => {
        calls += 1
        return Promise.resolve(savedClient)
      }
    })
    t.after(harness.dispose)
    harness.modal.addContact()
    harness.modal.contacts.value[0]!.name = 'Maria'
    await harness.modal.onSubmit({ data: { person_type: 'company' } })
    assert.equal(calls, 0)
    assert.match(harness.toasts.at(-1)?.description ?? '', /nome e o e-mail/)
    harness.modal.contacts.value[0]!.value = 'sem-arroba'
    await harness.modal.onSubmit({ data: { person_type: 'company' } })
    assert.equal(calls, 0)
    assert.match(harness.toasts.at(-1)?.description ?? '', /e-mail informado não é válido/)
  })

  it('avisa quando o contato falha depois do cliente cadastrado', async (t) => {
    let calls = 0
    let posts = 0
    const harness = modalHarness({
      create: () => {
        calls += 1
        return Promise.resolve(savedClient)
      },
      createDispatchContact: () => {
        posts += 1
        return Promise.reject({ data: { message: 'Contato recusado.' } })
      }
    })
    t.after(harness.dispose)
    harness.modal.addContact()
    Object.assign(harness.modal.contacts.value[0]!, { name: 'Maria', value: 'maria@empresa.com' })
    harness.modal.addContact()
    Object.assign(harness.modal.contacts.value[1]!, { name: 'Ana', value: 'ana@empresa.com' })
    await harness.modal.onSubmit({ data: { person_type: 'company' } })
    assert.equal(calls, 1)
    assert.equal(posts, 1)
    assert.equal(harness.props.open, true)
    assert.ok(harness.toasts.some(item => item.title === 'Cliente cadastrado'))
    assert.ok(harness.toasts.some(item => item.description === 'Contato recusado.'))
    assert.equal(harness.modal.modules.value?.regime, 'simple_national')
  })

  it('mostra o nome oficial sem substituir a razão social manual', async (t) => {
    const harness = modalHarness({ lookupCnpj: () => Promise.resolve({ ...companyPreview, mei: true }) })
    t.after(harness.dispose)
    harness.modal.state.tax_id = companyPreview.tax_id
    harness.modal.state.name = 'Nome digitado'
    await nextTick()
    await harness.modal.onLookup()
    assert.equal(harness.modal.state.name, 'Nome digitado')
    assert.equal(harness.modal.preview.value?.name, companyPreview.name)
    assert.equal(harness.modal.regimeLocked.value, 'mei')
    assert.equal(harness.modal.state.tax_regime, 'mei')
  })

  it('invalida a consulta ao mudar o CNPJ e preserva a razão social manual', async (t) => {
    const harness = modalHarness({ lookupCnpj: () => Promise.resolve({ ...companyPreview, simple_national: true }) })
    t.after(harness.dispose)
    harness.modal.state.tax_id = companyPreview.tax_id
    harness.modal.state.name = 'Nome manual preservado'
    await nextTick()
    await harness.modal.onLookup()
    harness.modal.state.tax_id = '12.ABC.345/0001-88'
    await nextTick()
    assert.equal(harness.modal.preview.value, null)
    assert.equal(harness.modal.activities.value.length, 0)
    assert.equal(harness.modal.state.name, 'Nome manual preservado')
    assert.equal(harness.modal.state.tax_regime, 'presumed_profit')
    assert.equal(harness.modal.regimeLocked.value, null)
  })

  it('reformatar o mesmo CNPJ não invalida a consulta', async (t) => {
    const request = deferred<CnpjPreview>()
    const harness = modalHarness({ lookupCnpj: () => request.promise })
    t.after(harness.dispose)
    harness.modal.state.tax_id = companyPreview.tax_id
    await nextTick()
    const lookup = harness.modal.onLookup()
    harness.modal.state.tax_id = '27.865.757/0001-02'
    request.resolve(companyPreview)
    await lookup
    assert.equal(harness.modal.preview.value?.tax_id, companyPreview.tax_id)
  })

  it('não chama a fonte pública para CNPJ alfanumérico', async (t) => {
    let calls = 0
    const harness = modalHarness({ lookupCnpj: () => {
      calls++
      return Promise.resolve(companyPreview)
    } })
    t.after(harness.dispose)
    harness.modal.state.tax_id = '12.ABC.345/0001-88'
    await nextTick()
    await harness.modal.onLookup()
    assert.equal(harness.modal.canLookup.value, false)
    assert.equal(calls, 0)
    assert.equal(harness.modal.preview.value, null)
  })

  for (const change of ['documento', 'tipo de pessoa', 'fechamento e reabertura'] as const) {
    it(`ignora a resposta antiga depois de ${change}`, async (t) => {
      const request = deferred<CnpjPreview>()
      const harness = modalHarness({ lookupCnpj: () => request.promise })
      t.after(harness.dispose)
      harness.modal.state.tax_id = companyPreview.tax_id
      await nextTick()
      const lookup = harness.modal.onLookup()
      if (change === 'documento') harness.modal.state.tax_id = '12.ABC.345/0001-88'
      else if (change === 'tipo de pessoa') harness.modal.state.person_type = 'individual'
      else {
        harness.props.open = false
        await nextTick()
        harness.props.open = true
      }
      await nextTick()
      request.resolve({ ...companyPreview, mei: true })
      await lookup
      assert.equal(harness.modal.preview.value, null)
      assert.equal(harness.modal.lookingUp.value, false)
      assert.equal(harness.toasts.length, 0)
    })
  }

  it('resposta antiga não encerra o carregamento da consulta que a substituiu', async (t) => {
    const first = deferred<CnpjPreview>()
    const second = deferred<CnpjPreview>()
    let calls = 0
    const harness = modalHarness({ lookupCnpj: () => ++calls === 1 ? first.promise : second.promise })
    t.after(harness.dispose)
    harness.modal.state.tax_id = companyPreview.tax_id
    await nextTick()
    const oldLookup = harness.modal.onLookup()
    const latestLookup = harness.modal.onLookup()
    first.resolve({ ...companyPreview, name: 'Resposta antiga' })
    await oldLookup
    assert.equal(harness.modal.preview.value, null)
    assert.equal(harness.modal.lookingUp.value, true)
    second.resolve({ ...companyPreview, name: 'Resposta atual' })
    await latestLookup
    assert.equal(harness.modal.preview.value?.name, 'Resposta atual')
    assert.equal(harness.modal.lookingUp.value, false)
    assert.equal(harness.toasts.length, 1)
  })

  it('mantém o cadastro manual e informa a indisponibilidade específica', async (t) => {
    const harness = modalHarness({ lookupCnpj: () => Promise.reject({ statusCode: 503, data: { message: 'Serviço indisponível.' } }) })
    t.after(harness.dispose)
    Object.assign(harness.modal.state, { tax_id: companyPreview.tax_id, name: 'Empresa manual' })
    await nextTick()
    await harness.modal.onLookup()
    assert.equal(harness.modal.state.name, 'Empresa manual')
    assert.match(harness.toasts[0]?.description ?? '', /Serviço indisponível/)
    assert.equal(harness.modal.lookingUp.value, false)
  })
})

describe('recusas do servidor mantêm o formulário', () => {
  it('mostra duplicidade no documento sem perder os valores digitados', async (t) => {
    const harness = modalHarness({ create: () => Promise.reject({ data: { errors: { tax_id: ['Documento já cadastrado.'] } } }) })
    t.after(harness.dispose)
    Object.assign(harness.modal.state, { tax_id: companyPreview.tax_id, name: 'Empresa manual' })
    await harness.modal.onSubmit({ data: {} })
    assert.equal(harness.props.open, true)
    assert.equal(harness.modal.state.name, 'Empresa manual')
    assert.equal(harness.serverErrors.length, 1)
    assert.equal(harness.serverErrors[0]?.name, 'tax_id')
    assert.equal(harness.serverErrors[0]?.message, 'Documento já cadastrado.')
    assert.equal(harness.saved().length, 0)
  })
})

describe('guardas do cadastro e acessibilidade', () => {
  it('mantém obrigatórios e opcionais corretos antes da consulta', (t) => {
    const harness = modalHarness()
    t.after(harness.dispose)
    const result = harness.modal.schema.safeParse(harness.modal.state)
    assert.equal(result.success, false)
    if (!result.success) {
      assert.ok(result.error.issues.some(issue => issue.path[0] === 'tax_id'))
      assert.ok(result.error.issues.some(issue => issue.path[0] === 'name'))
    }
    const parsed = harness.modal.schema.parse({ ...harness.modal.state, tax_id: '27865757000102', name: 'Empresa' })
    assert.equal('email' in parsed, false)
    assert.equal('phone' in parsed, false)
  })

  it('leva o foco ao primeiro erro dentro do corpo rolável', (t) => {
    const harness = modalHarness()
    t.after(harness.dispose)
    harness.modal.onValidationError({ errors: [{ id: 'tax-id' }, { id: 'name' }] })
    assert.deepEqual(harness.focusEvents, ['focus:tax-id', 'scroll:tax-id'])
    harness.modal.onValidationError({ errors: [] })
    assert.equal(harness.focusEvents.length, 2)
  })

  it('não aceita uma consulta de outro CNPJ mesmo sem outra requisição da tela', async (t) => {
    const harness = modalHarness({ lookupCnpj: () => Promise.resolve({ ...companyPreview, tax_id: '04252011000110' }) })
    t.after(harness.dispose)
    harness.modal.state.tax_id = companyPreview.tax_id
    await harness.modal.onLookup()
    assert.equal(harness.modal.preview.value, null)
    assert.equal(harness.modal.lookingUp.value, false)
    assert.equal(harness.toasts.length, 0)
  })

  it('ignora falha antiga quando uma nova consulta está carregando', async (t) => {
    const first = deferred<CnpjPreview>()
    const second = deferred<CnpjPreview>()
    let calls = 0
    const harness = modalHarness({ lookupCnpj: () => ++calls === 1 ? first.promise : second.promise })
    t.after(harness.dispose)
    harness.modal.state.tax_id = companyPreview.tax_id
    const oldLookup = harness.modal.onLookup()
    const latestLookup = harness.modal.onLookup()
    first.reject({ statusCode: 503, data: { message: 'Falha antiga' } })
    await oldLookup
    assert.equal(harness.toasts.length, 0)
    assert.equal(harness.modal.lookingUp.value, true)
    second.resolve(companyPreview)
    await latestLookup
    assert.equal(harness.modal.preview.value?.name, companyPreview.name)
  })

  it('impede submissão duplicada enquanto o cadastro está pendente', async (t) => {
    const request = deferred<Client>()
    let calls = 0
    const harness = modalHarness({ create: () => {
      calls++
      return request.promise
    } })
    t.after(harness.dispose)
    const first = harness.modal.onSubmit({ data: {} })
    await harness.modal.onSubmit({ data: {} })
    assert.equal(calls, 1)
    request.resolve(savedClient)
    await first
  })

  it('não cadastra durante consulta pendente e não modifica seu carregamento', async (t) => {
    const request = deferred<CnpjPreview>()
    let calls = 0
    const harness = modalHarness({
      lookupCnpj: () => request.promise,
      create: () => {
        calls++
        return Promise.resolve(savedClient)
      }
    })
    t.after(harness.dispose)
    harness.modal.state.tax_id = companyPreview.tax_id
    const lookup = harness.modal.onLookup()
    await harness.modal.onSubmit({ data: {} })
    assert.equal(calls, 0)
    assert.equal(harness.modal.lookingUp.value, true)
    request.resolve(companyPreview)
    await lookup
  })

  it('empresa não administrável encerra sem carregar obrigações', async (t) => {
    let calls = 0
    const harness = modalHarness({ canManageClients: false, monitoringModules: () => {
      calls++
      return Promise.resolve(availableModules)
    } })
    t.after(harness.dispose)
    await harness.modal.onSubmit({ data: {} })
    assert.equal(calls, 0)
    assert.equal(harness.props.open, false)
    assert.equal(harness.saved().length, 1)
  })

  it('PF para PJ não envia endereço ou regime não aplicável', async (t) => {
    const harness = modalHarness()
    t.after(harness.dispose)
    harness.modal.state.person_type = 'individual'
    harness.modal.state.street = 'Rua da pessoa'
    harness.modal.state.person_type = 'company'
    Object.assign(harness.modal.state, { tax_id: '12ABC345000188', name: 'Empresa' })
    const parsed = harness.modal.schema.parse(harness.modal.state)
    assert.equal(parsed.tax_regime, 'presumed_profit')
    assert.equal('street' in parsed, false)
  })
})

describe('fechamento depois de cadastrar o cliente', () => {
  it('emite saved uma vez pelo fechamento do modal durante o carregamento dos módulos', async (t) => {
    const request = deferred<ClientMonitoringModules>()
    const harness = modalHarness({ monitoringModules: () => request.promise })
    t.after(harness.dispose)
    const submitting = harness.modal.onSubmit({ data: {} })
    await setImmediate()
    assert.equal(harness.modal.modulesLoading.value, true)

    harness.modal.isOpen.value = false
    await nextTick()
    assert.deepEqual(harness.saved(), [{ name: 'saved', value: savedClient }])

    harness.props.open = true
    await nextTick()
    request.resolve(availableModules)
    await submitting
    assert.equal(harness.modal.modules.value, null)
    assert.equal(harness.modal.modulesLoading.value, false)
    assert.equal(harness.modal.submitting.value, false)
    assert.equal(harness.props.open, true)
    assert.equal(harness.saved().length, 1)
  })

  it('emite saved quando o pai fecha o modal e não duplica ao concluir', async (t) => {
    const harness = modalHarness()
    t.after(harness.dispose)
    await harness.modal.onSubmit({ data: {} })

    harness.props.open = false
    await nextTick()
    harness.modal.finishSaved()
    await nextTick()
    assert.deepEqual(harness.saved(), [{ name: 'saved', value: savedClient }])
  })

  it('confirmar as obrigações mantém uma única emissão de saved', async (t) => {
    const harness = modalHarness()
    t.after(harness.dispose)
    await harness.modal.onSubmit({ data: {} })
    await harness.modal.confirmModules()
    await nextTick()

    assert.equal(harness.props.open, false)
    assert.deepEqual(harness.saved(), [{ name: 'saved', value: savedClient }])
  })

  it('concluir sem associar mantém uma única emissão de saved', async (t) => {
    const harness = modalHarness()
    t.after(harness.dispose)
    await harness.modal.onSubmit({ data: {} })
    harness.modal.finishSaved()
    await nextTick()

    assert.equal(harness.props.open, false)
    assert.deepEqual(harness.saved(), [{ name: 'saved', value: savedClient }])
  })

  it('pessoa física encerra o cadastro sem duplicar saved', async (t) => {
    const individual = { ...savedClient, person_type: 'individual' } as Client
    const harness = modalHarness({ create: () => Promise.resolve(individual) })
    t.after(harness.dispose)
    await harness.modal.onSubmit({ data: {} })
    await nextTick()

    assert.equal(harness.props.open, false)
    assert.deepEqual(harness.saved(), [{ name: 'saved', value: individual }])
  })

  it('ignora a confirmação antiga depois de fechar e reabrir o modal', async (t) => {
    const request = deferred<unknown>()
    const harness = modalHarness({ confirmMonitoringModules: () => request.promise })
    t.after(harness.dispose)
    await harness.modal.onSubmit({ data: {} })
    const confirming = harness.modal.confirmModules()
    harness.modal.isOpen.value = false
    await nextTick()
    harness.props.open = true
    await nextTick()

    request.resolve({})
    await confirming
    assert.equal(harness.props.open, true)
    assert.equal(harness.modal.modulesError.value, false)
    assert.equal(harness.modal.modulesSubmitting.value, false)
    assert.deepEqual(harness.saved(), [{ name: 'saved', value: savedClient }])
  })

  it('fechar antes de salvar não emite saved', async (t) => {
    const harness = modalHarness()
    t.after(harness.dispose)
    harness.modal.isOpen.value = false
    await nextTick()
    assert.equal(harness.saved().length, 0)
  })

  it('notifica o cadastro que termina depois do fechamento sem reabrir o modal', async (t) => {
    const request = deferred<Client>()
    const harness = modalHarness({ create: () => request.promise })
    t.after(harness.dispose)
    const submitting = harness.modal.onSubmit({ data: {} })
    harness.modal.isOpen.value = false
    await nextTick()
    request.resolve(savedClient)
    await submitting

    assert.equal(harness.props.open, false)
    assert.deepEqual(harness.saved(), [{ name: 'saved', value: savedClient }])
  })
})

describe('resumo depois de salvar', () => {
  it('mostra o cadastro concluído e o certificado ainda sem cadastro', async (t) => {
    const harness = modalHarness()
    t.after(harness.dispose)
    await harness.modal.onSubmit({ data: {} })

    const steps = harness.modal.registrationSteps.value.map(step => [step.id, step.statusLabel, step.done])
    assert.equal(JSON.stringify(steps), JSON.stringify([
      ['cadastro', 'Concluído', true],
      ['xml', 'Incluída', true],
      ['certificate', 'Sem cadastro', false],
      ['poa', 'Sem cadastro', false]
    ]))
    assert.equal(harness.modal.registrationProgress.value, 50)
  })

  it('marca os contatos de envio quando o salvamento deles termina', async (t) => {
    const harness = modalHarness()
    t.after(harness.dispose)
    harness.modal.addContact()
    Object.assign(harness.modal.contacts.value[0]!, { name: 'Maria', value: 'maria@empresa.com' })
    await harness.modal.onSubmit({ data: {} })

    const contacts = harness.modal.registrationSteps.value.find(step => step.id === 'contacts')
    assert.equal(contacts?.statusLabel, 'Concluído')
    assert.equal(contacts?.done, true)
  })

  it('mostra o resumo visual do cadastro na etapa seguinte ao salvar', () => {
    const source = modalSource()
    const template = source.slice(source.indexOf('<template>'))
    assert.match(template, /Cliente cadastrado com sucesso\./)
    assert.match(source, /label: 'Cadastro do cliente'/)
    assert.match(source, /label: 'Captura de XML'/)
    assert.match(source, /'Certificado digital'/)
    assert.match(source, /'Procuração e-CAC'/)
    assert.match(template, /Obrigações de monitoramento/)
    assert.match(template, /label="Fechar"/)
    assert.match(template, /label="Confirmar obrigações"/)
    assert.match(source, /title="Não foi possível associar as obrigações"[\s\S]*?@retry="confirmModules"/)
  })
})

describe('alerta de erro na associação das obrigações', () => {
  it('tenta de novo chamando confirmModules, com o botão em carregando', () => {
    // Sem `@retry` o botão "Tentar novamente" do ErrorRetryAlert emite para
    // o vazio — o alerta de erro de carregamento já usa
    // `@retry="savedClient && loadModules(savedClient.id)"`.
    const source = modalSource()

    assert.match(source, /title="Não foi possível associar as obrigações"[\s\S]*?@retry="confirmModules"/)
    assert.match(source, /title="Não foi possível associar as obrigações"[\s\S]*?:loading="modulesSubmitting"/)
  })
})

describe('confirmar obrigações com nada marcado', () => {
  it('conclui sem associar em vez de postar um payload vazio', () => {
    // `StoreClientMonitoringModulesRequest` exige `obligations min:1`: postar
    // `[]` é um 422 garantido. O caminho existente "Concluir sem associar"
    // (`finishSaved()`, que emite `saved` sem associação) já resolve.
    const source = modalSource()

    assert.match(source, /async function confirmModules\(\)[\s\S]*?monitoringModulesPayload/)
    assert.match(source, /async function confirmModules\(\)[\s\S]*?if \(obligations\.length === 0\)[\s\S]*?finishSaved\(\)/)
  })
})
