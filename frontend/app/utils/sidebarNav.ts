type SidebarChild = {
  active?: boolean
  ui?: { childItem?: string }
}

type SidebarParent = {
  type?: string
  to?: unknown
  exact?: boolean
  active?: boolean
}

/**
 * O pai do módulo está aceso quando a rota é o `to` ou uma filha.
 * O prefixo exige a barra: `/work` não acende em `/workflow`.
 */
export function sidebarParentActive(to: unknown, path: string): boolean {
  if (typeof to !== 'string' || to.length === 0) return false
  return path === to || path.startsWith(`${to}/`)
}

/**
 * Cópia para a sidebar recolhida.
 *
 * Expandida, o pai `trigger` é o botão do accordion e o `to` não entra no link.
 * Recolhida, o mesmo item volta a ser link (`pickLinkProps`). `exact: true` no
 * pai só casa o path do módulo e apaga o ícone nas rotas filhas. A cópia tira
 * esse `exact` e marca `active` pelo prefixo. Os filhos ficam como estão: o
 * `exact` de um ancestral (Painel, Geral) continua impedindo o acendimento
 * por prefixo. A lista original, da sidebar expandida, não é alterada.
 */
export function collapsedSidebarItems<T extends SidebarParent>(items: T[], path: string): T[] {
  return items.map((item) => {
    if (item.type !== 'trigger') return item
    return {
      ...item,
      exact: false,
      active: sidebarParentActive(item.to, path)
    }
  })
}

/**
 * Com o grupo recolhido, os irmãos somem e o filho da página aberta permanece
 * no mesmo nó. Trocar por outro link faz o destaque piscar.
 */
export function foldedSidebarChildren<T>(
  children: T[] | undefined,
  folded: boolean
): T[] | undefined {
  if (!folded || !children) {
    return children
  }
  return children.map((child) => {
    const item = child as T & SidebarChild
    return item.active
      ? child
      : { ...item, ui: { ...item.ui, childItem: 'hidden' } } as T
  })
}

/** Grupo expansível da sidebar principal aberto para o path da rota atual. */
export function sidebarOpenGroupFromPath(path: string): string {
  if (sidebarParentActive('/gestao-erp', path)) {
    return 'gestao-erp'
  }
  if (sidebarParentActive('/customers', path)) {
    return 'clientes'
  }
  if (sidebarParentActive('/equipe', path)) {
    return 'equipe'
  }
  if (sidebarParentActive('/monitoring', path)) {
    return 'monitoramento'
  }
  if (sidebarParentActive('/fiscal', path)) {
    return 'fiscal'
  }
  if (sidebarParentActive('/work', path)) {
    return 'work'
  }
  if (sidebarParentActive('/settings', path)) {
    return 'configuracoes'
  }
  if (sidebarParentActive('/admin', path)) {
    return 'admin'
  }
  return ''
}

export function isOfficeErpAdmin(account: { status?: unknown, role?: unknown } | null | undefined): boolean {
  return account?.status === 'active' && account.role === 'admin'
}

export function officeErpSidebarItem(path: string) {
  const active = sidebarParentActive('/gestao-erp', path)
  return {
    label: 'Gestão ERP',
    icon: 'i-lucide-briefcase-business',
    to: '/gestao-erp',
    type: 'trigger' as const,
    value: 'gestao-erp',
    active,
    defaultOpen: active,
    children: [
      { label: 'Visão geral', to: '/gestao-erp', exact: true, active: path === '/gestao-erp' },
      { label: 'Ofertas', to: '/gestao-erp/planos', exact: true, active: path === '/gestao-erp/planos' },
      { label: 'Clientes da carteira', to: '/gestao-erp/clientes', exact: true, active: path === '/gestao-erp/clientes' },
      { label: 'Assinaturas', to: '/gestao-erp/assinaturas', exact: false, active: path.startsWith('/gestao-erp/assinaturas') },
      { label: 'Faturas', to: '/gestao-erp/faturas', exact: false, active: sidebarParentActive('/gestao-erp/faturas', path) },
      { label: 'Recebimentos', to: '/gestao-erp/recebimentos', exact: true, active: path === '/gestao-erp/recebimentos' },
      { label: 'Configurações', to: '/gestao-erp/configuracoes', exact: true, active: path === '/gestao-erp/configuracoes' }
    ]
  }
}
