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
