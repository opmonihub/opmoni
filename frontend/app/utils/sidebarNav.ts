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
  if (path.startsWith('/customers')) {
    return 'clientes'
  }
  if (path.startsWith('/equipe')) {
    return 'equipe'
  }
  if (path.startsWith('/monitoring')) {
    return 'monitoramento'
  }
  if (path.startsWith('/fiscal')) {
    return 'fiscal'
  }
  if (path.startsWith('/work')) {
    return 'work'
  }
  if (path.startsWith('/settings')) {
    return 'configuracoes'
  }
  if (path.startsWith('/admin')) {
    return 'admin'
  }
  return ''
}
