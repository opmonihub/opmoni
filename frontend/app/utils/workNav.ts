import type { NavigationMenuItem } from '@nuxt/ui'

export interface WorkNavItem {
  label: string
  icon: string
  to: string
}

export const workNav: readonly WorkNavItem[] = [
  { label: 'Calendário', icon: 'i-lucide-calendar-days', to: '/work/calendario' },
  { label: 'Clientes', icon: 'i-lucide-building', to: '/work/clientes' },
  { label: 'Processos', icon: 'i-lucide-layers', to: '/work/processos' },
  { label: 'Tarefas', icon: 'i-lucide-square-kanban', to: '/work/tarefas' },
  { label: 'Modelos', icon: 'i-lucide-shapes', to: '/work/modelos' }
]

export function workSidebarChildren(path: string): NavigationMenuItem[] {
  return workNav.map(item => ({
    label: item.label,
    to: item.to,
    active: path === item.to || path.startsWith(`${item.to}/`)
  }))
}
