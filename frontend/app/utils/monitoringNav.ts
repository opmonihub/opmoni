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

const NAME: MonitoringColumn = { id: 'name', header: 'Cliente' }
const SITUACAO: MonitoringColumn = { id: 'situacao', header: 'Situação' }
const DUE_ON: MonitoringColumn = { id: 'due_on', header: 'Vencimento' }

/** Every served obligation carries the client's name and the situation. */
const served = (...specific: MonitoringColumn[]) => [NAME, ...specific, SITUACAO] as const
/** An obligation the provider does not serve presents no column at all. */
const unserved: readonly MonitoringColumn[] = []

export const monitoringGroups: readonly MonitoringGroup[] = [
  {
    label: 'Simples Nacional',
    icon: 'i-lucide-store',
    description: 'Apuração do regime e data da opção.',
    pages: [
      {
        slug: 'simples-nacional',
        label: 'Simples Nacional',
        icon: 'i-lucide-store',
        columns: served(
          { id: 'regime_escolhido', header: 'Regime escolhido' },
          { id: 'data_da_opcao', header: 'Data da opção' },
          DUE_ON
        ),
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
          { id: 'gi_declaracao', header: 'GI_Declaração' },
          { id: 'receitas', header: 'Receitas', numeric: true },
          DUE_ON
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
        service: 'PARCSN',
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
        service: 'PERTSN+RELPSN',
        procuracao: '00149+10011, 00210+10036',
        category: 'derived',
        derivedFrom: 'os sistemas PERTSN e RELPSN'
      },
      {
        slug: 'parcelamentos/especiais',
        label: 'Especiais',
        icon: 'i-lucide-folder-lock',
        columns: served(),
        service: 'PARCSN-ESP',
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
          { id: 'emissao', header: 'Emissão' },
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
        columns: served(),
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
        columns: served(),
        service: 'CAIXAPOSTAL',
        procuracao: '00006',
        category: 'derived',
        derivedFrom: 'um filtro por assunto sobre a caixa postal e-CAC'
      },
      {
        slug: 'caixas-postais/det',
        label: 'DET',
        icon: 'i-lucide-inbox',
        columns: served(),
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
        slug: 'declaracoes/pgdas',
        label: 'PGDAS',
        icon: 'i-lucide-file-spreadsheet',
        columns: served(
          { id: 'gi_declaracao', header: 'GI_Declaração' },
          { id: 'guia_emitida', header: 'Guia emitida' },
          { id: 'guia_paga', header: 'Guia paga' },
          DUE_ON
        ),
        service: 'PGDASD/CONSDECLARACAO13',
        procuracao: '00146',
        category: 'direct'
      },
      {
        slug: 'declaracoes/dctfweb',
        label: 'DCTFWeb',
        icon: 'i-lucide-file-chart-column',
        columns: served(),
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
        columns: served(),
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

/** Static siblings, resolved by Nuxt ahead of the `[...slug].vue` catch-all. */
export const monitoringIntegrationLinks = [
  { label: 'Termo de autorização', icon: 'i-lucide-file-signature', to: '/monitoring/termos' },
  { label: 'Execuções de sincronização', icon: 'i-lucide-refresh-cw', to: '/monitoring/execucoes' }
] as const

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

export function monitoringSidebarChildren(path: string): NavigationMenuItem[] {
  const items: NavigationMenuItem[] = [{
    label: 'Painel',
    to: '/monitoring',
    exact: true,
    active: path === '/monitoring'
  }]

  for (const group of monitoringGroups) {
    const first = group.pages[0]
    if (!first) continue
    items.push({
      label: group.label,
      to: monitoringListPath(first),
      active: group.pages.some(obligation => obligationActive(path, obligation))
    })
  }

  for (const link of monitoringIntegrationLinks) {
    items.push({
      label: link.label,
      icon: link.icon,
      to: link.to,
      active: path === link.to || path.startsWith(`${link.to}/`)
    })
  }

  return items
}
