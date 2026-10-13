import type { NavigationMenuItem } from '@nuxt/ui'

export interface EquipePage {
  label: string
  icon: string
  to: string
}

export const equipePages: readonly EquipePage[] = [
  { label: 'Membros', icon: 'i-lucide-users', to: '/equipe' },
  { label: 'Departamentos', icon: 'i-lucide-network', to: '/equipe/departamentos' }
]

export function equipePageActive(path: string, page: EquipePage) {
  if (page.to === '/equipe') return path === '/equipe'
  return path === page.to || path.startsWith(`${page.to}/`)
}

export function equipeSidebarChildren(path: string): NavigationMenuItem[] {
  return equipePages.map(page => ({
    label: page.label,
    to: page.to,
    exact: page.to === '/equipe',
    active: equipePageActive(path, page)
  }))
}

export function equipeTabs(path: string): NavigationMenuItem[][] {
  return [[
    ...equipePages.map(page => ({
      label: page.label,
      icon: page.icon,
      to: page.to,
      exact: page.to === '/equipe',
      active: equipePageActive(path, page)
    }))
  ]]
}
