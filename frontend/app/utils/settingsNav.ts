import type { NavigationMenuItem } from '@nuxt/ui'

export interface SettingsPage {
  label: string
  icon: string
  to: string
  /** `exact` para o índice, que não deve acender nas telas irmãs. */
  exact?: boolean
}

const settingsPagesBase: readonly SettingsPage[] = [
  { label: 'Geral', icon: 'i-lucide-user', to: '/settings', exact: true },
  { label: 'Notificações', icon: 'i-lucide-bell', to: '/settings/notifications' },
  { label: 'Segurança', icon: 'i-lucide-shield', to: '/settings/security' }
]

const certificatePage: SettingsPage = {
  label: 'Certificado do escritório',
  icon: 'i-lucide-file-signature',
  to: '/settings/certificado'
}

/**
 * As abas de Configurações. O e-CNPJ do escritório entra no fim para quem
 * administra a Account (`admin` ou super_admin em suporte), espelhando
 * `AccountCertificatePolicy` e o middleware `account-admin` em
 * `/settings/certificado`.
 */
export function settingsPages(canManageOfficeCertificate: boolean): readonly SettingsPage[] {
  return canManageOfficeCertificate ? [...settingsPagesBase, certificatePage] : settingsPagesBase
}

/** A barra de abas da tela de Configurações, no formato que o `UNavigationMenu` espera. */
export function settingsTabs(canManageOfficeCertificate: boolean): NavigationMenuItem[][] {
  return [settingsPages(canManageOfficeCertificate).map(page => ({
    label: page.label,
    icon: page.icon,
    to: page.to,
    exact: page.exact ?? false
  }))]
}

/**
 * Os filhos de Configurações no sidebar, mesma lista e mesma ordem das abas.
 *
 * Sem ícone: no template da lateral o ícone fica no item pai, e o submenu é só texto.
 * O ícone continua na barra de abas, via `settingsTabs`.
 */
export function settingsSidebarChildren(canManageOfficeCertificate: boolean): NavigationMenuItem[] {
  return settingsPages(canManageOfficeCertificate).map(page => ({
    label: page.label,
    to: page.to,
    exact: page.exact ?? false
  }))
}
