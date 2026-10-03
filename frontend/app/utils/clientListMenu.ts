import type { DropdownMenuItem } from '@nuxt/ui'
import type { ClientSavedFilter } from '~/types/client'

export interface ClientListMenuItem extends DropdownMenuItem {
  savedId?: number
}

export interface ClientListMenuOptions {
  /** `null` esconde a seção: filtros salvos só abrem em Todos. */
  saved: ClientSavedFilter[] | null
  canSave: boolean
  canManageTags: boolean
  onApply: (filter: ClientSavedFilter) => void
  onSave: () => void
  onTags: (focusCreate: boolean) => void
}

export function clientListMenuItems(options: ClientListMenuOptions): ClientListMenuItem[][] {
  const groups: ClientListMenuItem[][] = []

  if (options.saved) {
    const rows: ClientListMenuItem[] = options.saved.length
      ? options.saved.map(filter => ({
          label: filter.name,
          icon: 'i-lucide-bookmark',
          slot: 'saved' as const,
          savedId: filter.id,
          onSelect: () => options.onApply(filter)
        }))
      : [{ label: 'Nenhum filtro salvo', disabled: true }]

    groups.push([
      { label: 'Filtros salvos', type: 'label' },
      ...rows,
      {
        label: 'Salvar filtros atuais',
        icon: 'i-lucide-plus',
        disabled: !options.canSave,
        onSelect: options.onSave
      }
    ])
  }

  if (options.canManageTags) {
    groups.push([
      { label: 'Tags', type: 'label' },
      { label: 'Criar tag', icon: 'i-lucide-plus', onSelect: () => options.onTags(true) },
      { label: 'Gerenciar tags', icon: 'i-lucide-library', onSelect: () => options.onTags(false) }
    ])
  }

  return groups
}
