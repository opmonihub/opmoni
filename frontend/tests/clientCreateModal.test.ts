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
import type { Client } from '../app/types/client.ts'
import type { ClientMonitoringModules } from '../app/types/serpro.ts'
import { companyTaxIdEntry, canRegisterTypedCnpj, taxIdEditInvalidatesLookup } from '../app/utils/taxId.ts'
import { initialMonitoringModuleSelection, monitoringModuleGroups, monitoringModulesPayload } from '../app/utils/monitoringModules.ts'

function modalSource(): string {
  return readFileSync(new URL('../app/components/customers/ClientCreateModal.vue', import.meta.url), 'utf8')
}

const savedClient = { id: 7, person_type: 'company' } as Client
const availableModules: ClientMonitoringModules = {
  regime: 'simple_national',
  obligations: [{ slug: 'declaracoes/pgdas', label: 'PGDAS', category: 'direct', suggested: true, associated: false }]
}

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((done) => {
    resolve = done
  })
  return { promise, resolve }
}

function modalHarness(options: {
  create?: () => Promise<Client>
  monitoringModules?: () => Promise<ClientMonitoringModules>
  confirmMonitoringModules?: () => Promise<unknown>
} = {}) {
  // Executa o script real com serviços locais, sem importar o arquivo .vue
  // nem montar a interface. Os watchers usam a reatividade normal do Vue.
  const script = modalSource().match(/<script setup lang="ts">([\s\S]*?)<\/script>/)?.[1]
  assert.ok(script)
  const code = stripTypeScriptTypes(script.replace(/^import[\s\S]*?from ['"][^'"]+['"]\r?\n/gm, ''))
  const props = reactive({ open: true })
  const events: Array<{ name: string, value: unknown }> = []
  const scope = effectScope()
  const modal = scope.run(() => runInNewContext(`${code}\n({ isOpen, onSubmit, finishSaved, confirmModules, modules, modulesLoading, modulesError, modulesSubmitting, submitting })`, {
    z, computed, reactive, ref, shallowRef, watch,
    companyTaxIdEntry, canRegisterTypedCnpj, taxIdEditInvalidatesLookup,
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
      lookupCnpj: () => Promise.reject(new Error('Consulta não esperada'))
    }),
    useAuth: () => ({ canManageClients: ref(true) }),
    useToast: () => ({ add: () => {} })
  })) as {
    isOpen: Ref<boolean>
    onSubmit: (event: { data: object }) => Promise<void>
    finishSaved: () => void
    confirmModules: () => Promise<void>
    modules: Ref<ClientMonitoringModules | null>
    modulesLoading: Ref<boolean>
    modulesError: Ref<boolean>
    modulesSubmitting: Ref<boolean>
    submitting: Ref<boolean>
  }
  return { modal, props, saved: () => events.filter(event => event.name === 'saved'), dispose: () => scope.stop() }
}

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
