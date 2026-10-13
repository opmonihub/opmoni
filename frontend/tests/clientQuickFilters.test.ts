import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { createSSRApp, defineComponent, h, ref, computed, type Component, type ComputedRef } from 'vue'
import { renderToString } from 'vue/server-renderer'
import type { ClientPortfolioSummary } from '../app/types/client.ts'
import { customerListPath } from '../app/utils/customerRoutes.ts'
import { deadlineStatusAppearance } from '../app/utils/portfolioLabels.ts'
import { compileVueComponent, evaluateVueBindings, vueSource } from './helpers/compileVueComponent.ts'

const LinkBoundary = defineComponent({
  props: ['to', 'active', 'exact', 'raw', 'ariaCurrentValue'],
  setup: (props, { slots }) => () => h('a', { href: props.to }, slots.default?.())
})

const PageCardBoundary = defineComponent({
  props: ['to', 'icon', 'title', 'variant', 'ui'],
  setup: (props, { slots }) => () => h('section', {
    'data-variant': props.variant,
    'data-ui': JSON.stringify(props.ui)
  }, [h('span', props.title), slots.default?.()])
})

const IconBoundary = defineComponent({
  props: ['name'],
  setup: props => () => h('i', { 'data-icon': props.name })
})

const SkeletonBoundary = defineComponent({
  setup: () => () => h('span', { 'data-skeleton': true })
})

async function render(component: Component, props: Record<string, unknown>, slot?: string): Promise<string> {
  const app = createSSRApp({
    render: () => h(component, props, slot ? { default: () => slot } : undefined)
  })
  app.component('ULink', LinkBoundary)
  app.component('UPageCard', PageCardBoundary)
  app.component('UIcon', IconBoundary)
  app.component('USkeleton', SkeletonBoundary)
  return renderToString(app)
}

const metricCard = compileVueComponent('components/MetricCard.vue')
const toolbar = compileVueComponent('components/customers/ClientListToolbar.vue')
const page = vueSource('pages/customers/[documento]/[[situacao]].vue')
const summary: ClientPortfolioSummary = {
  total: 19,
  active: 13,
  inactive: 6,
  certificate: { expiring: 0, expired: 2, valid: 12, missing: 5 },
  poa: { expiring: 4, expired: 0, valid: 6, missing: 9 }
}

interface StatusCard {
  label: string
  value: string
  to: string
  count?: number
  selected: boolean
}

function pageCards(document: 'certificate' | 'poa' = 'certificate', status = 'all') {
  const documentTab = ref(document)
  const documentStatus = ref(status)
  const summaryData = ref<ClientPortfolioSummary | undefined>(summary)
  const summaryError = ref<Error | undefined>()
  const result = evaluateVueBindings(page, ['statusChoices', 'statusCards'], {
    computed,
    customerListPath,
    deadlineStatusAppearance,
    documentTab,
    documentStatus,
    summary: summaryData,
    summaryError
  })
  return {
    cards: result.statusCards as ComputedRef<StatusCard[]>,
    documentTab,
    documentStatus,
    summaryData,
    summaryError
  }
}

describe('MetricCard compact opt-in', () => {
  it('preserves the original dashboard defaults, tone, size and slot', async () => {
    const html = await render(metricCard, { title: 'Clientes', icon: 'i-lucide-building-2', value: 19 })
    assert.match(html, /<section[^>]*data-variant="subtle"/)
    assert.match(html, /lg:rounded-none first:rounded-l-lg last:rounded-r-lg hover:z-1/)
    assert.match(html, /text-2xl font-semibold tabular-nums/)
    assert.match(html, /p-2.5 rounded-full bg-elevated ring ring-inset ring-default flex-col/)
    assert.match(html, /font-normal text-muted text-sm/)
    assert.doesNotMatch(html, /aria-current|focus-visible|h-16/)

    const brand = await render(metricCard, { title: 'Clientes', icon: 'i-lucide-building-2', tone: 'brand' }, 'Conteúdo original')
    assert.match(brand, /bg-primary\/10 ring ring-inset ring-primary\/25/)
    assert.match(brand, /font-normal text-muted text-xs uppercase/)
    assert.match(brand, /Conteúdo original/)
  })

  it('renders a compact selected card as a single focusable current-page link', async () => {
    const html = await render(metricCard, {
      title: 'A vencer', icon: 'i-lucide-clock-alert', to: '/customers/certificados/a-vencer',
      value: 0, compact: true, selected: true, iconClass: 'text-warning'
    })
    assert.equal((html.match(/<a\b/g) ?? []).length, 1)
    assert.match(html, /href="\/customers\/certificados\/a-vencer"/)
    assert.match(html, /aria-current="page"/)
    assert.match(html, /h-16/)
    assert.match(html, /focus-visible:outline/)
    assert.match(html, /ring-primary/)
    assert.match(html, /text-warning/)
    assert.match(html, />0</)
    assert.doesNotMatch(html, /<button|<section/)
  })

  it('does not mark Todos or another unselected card current', async () => {
    const html = await render(metricCard, {
      title: 'Todos', icon: 'i-lucide-building-2', to: '/customers/certificados',
      value: 19, compact: true, selected: false
    })
    assert.match(html, /<a\b/)
    assert.doesNotMatch(html, /aria-current="page"|ring-primary/)
  })

  it('shows a skeleton rather than an old or zero count during summary loading', async () => {
    const html = await render(metricCard, {
      title: 'Vencido', icon: 'i-lucide-circle-alert', compact: true,
      value: 0, loading: true, unavailable: true
    })
    assert.match(html, /data-skeleton="true"/)
    assert.match(html, /Carregando contagem/)
    assert.doesNotMatch(html, />0<|Contagem indisponível/)
  })

  it('shows an accessible unavailable indicator instead of a stale or fabricated count', async () => {
    for (const props of [{ value: 0, unavailable: true }, { value: 27, unavailable: true }, {}]) {
      const html = await render(metricCard, {
        title: 'Válido', icon: 'i-lucide-circle-check', compact: true, ...props
      })
      assert.match(html, /Contagem indisponível/)
      assert.match(html, /—/)
      assert.doesNotMatch(html, />0<|>27</)
    }
  })
})

describe('route-backed client status cards', () => {
  it('uses Todos total and the correct independent document buckets in the approved order', () => {
    const model = pageCards()
    assert.deepEqual(model.cards.value.map(card => card.label), ['Todos', 'A vencer', 'Vencido', 'Válido', 'Sem cadastro'])
    assert.deepEqual(model.cards.value.map(card => card.count), [19, 0, 2, 12, 5])
    assert.deepEqual(model.cards.value.map(card => card.to), [
      '/customers/certificados', '/customers/certificados/a-vencer',
      '/customers/certificados/vencido', '/customers/certificados/valido', '/customers/certificados/sem-cadastro'
    ])
    model.documentTab.value = 'poa'
    assert.deepEqual(model.cards.value.map(card => card.count), [19, 4, 0, 6, 9])
    assert.deepEqual(model.cards.value.map(card => card.to), [
      '/customers/procuracao', '/customers/procuracao/a-vencer',
      '/customers/procuracao/vencido', '/customers/procuracao/valido', '/customers/procuracao/sem-cadastro'
    ])
  })

  it('selects exactly one route status and clears Todos while a status view is open', () => {
    const model = pageCards()
    for (const status of ['all', 'expiring', 'expired', 'valid', 'missing']) {
      model.documentStatus.value = status
      assert.deepEqual(model.cards.value.filter(card => card.selected).map(card => card.value), [status])
    }
  })

  it('leaves unavailable summary counts undefined, including after a failed refresh with cached data', () => {
    const model = pageCards()
    model.summaryError.value = new Error('Summary failed')
    assert.ok(model.cards.value.every(card => card.count === undefined))
    model.summaryError.value = undefined
    model.summaryData.value = undefined
    assert.ok(model.cards.value.every(card => card.count === undefined))
  })

  it('keeps summary search and column scope but excludes the selected list view', () => {
    const debouncedSearch = ref('Acme')
    const columnFilters = ref({ status: ['active'], tag_id: [3, 7], poa_status: ['valid'] })
    const result = evaluateVueBindings(page, ['summaryParams'], {
      computed, debouncedSearch, columnFilters, view: ref('certificate_expired')
    })
    const params = result.summaryParams as ComputedRef<Record<string, unknown>>
    assert.deepEqual(params.value, { q: 'Acme', status: ['active'], tag_id: [3, 7], poa_status: ['valid'] })
    debouncedSearch.value = ''
    assert.equal(params.value.q, undefined)
    assert.equal('view' in params.value, false)
  })

  it('drives skeleton and unavailable states from summary, never row loading', () => {
    assert.match(page, /status: summaryStatus/)
    assert.match(page, /error: summaryError/)
    const toolbarTag = page.match(/<CustomersClientListToolbar[\s\S]*?\/>/)?.[0]
    assert.ok(toolbarTag)
    assert.match(toolbarTag, /:status-cards="statusCards"/)
    assert.match(toolbarTag, /:loading="summaryStatus === 'pending'/)
    assert.match(toolbarTag, /:unavailable="Boolean\(summaryError\) \|\| !summary"/)
    assert.doesNotMatch(toolbarTag, /isLoading|status-tabs|mobile-status/)
    assert.doesNotMatch(page, /definePageMeta\([^)]*\bkey:|mobileStatusItems|statusTabs/)
  })
})

describe('shared responsive quick-filter and list-control structure', () => {
  it('renders five equal desktop columns and one bounded mobile scrolling row', async () => {
    const source = vueSource('components/customers/ClientListToolbar.vue')
    assert.match(source, /grid-flow-col/)
    assert.match(source, /auto-cols-/)
    assert.match(source, /overflow-x-auto/)
    assert.match(source, /md:grid-cols-5/)
    assert.match(source, /min-w-0/)
    assert.doesNotMatch(source, /UNavigationMenu|CustomersClientListMenu|hidden min-w-0 md:flex/)
    const app = createSSRApp({ render: () => h(toolbar, { statusCards: pageCards('poa', 'expired').cards.value }) })
    app.component('MetricCard', metricCard)
    app.component('ULink', LinkBoundary)
    app.component('UPageCard', PageCardBoundary)
    app.component('UIcon', IconBoundary)
    app.component('USkeleton', SkeletonBoundary)
    const html = await renderToString(app)
    assert.equal((html.match(/<a\b/g) ?? []).length, 5)
    assert.equal((html.match(/aria-current="page"/g) ?? []).length, 1)
    assert.match(html, /aria-label="Filtrar clientes por situação"/)
  })

  it('places one permission-aware saved-filter/tag menu in the shared action row without mobile chips', () => {
    const source = vueSource('components/customers/ClientListFilters.vue')
    assert.doesNotMatch(source, /DataTableStatusChips|mobileStatusItems/)
    assert.equal((source.match(/<CustomersClientListMenu\b/g) ?? []).length, 1)
    const menuTag = source.match(/<CustomersClientListMenu[\s\S]*?\/>/)?.[0]
    assert.ok(menuTag)
    assert.match(menuTag, /:compact="!desktopTable"/)
    assert.match(menuTag, /:show-saved="documentStatus === 'all'"/)
    assert.match(menuTag, /:can-manage-tags="canManageClients"/)
    assert.doesNotMatch(menuTag, /md:hidden|hidden md/)
    for (const control of ['DataTableFilterPanel', 'UDropdownMenu', 'DataTableColumnMenu', 'emit(\'export\')']) {
      assert.ok(source.includes(control), `Existing control must remain: ${control}`)
    }
    assert.match(source, /flex-wrap/)
  })
})
