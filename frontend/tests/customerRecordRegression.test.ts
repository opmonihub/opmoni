import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import * as apiErrors from '../app/composables/useApiError.ts'
import * as portfolio from '../app/utils/portfolioLabels.ts'
import * as dates from '../app/utils/index.ts'
import * as pageShell from '../app/utils/pageShell.ts'
import * as taxId from '../app/utils/taxId.ts'
import * as dispatch from '../app/utils/whatsappDispatch.ts'
import type { Client, ClientEcacPowerOfAttorney } from '../app/types/client.ts'
import type { ClientDispatchContact } from '../app/types/whatsapp.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

const filename = new URL('../app/pages/customers/empresa/[id].vue', import.meta.url)
const { descriptor } = parse(readFileSync(filename, 'utf8'))
const script = compileScript(descriptor, { id: 'customer-record' })
const template = compileTemplate({
  source: descriptor.template!.content,
  filename: filename.pathname,
  id: 'customer-record',
  compilerOptions: { bindingMetadata: script.bindings }
})

function evaluate(code: string, imports: Record<string, unknown>, globals: Record<string, unknown> = {}) {
  const compiled = ts.transpileModule(code, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ESNext, esModuleInterop: true }
  }).outputText
  const exports: Record<string, unknown> = {}
  new Function('require', 'exports', ...Object.keys(globals), compiled)(
    (name: string) => imports[name], exports, ...Object.values(globals)
  )
  return exports
}

function client(poa: ClientEcacPowerOfAttorney | null = null): Client {
  return {
    id: 42, person_type: 'company', tax_id: '12345678000195', name: 'Empresa de teste',
    trade_name: null, status: 'active', tax_regime: 'presumed_profit',
    registration_status: 'ATIVA', registration_status_date: null, opened_at: null,
    company_size: null, legal_nature: null, primary_activity: { code: null, description: null },
    address: { street_type: null, street: null, number: null, complement: null, district: null, postal_code: null, city: null, state: null },
    email: 'financeiro@empresa.test', phone: '11999999999', certificate: null, certificate_status: 'missing',
    ecac_power_of_attorney: poa, ecac_power_of_attorney_status: poa?.status ?? 'missing',
    source_updated_at: null, looked_up_at: null, created_at: '2026-10-01T12:00:00Z', updated_at: '2026-10-01T12:00:00Z'
  }
}

async function setupRecord(data: Client, destinationsDisabled = false, listDestinations?: (clientId: number) => Promise<ClientDispatchContact[]>, extra: Record<string, unknown> = {}) {
  const cleanups: (() => void)[] = []
  const imports = {
    'vue': { ...Vue, withAsyncContext: (run: () => unknown) => [run(), () => {}], resolveComponent: (name: string) => ({ name }) },
    '~/composables/useApiError': apiErrors,
    '~/utils/portfolioLabels': portfolio,
    '~/utils': dates,
    '~/utils/pageShell': pageShell,
    '~/utils/taxId': taxId,
    '~/utils/whatsappDispatch': dispatch,
    '~/components/monitoring/DispatchContactFormModal.vue': {}
  }
  const globals = {
    ref: Vue.ref,
    shallowRef: Vue.shallowRef,
    computed: Vue.computed,
    ...apiErrors,
    definePageMeta() {},
    onBeforeUnmount() {},
    useOfficeReadLifecycle: () => ({ own() {} }),
    useListNavigationRegistry: () => ({ registry: Vue.ref({ authority: 'office' }) }),
    useAsyncData: async (_key: unknown, handler: (app: unknown, options: { signal: AbortSignal }) => Promise<unknown>, options: { default: () => unknown }) => {
      const data = Vue.ref(options.default())
      const error = Vue.ref<unknown>()
      const status = Vue.ref('idle')
      const clear = () => {
        data.value = options.default()
      }
      const refresh = async () => {
        status.value = 'pending'
        try {
          data.value = await handler({}, { signal: new AbortController().signal })
          error.value = undefined
          status.value = 'success'
        } catch (failure) {
          error.value = failure
          status.value = 'error'
        }
      }
      await refresh()
      return { data, error, status, refresh, clear }
    },
    useRoute: () => ({ params: { id: String(data.id) } }),
    useToast: () => ({ add() {} }),
    useAuth: () => ({ canManageClients: Vue.ref(true) }),
    useClients: () => ({ show: async () => data }),
    useDispatchContacts: () => ({ list: listDestinations ?? (async () => {
      if (destinationsDisabled) throw { statusCode: 404 }
      return []
    }) }),
    watch: (...args: Parameters<typeof Vue.watch>) => {
      const stop = Vue.watch(...args)
      cleanups.push(stop)
      return stop
    },
    ...extra
  }
  const component = evaluate(script.content, imports, globals).default as {
    setup: (props: object, context: object) => Promise<unknown>
  }
  const state = await component.setup({}, { expose() {} }) as {
    tab: Vue.Ref<string>
    contactOpen: Vue.Ref<boolean>
    summaryText: Vue.ComputedRef<string>
    client: Vue.Ref<Client | null>
    toggleStatus: () => Promise<void>
  }
  await Vue.nextTick()
  const render = evaluate(template.code, imports).render as (...args: unknown[]) => Vue.VNode
  return {
    state,
    render: () => render({}, [], {}, Vue.proxyRefs(state), {}, {}),
    close: () => cleanups.forEach(cleanup => cleanup())
  }
}

function nodes(vnode: Vue.VNode): Vue.VNode[] {
  const result = [vnode]
  if (Array.isArray(vnode.children)) {
    for (const child of vnode.children) if (Vue.isVNode(child)) result.push(...nodes(child))
  } else if (vnode.children && typeof vnode.children === 'object') {
    for (const slot of Object.values(vnode.children)) {
      if (typeof slot !== 'function') continue
      const children = slot()
      for (const child of Array.isArray(children) ? children : [children]) {
        if (Vue.isVNode(child)) result.push(...nodes(child))
      }
    }
  }
  return result
}

function componentName(node: Vue.VNode) {
  return typeof node.type === 'object' ? (node.type as { name?: string }).name : node.type
}

describe('dados existentes na ficha do cliente', () => {
  it('distingue falha dos Contatos de envio na visão geral e recupera a lista ao tentar novamente', async (t) => {
    let failing = true
    const page = await setupRecord(client(), false, async () => {
      if (failing) throw { statusCode: 500 }
      return [{
        id: 7, client_id: 42, label: 'Financeiro', channel: 'whatsapp', value: '11987654321',
        created_at: null, updated_at: null
      }]
    })
    t.after(page.close)
    const failedView = nodes(page.render())
    const alert = failedView.find(node => componentName(node) === 'ErrorRetryAlert')

    assert.ok(alert, 'Uma falha de leitura precisa aparecer como erro na visão geral')
    assert.equal(failedView.some(node => typeof node.children === 'string' && node.children.includes('Nenhum Contato de envio cadastrado.')), false)

    failing = false
    await alert.props!.onRetry()
    await Vue.nextTick()
    const recoveredView = nodes(page.render())

    assert.equal(recoveredView.some(node => componentName(node) === 'ErrorRetryAlert'), false)
    assert.ok(recoveredView.some(node => typeof node.children === 'string' && node.children.replace(/\D/g, '') === '11987654321'))
  })

  for (const disabled of [false, true]) {
    it(`preserva telefone e e-mail do cadastro com destinos ${disabled ? 'desativados (404)' : 'vazios'}`, async (t) => {
      const page = await setupRecord(client(), disabled)
      t.after(page.close)
      page.state.tab.value = 'contato'
      const view = nodes(page.render())
      const facts = view.flatMap(node => componentName(node) === 'DataTableMetaList' ? node.props?.items ?? [] : [])

      assert.ok(facts.some(item => item.value === 'financeiro@empresa.test'))
      assert.ok(facts.some(item => String(item.value).replace(/\D/g, '') === '11999999999'))
      assert.ok(page.state.summaryText.value.includes('financeiro@empresa.test'))
      const edit = view.find(node => componentName(node) === 'UButton' && node.props?.label === 'Editar contato')
      assert.ok(edit)
      edit.props!.onClick()
      assert.equal(page.state.contactOpen.value, true)
    })
  }

  it('apresenta a família estabelecida sem vencimento como outorga confirmada', async (t) => {
    const page = await setupRecord(client({
      status: 'valid', expires_on: null,
      families: [{ family: '00146', state: 'established', expires_on: null }]
    }))
    t.after(page.close)
    page.state.tab.value = 'acesso'
    const view = nodes(page.render())

    assert.equal(view.some(node => componentName(node) === 'UEmpty' && node.props?.title === 'Sem outorga confirmada'), false)
    assert.ok(view.some(node => typeof node.children === 'string' && node.children.includes('00146')))
    assert.ok(view.some(node => componentName(node) === 'UBadge' && node.props?.label === 'Em vigor'))
  })

  it('identifica a família recusada que precisa de nova outorga', async (t) => {
    const page = await setupRecord(client({
      status: 'missing', expires_on: null,
      families: [{ family: '00076', state: 'rejected', expires_on: null }]
    }))
    t.after(page.close)
    page.state.tab.value = 'acesso'
    const view = nodes(page.render())

    assert.ok(view.some(node => typeof node.children === 'string' && node.children.includes('00076')))
    assert.ok(view.some(node => componentName(node) === 'UBadge' && node.props?.label === 'Recusada'))
  })
})

it('ignores a late client status update after an effective Account role change', async (t) => {
  const identity = Vue.ref({ id: 1, current_account: { id: 7, status: 'active', role: 'admin' }, accounts: [{ id: 7, status: 'active', role: 'admin' }] })
  const states = new Map<string, Vue.Ref>([['access-user', identity]])
  const foundation = loadAppComposable<Record<string, unknown>>('composables/useListNavigationState.ts', {
    useState(key: string, initial: () => unknown) {
      if (!states.has(key)) states.set(key, Vue.ref(initial()))
      return states.get(key)
    }
  })
  let resolveCommand!: (value: Client) => void
  const completion = new Promise<Client>((resolve) => {
    resolveCommand = resolve
  })
  const toasts: unknown[] = []
  const original = client()
  const page = await setupRecord(original, false, undefined, {
    useListNavigationRegistry: foundation.useListNavigationRegistry,
    useClients: () => ({ show: async () => original, update: () => completion }),
    useToast: () => ({ add: (value: unknown) => toasts.push(value) })
  })
  t.after(page.close)
  const pending = page.state.toggleStatus()
  identity.value.accounts[0]!.role = 'user'
  assert.equal(page.state.client.value, null)
  resolveCommand({ ...original, status: 'inactive' })
  await pending
  assert.equal(page.state.client.value, null)
  assert.deepEqual(toasts, [])
})
