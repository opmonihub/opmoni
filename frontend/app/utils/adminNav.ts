import type { NavigationMenuItem } from '@nuxt/ui'

export interface AdminPage {
  label: string
  icon: string
  to: string
}

export const adminPages: readonly AdminPage[] = [
  { label: 'Resumo', icon: 'i-lucide-layout-dashboard', to: '/admin' },
  { label: 'Contas', icon: 'i-lucide-building-2', to: '/admin/contas' },
  { label: 'Planos', icon: 'i-lucide-layers', to: '/admin/planos' },
  { label: 'Assinaturas', icon: 'i-lucide-receipt', to: '/admin/assinaturas' },
  { label: 'Usuários', icon: 'i-lucide-users', to: '/admin/usuarios' },
  { label: 'Suporte', icon: 'i-lucide-life-buoy', to: '/admin/suporte' },
  { label: 'Serpro', icon: 'i-lucide-plug', to: '/admin/serpro' },
  { label: 'Certificado do escritório', icon: 'i-lucide-file-signature', to: '/admin/certificado' }
]

export function adminPageActive(path: string, page: AdminPage) {
  if (page.to === '/admin') return path === '/admin'
  return path === page.to || path.startsWith(`${page.to}/`)
}

export function adminSidebarChildren(path: string): NavigationMenuItem[] {
  return adminPages.map(page => ({
    label: page.label,
    to: page.to,
    exact: page.to === '/admin',
    active: adminPageActive(path, page)
  }))
}

export function adminTabs(path: string): NavigationMenuItem[][] {
  return [[
    ...adminPages.map(page => ({
      label: page.label,
      icon: page.icon,
      to: page.to,
      exact: page.to === '/admin',
      active: adminPageActive(path, page)
    }))
  ]]
}

export function adminNavbarTitle(path: string): string {
  return adminPages.find(page => adminPageActive(path, page))?.label ?? 'Admin'
}
