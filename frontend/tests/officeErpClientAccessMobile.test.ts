import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent, mountVueComponent } from './helpers/compileVueComponent.ts'

function mountPortfolio(options: { open?: boolean, total?: number } = {}) {
  const tableProps: Array<Record<string, unknown>> = []
  const requestedPages: number[] = []
  const component = compileVueComponent('components/office-erp/OfficeErpCompanyPortfolioModal.vue', {
    computed: Vue.computed,
    onScopeDispose: Vue.onScopeDispose,
    ref: Vue.ref,
    watch: Vue.watch,
    useAccessApi: () => ({ api: async (_path: string, request?: { params?: Record<string, string | number> }) => {
      const perPage = Number(request?.params?.per_page ?? 25)
      requestedPages.push(perPage)
      return { data: [], meta: { total: options.total ?? 0, current_page: 1, per_page: perPage, last_page: Math.max(1, Math.ceil((options.total ?? 0) / perPage)) } }
    } }),
    useOfficeErpCommand: () => ({ pending: Vue.ref(false), recoveryKey: Vue.ref(null), execute: async () => ({}), recover: async () => ({ status: 'unknown' }) }),
    useOfficeErpContext: () => ({ capabilities: Vue.ref({ can_read: true }), refresh: async () => {}, capture: () => ({}), isCurrent: () => true }),
    useAuth: () => ({ user: Vue.ref({ id: 1 }), currentAccount: Vue.ref({ id: 2, status: 'active', role: 'owner' }) }),
    useState: (_key: string, init: () => unknown) => Vue.ref(init()),
    useOfficeErpReadIdentity: () => Vue.ref('identity'),
    useOfficeErpReads: () => ({ begin: () => ({ current: () => true, signal: undefined }), cancel() {} })
  }, {
    '~/composables/useApiError': { apiErrorMessage: () => null },
    '~/composables/useOfficeErpReadIdentity': { useOfficeErpReadIdentity: () => Vue.ref('identity') },
    '~/composables/useOfficeErpReads': { useOfficeErpReads: () => ({ begin: () => ({ current: () => true, signal: undefined }), cancel() {} }) },
    '~/composables/useOfficeErpListNavigation': { officeErpAvailablePage: () => 1 },
    '~/composables/useModalFocusReturn': { useModalFocusReturn: () => () => {} }
  })
  const customComponents = {
    UModal: {
      props: ['title', 'description', 'ui', 'open'],
      setup(props: Record<string, unknown>, context: Vue.SetupContext) {
        return () => Vue.h('section', { 'data-modal': true, ...props }, [context.slots.body?.(), context.slots.footer?.()])
      }
    },
    UPagination: {
      setup(_props: unknown, context: Vue.SetupContext) { return () => Vue.h('nav', { 'aria-label': 'Páginas da carteira', ...context.attrs }) }
    },
    OfficeErpDataTable: {
      props: ['columns', 'mobileColumns', 'data', 'loading'],
      setup(props: Record<string, unknown>) {
        tableProps.push(props)
        return () => Vue.h('div', { 'data-table': true })
      }
    }
  } satisfies Record<string, Vue.Component>
  const mounted = mountVueComponent(component, customComponents, { open: options.open ?? false })
  return { ...mounted, tableProps, requestedPages }
}

async function settleReads() {
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
}

test('portfolio page-size chooser remains available when 40 matches are set to 50 then 25', async () => {
  const mounted = mountPortfolio({ open: true, total: 40 })
  const pageSize = () => mounted.nodes(node => node.tag === 'select' && node.props['aria-label'] === 'Clientes por página')[0]
  const navigation = () => mounted.nodes(node => node.tag === 'nav' && node.props['aria-label'] === 'Páginas da carteira')[0]

  try {
    await settleReads()
    assert.ok(pageSize(), '40 matches show the page-size chooser at the default 25 rows')
    assert.ok(navigation(), '40 matches need page navigation at 25 rows')

    ;(pageSize()!.props['onUpdate:modelValue'] as (value: number) => void)(50)
    await settleReads()
    assert.ok(pageSize(), 'the chooser stays visible when total 40 is below the selected page size 50')
    assert.equal(navigation(), undefined, 'navigation hides when all 40 matches fit on page 1')
    assert.equal(mounted.requestedPages.at(-1), 50, 'selecting 50 updates the page-size request')

    ;(pageSize()!.props['onUpdate:modelValue'] as (value: number) => void)(25)
    await settleReads()
    assert.ok(pageSize(), 'the chooser remains available after restoring 25 rows')
    assert.ok(navigation(), 'navigation returns when 40 matches exceed 25 rows')
    assert.equal(mounted.requestedPages.at(-1), 25)
  } finally {
    mounted.unmount()
  }
})

test('portfolio picker hides page-size controls when all 20 matches fit in the default page', async () => {
  const mounted = mountPortfolio({ open: true, total: 20 })

  try {
    await settleReads()
    assert.equal(mounted.nodes(node => node.tag === 'select' && node.props['aria-label'] === 'Clientes por página').length, 0)
    assert.equal(mounted.nodes(node => node.tag === 'nav' && node.props['aria-label'] === 'Páginas da carteira').length, 0)
  } finally {
    mounted.unmount()
  }
})
