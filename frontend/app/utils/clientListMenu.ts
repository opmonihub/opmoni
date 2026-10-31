import type { DropdownMenuItem } from '@nuxt/ui'

export interface ClientListMenuOptions {
  canManageTags: boolean
  onTags: (focusCreate: boolean) => void
}

export function clientListMenuItems(options: ClientListMenuOptions): DropdownMenuItem[][] {
  if (!options.canManageTags) return []

  return [[
    { label: 'Tags', type: 'label' },
    { label: 'Criar tag', icon: 'i-lucide-plus', onSelect: () => options.onTags(true) },
    { label: 'Gerenciar tags', icon: 'i-lucide-library', onSelect: () => options.onTags(false) }
  ]]
}
