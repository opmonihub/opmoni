import type { NavigationMenuItem } from '@nuxt/ui'
import type { MonitoringSituacao, ObligationCategory } from '~/types/serpro'

export interface MonitoringColumn {
  id: string
  header: string
  /** Right-aligned, for counts and amounts. */
  numeric?: boolean
}

export interface MonitoringObligation {
  /** The path under `/monitoring`, and the key the backend is addressed by. */
  slug: string
  label: string
  icon: string
  /** Only the columns this obligation's source actually returns (D21). */
  columns: readonly MonitoringColumn[]
  /** `null` when the catalogue publishes no service for it. */
  service: string | null
  procuracao: string | null
  category: ObligationCategory
  /** What a `derived` obligation projects over, named for the office. */
  derivedFrom?: string
}

export interface MonitoringGroup {
  label: string
  icon: string
  description: string
  pages: readonly MonitoringObligation[]
}

/**
 * The published catalogue revision this mapping was read from (D22). The
 * catalogue is a moving document that contradicts itself, so the mapping
 * records its shelf life instead of being trusted as settled.
 */
export const monitoringCatalogueRevision = '2026-09'

/**
 * The obligations the provider does not serve, in one place because two screens
 * have to agree on them: the obligation page draws no counter and no row for
 * these, and the overview draws no attention count — and a `0` on either would
 * say "nobody needs attention" about an obligation no client can be pending for.
 */
const UNSERVED_CATEGORIES: readonly ObligationCategory[] = ['unavailable', 'extinct']

/** Whether the integration serves this obligation at all. */
export function monitoringObligationUnserved(obligation: Pick<MonitoringObligation, 'category'>): boolean {
  return UNSERVED_CATEGORIES.includes(obligation.category)
}

const NAME: MonitoringColumn = { id: 'name', header: 'Razão social' }
const SITUACAO: MonitoringColumn = { id: 'situacao', header: 'Situação' }
/** Read from `row.consulted_at`, never from `fields` — it is the row's own stamp. */
const ULTIMA_CONSULTA: MonitoringColumn = { id: 'ultima_consulta', header: 'Última consulta' }

/** Every served obligation carries the situation, its own facts, the client's name and when the provider last answered. */
const served = (...specific: MonitoringColumn[]) => [SITUACAO, ...specific, NAME, ULTIMA_CONSULTA] as const
/** An obligation the provider does not serve presents no column at all. */
const unserved: readonly MonitoringColumn[] = []

export const monitoringGroups: readonly MonitoringGroup[] = [
  {
    label: 'Simples Nacional',
    icon: 'i-lucide-store',
    description: 'Receita bruta acumulada dos últimos 12 meses.',
    pages: [
      {
        slug: 'simples-nacional',
        label: 'Simples Nacional',
        icon: 'i-lucide-store',
        columns: served({ id: 'rbt12', header: 'RBT12', numeric: true }),
        service: 'REGIMEAPURACAO/CONSULTAROPCAOREGIME103',
        procuracao: '00060',
        category: 'direct'
      }
    ]
  },
  {
    label: 'MEI',
    icon: 'i-lucide-store',
    description: 'Dívida ativa do microempreendedor.',
    pages: [
      {
        slug: 'mei',
        label: 'MEI',
        icon: 'i-lucide-store',
        columns: served({ id: 'divida_ativa', header: 'Dívida ativa', numeric: true }),
        service: 'PGMEI/DIVIDAATIVA24',
        procuracao: null,
        category: 'direct'
      }
    ]
  },
  {
    label: 'DCTFWeb',
    icon: 'i-lucide-file-spreadsheet',
    description: 'Entregas da DCTFWeb.',
    pages: [
      {
        slug: 'dctfweb',
        label: 'DCTFWeb',
        icon: 'i-lucide-file-chart-column',
        columns: served(
          { id: 'ultima_declaracao', header: 'Última declaração' },
          { id: 'receitas', header: 'Receitas', numeric: true }
        ),
        service: 'DCTFWEB/CONSXMLDECLARACAO38',
        procuracao: '00103',
        category: 'direct'
      }
    ]
  },
  {
    label: 'FGTS Digital',
    icon: 'i-lucide-landmark',
    description: 'Valores apurados no FGTS Digital.',
    pages: [
      {
        // PGDAS-D publishes a closed tax-code table with no FGTS, and FGTS due
        // from a Simples optant is not collected in the DAS. The only
        // structured FGTS in the catalogue is inside the DCTFWeb declaration.
        slug: 'fgts-digital',
        label: 'FGTS Digital',
        icon: 'i-lucide-landmark',
        columns: served({ id: 'valor_apurado_1718', header: 'Valor apurado (1718)', numeric: true }),
        service: 'DCTFWEB/CONSXMLDECLARACAO38',
        procuracao: '00103',
        category: 'derived',
        derivedFrom: 'o valor 1718 da declaração DCTFWeb'
      }
    ]
  },
  {
    label: 'Parcelamentos',
    icon: 'i-lucide-calendar-clock',
    description: 'Parcelas em aberto por programa.',
    pages: [
      {
        slug: 'parcelamentos/simples-nacional',
        label: 'Simples Nacional',
        icon: 'i-lucide-store',
        columns: served(),
        service: 'PARCSN/PEDIDOSPARC163',
        procuracao: '00076+00188',
        category: 'direct'
      },
      {
        // All eight parcelamento systems say the debts are Simples Nacional
        // ones under collection at the RFB: federal parcelamento runs on
        // Receita's own channels, and the catalogue publishes nothing for it.
        slug: 'parcelamentos/pgfn',
        label: 'PGFN',
        icon: 'i-lucide-scale',
        columns: unserved,
        service: null,
        procuracao: null,
        category: 'unavailable'
      },
      {
        // "Receita Federal" is a misnomer: PERTSN and RELPSN are Simples
        // Nacional debts under federal programmes. The name is kept because it
        // is the office's word for the tab, and the comment keeps it honest.
        slug: 'parcelamentos/receita-federal',
        label: 'Receita Federal',
        icon: 'i-lucide-building-2',
        columns: served(),
        service: 'PERTSN/PEDIDOSPARC183+RELPSN/PEDIDOSPARC193',
        procuracao: '00149+10011, 00210+10036',
        category: 'derived',
        derivedFrom: 'os sistemas PERTSN e RELPSN'
      },
      {
        slug: 'parcelamentos/especiais',
        label: 'Especiais',
        icon: 'i-lucide-folder-lock',
        columns: served(),
        service: 'PARCSN-ESP/PEDIDOSPARC173',
        procuracao: '00125',
        category: 'direct'
      }
    ]
  },
  {
    label: 'Situação Fiscal',
    icon: 'i-lucide-shield-check',
    description: 'Relatório, certidões e comprovantes.',
    pages: [
      {
        slug: 'situacao-fiscal/relatorio-fiscal',
        label: 'Relatório Fiscal',
        icon: 'i-lucide-file-text',
        columns: served(),
        service: 'SITFIS/RELATORIOSITFIS92',
        procuracao: '00002',
        category: 'direct'
      },
      {
        // Not a separate source: a projection of the same SITFIS PDF, titled
        // "informações de apoio para emissão de certidão".
        slug: 'situacao-fiscal/certidoes',
        label: 'Certidões',
        icon: 'i-lucide-badge-check',
        columns: served(
          { id: 'certidao', header: 'Certidão' },
          { id: 'validade', header: 'Validade' }
        ),
        service: 'SITFIS/RELATORIOSITFIS92',
        procuracao: '00002',
        category: 'derived',
        derivedFrom: 'o relatório SITFIS, que já traz o número negativo, a emissão e a validade'
      },
      {
        slug: 'situacao-fiscal/comprovantes',
        label: 'Comprovantes',
        icon: 'i-lucide-receipt',
        columns: served({ id: 'ultima', header: 'Último pagamento' }),
        service: 'PAGTOWEB/PAGAMENTOS71',
        procuracao: '00004',
        category: 'direct'
      }
    ]
  },
  {
    label: 'Caixas Postais',
    icon: 'i-lucide-mailbox',
    description: 'Mensagens por caixa.',
    pages: [
      {
        slug: 'caixas-postais/e-cac',
        label: 'e-CAC',
        icon: 'i-lucide-landmark',
        columns: served(
          { id: 'nao_lidas', header: 'Não lidas', numeric: true },
          { id: 'ultima', header: 'Última mensagem' }
        ),
        service: 'CAIXAPOSTAL/MSGCONTRIBUINTE61',
        procuracao: '00006',
        category: 'direct'
      },
      {
        // A subject filter over CAIXAPOSTAL, not a service of its own.
        slug: 'caixas-postais/fgts-digital',
        label: 'FGTS Digital',
        icon: 'i-lucide-wallet',
        columns: served(
          { id: 'nao_lidas', header: 'Não lidas', numeric: true },
          { id: 'ultima', header: 'Última mensagem' }
        ),
        service: 'CAIXAPOSTAL',
        procuracao: '00006',
        category: 'derived',
        derivedFrom: 'um filtro por assunto sobre a caixa postal e-CAC'
      },
      {
        slug: 'caixas-postais/det',
        label: 'DET',
        icon: 'i-lucide-inbox',
        columns: served(
          { id: 'nao_lidas', header: 'Não lidas', numeric: true },
          { id: 'ultima', header: 'Última mensagem' }
        ),
        service: 'CAIXAPOSTAL',
        procuracao: '00006',
        category: 'derived',
        derivedFrom: 'um filtro por assunto sobre a caixa postal e-CAC'
      }
    ]
  },
  {
    label: 'Declarações',
    icon: 'i-lucide-files',
    description: 'Obrigações acessórias da carteira.',
    pages: [
      {
        // `00146` is shared by PGDASD and DEFIS: one client's grant covers
        // both, so the office setup must not present them as two grants (D5).
        //
        // The guide is not read from `fields`: it is derived from the
        // assessment periods already synchronized for this declaration, so the
        // page can say which period it belongs to, when it was issued and
        // whether it was paid without a second provider call.
        //
        // Two deadlines are declared and they come from two different fields:
        // `due_on` is the row's own, `guia_vencimento` is the period's `due_on`.
        // `MonitoringAssessmentPeriod` does not say which document the latter
        // belongs to — only that the assessment period carries a deadline — so
        // nothing here claims it is a different document from the row's, and the
        // column keeps its id and header because the contract is fixed.
        slug: 'declaracoes/pgdas',
        label: 'PGDAS',
        icon: 'i-lucide-file-spreadsheet',
        columns: served({ id: 'ultima_declaracao', header: 'Última declaração' }),
        service: 'PGDASD/CONSDECLARACAO13',
        procuracao: '00146',
        category: 'direct'
      },
      {
        slug: 'declaracoes/dctfweb',
        label: 'DCTFWeb',
        icon: 'i-lucide-file-chart-column',
        columns: served(
          { id: 'ultima_declaracao', header: 'Última declaração' },
          { id: 'receitas', header: 'Receitas', numeric: true }
        ),
        service: 'DCTFWEB/CONSXMLDECLARACAO38',
        procuracao: '00103',
        category: 'direct'
      },
      {
        slug: 'declaracoes/fgts',
        label: 'FGTS',
        icon: 'i-lucide-wallet',
        columns: unserved,
        service: null,
        procuracao: null,
        category: 'unavailable'
      },
      {
        // Shares `00146` with PGDAS-D — see the comment there.
        slug: 'declaracoes/defis',
        label: 'DEFIS',
        icon: 'i-lucide-file-text',
        columns: served({ id: 'ultima_declaracao', header: 'Última declaração' }),
        service: 'DEFIS/CONSDECLARACAO142',
        procuracao: '00146',
        category: 'direct'
      },
      {
        // Not waiting for data. IN RFB 2.043/2021 replaced DIRF with EFD-Reinf
        // and eSocial; IN RFB 2.181/2024 made that a fact from 1 January 2025.
        // Neither successor is exposed by the integration.
        slug: 'declaracoes/dirf',
        label: 'DIRF',
        icon: 'i-lucide-files',
        columns: unserved,
        service: null,
        procuracao: null,
        category: 'extinct'
      }
    ]
  }
]

export const monitoringObligations: readonly MonitoringObligation[] = monitoringGroups.flatMap(group => group.pages)

const situacaoSlug: Record<MonitoringSituacao, string> = {
  em_dia: 'em-dia',
  processando: 'processando',
  pendencias: 'pendencias',
  atencao: 'atencao',
  encerrado: 'encerrado'
}

const situacaoBySlug = {
  'em-dia': 'em_dia',
  'processando': 'processando',
  'pendencias': 'pendencias',
  'atencao': 'atencao',
  'encerrado': 'encerrado'
} as const satisfies Record<string, MonitoringSituacao>

export function monitoringListPath(obligation: MonitoringObligation, situacao?: MonitoringSituacao) {
  const base = `/monitoring/${obligation.slug}`
  return situacao ? `${base}/${situacaoSlug[situacao]}` : base
}

/**
 * The situation is a route segment, never local state, so a list restricted to
 * one state is a link that reproduces for whoever follows it. A trailing
 * segment the vocabulary does not know is treated as part of the obligation
 * path and therefore fails to resolve — the alternative is a mistyped situation
 * silently showing everything.
 */
export function parseMonitoringSlug(slug: unknown) {
  const source = Array.isArray(slug) ? slug : typeof slug === 'string' ? [slug] : []
  const parts = source.filter((part): part is string => typeof part === 'string' && part !== '')
  if (!parts.length) return null

  let situacao: MonitoringSituacao | null = null
  let body = parts
  const last = parts[parts.length - 1]
  if (last && last in situacaoBySlug) {
    situacao = situacaoBySlug[last as keyof typeof situacaoBySlug]
    body = parts.slice(0, -1)
  }
  if (!body.length) return null

  const obligation = monitoringObligations.find(item => item.slug === body.join('/'))
  if (!obligation) return null
  return { obligation, situacao }
}

function obligationActive(path: string, obligation: MonitoringObligation) {
  const base = monitoringListPath(obligation)
  return path === base || path.startsWith(`${base}/`)
}

export interface MonitoringPage {
  label: string
  icon: string
  to: string
}

/**
 * The module's own destinations — what the navbar tab bar carries.
 *
 * Every module in the shell (Admin, Work, Equipe, Clientes) owns a
 * `UDashboardToolbar` of `UNavigationMenu highlight` tabs listing where the module
 * can go, and Monitoramento was the one without one. The obligations are the
 * second level and stay second: they are reachable from the sidebar, from the
 * panel, and from the sub-tab row that this leaves free.
 *
 * With the integration screens gone, the tab bar carries Painel alone: the
 * whole module is a drill-down from it, and the obligations are its children —
 * the screen is kept as the single destination because the tab bar still has
 * to answer for the module's one root.
 */
export const monitoringPages: readonly MonitoringPage[] = [
  { label: 'Painel', icon: 'i-lucide-layout-dashboard', to: '/monitoring' }
]

/**
 * The tab bar answers "which part of the module am I in", and the obligations
 * are part of the panel — `monitoring/index.vue` renders a card per obligation,
 * each linking to its own screen, so an obligation page is a drill-down from
 * Painel and Painel stays lit on it, the same relationship Work's tabs have to
 * `/work/processos/12`. Matching `/monitoring` exactly instead would leave the
 * module tab bar with nothing lit on the obligation screens.
 *
 * Painel is the only destination, and every path under `/monitoring` is its
 * drill-down, so the prefix match keeps it lit on all of them — the rule that
 * used to take the tab away for the integration screens has nothing left to
 * take it from.
 */
export function monitoringPageActive(path: string, page: MonitoringPage) {
  return path === page.to || path.startsWith(`${page.to}/`)
}

/**
 * The sidebar answers "exactly where am I", which is a stricter question than the
 * tab bar's, so it gets its own rule. The obligations are the sidebar's own
 * children: inside one, the group is the position and Painel must go dark, or the
 * rail claims two positions at once. Submenu items stay text-only; the icon
 * belongs to the parent trigger and to the module tab bar.
 */
function monitoringSidebarItem(page: MonitoringPage, path: string): NavigationMenuItem {
  const isIndex = page.to === '/monitoring'
  return {
    label: page.label,
    to: page.to,
    exact: isIndex,
    active: isIndex ? path === page.to : monitoringPageActive(path, page)
  }
}

/** The module tab bar, in the same shape `adminTabs` and the shell expect. */
export function monitoringTabs(path: string): NavigationMenuItem[][] {
  return [[
    ...monitoringPages.map(page => ({
      label: page.label,
      icon: page.icon,
      to: page.to,
      exact: page.to === '/monitoring',
      active: monitoringPageActive(path, page)
    }))
  ]]
}

export function monitoringSidebarChildren(path: string): NavigationMenuItem[] {
  // Painel leads and the obligations follow: they are the bulk of the module,
  // and with the integration screens gone there is nothing after them.
  const items: NavigationMenuItem[] = monitoringPages.map(page => monitoringSidebarItem(page, path))

  for (const group of monitoringGroups) {
    const first = group.pages[0]
    if (!first) continue
    items.push({
      label: group.label,
      to: monitoringListPath(first),
      active: group.pages.some(obligation => obligationActive(path, obligation))
    })
  }

  return items
}
