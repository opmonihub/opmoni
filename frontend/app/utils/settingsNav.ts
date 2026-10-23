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

const whatsappChannelPage: SettingsPage = {
  label: 'Canal WhatsApp',
  icon: 'i-lucide-message-circle',
  to: '/settings/whatsapp'
}

const erpApprovalPage: SettingsPage = {
  label: 'Integração ERP',
  icon: 'i-lucide-landmark',
  to: '/settings/integracoes-erp'
}

/**
 * As abas de Configurações. O e-CNPJ e o Canal WhatsApp do escritório entram
 * no fim para quem administra a Account (`admin` ou super_admin em suporte),
 * espelhando `AccountCertificatePolicy`, `WhatsAppChannelPolicy` e o
 * middleware `account-admin` nas duas rotas.
 */
export function settingsPages(canManageOfficeCertificate: boolean, canManageErpApproval = false): readonly SettingsPage[] {
  return [
    ...settingsPagesBase,
    ...(canManageOfficeCertificate ? [certificatePage, whatsappChannelPage] : []),
    ...(canManageErpApproval ? [erpApprovalPage] : [])
  ]
}

/** A barra de abas da tela de Configurações, no formato que o `UNavigationMenu` espera. */
export function settingsTabs(canManageOfficeCertificate: boolean, canManageErpApproval = false): NavigationMenuItem[][] {
  return [settingsPages(canManageOfficeCertificate, canManageErpApproval).map(page => ({
    label: page.label,
    icon: page.icon,
    to: page.to,
    exact: page.exact ?? false
  }))]
}

/** O índice (`exact`) só acende na própria rota; as demais acompanham o prefixo. */
export function settingsPageActive(path: string, page: SettingsPage): boolean {
  if (page.exact) return path === page.to
  return path === page.to || path.startsWith(`${page.to}/`)
}

/**
 * Os filhos de Configurações no sidebar, mesma lista e mesma ordem das abas.
 *
 * Sem ícone: no template da lateral o ícone fica no item pai, e o submenu é só texto.
 * O ícone continua na barra de abas, via `settingsTabs`.
 * `active` segue os outros módulos: sem ele, o grupo some ao abrir outro accordion.
 */
export function settingsSidebarChildren(path: string, canManageOfficeCertificate: boolean, canManageErpApproval = false): NavigationMenuItem[] {
  return settingsPages(canManageOfficeCertificate, canManageErpApproval).map(page => ({
    label: page.label,
    to: page.to,
    exact: page.exact ?? false,
    active: settingsPageActive(path, page)
  }))
}
