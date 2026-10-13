import type { NavigationMenuItem } from '@nuxt/ui'
import type { ClientPortfolioDocument } from '~/types/client'
import { customerListPath } from './customerRoutes.ts'

function within(path: string, base: string) {
  return path === base || path.startsWith(`${base}/`)
}

export function clientsListDocument(path: string): ClientPortfolioDocument | null {
  if (within(path, customerListPath('certificate'))) return 'certificate'
  if (within(path, customerListPath('poa'))) return 'poa'
  return null
}

export function clientsSidebarItem(path: string) {
  const active = within(path, '/customers')

  return {
    label: 'Clientes',
    icon: 'i-lucide-users',
    to: '/customers',
    type: 'trigger' as const,
    value: 'clientes',
    active,
    defaultOpen: active,
    children: [
      {
        label: 'Painel',
        to: '/customers/painel',
        exact: true,
        active: path === '/customers/painel'
      },
      {
        label: 'Meus clientes',
        to: customerListPath('certificate'),
        active: clientsListDocument(path) !== null || within(path, '/customers/empresa')
      }
    ]
  } satisfies NavigationMenuItem
}
