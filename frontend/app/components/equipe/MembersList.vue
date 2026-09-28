<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import type { AccountMemberRole } from '~/composables/useMembers'
import type { MemberDirectoryEntry } from '~/types/team'

withDefaults(defineProps<{
  members: MemberDirectoryEntry[]
  canManage?: boolean
}>(), {
  canManage: false
})

const emit = defineEmits<{
  'update:role': [member: MemberDirectoryEntry, role: AccountMemberRole]
  'remove': [member: MemberDirectoryEntry]
}>()

const roleItems: { label: string, value: AccountMemberRole }[] = [
  { label: 'Admin', value: 'admin' },
  { label: 'Operador', value: 'operador' },
  { label: 'Usuário', value: 'user' }
]

const rolePresentation: Record<string, { label: string, color: 'primary' | 'info' | 'neutral', icon: string }> = {
  admin: { label: 'Admin', color: 'primary', icon: 'i-lucide-shield-check' },
  operador: { label: 'Operador', color: 'info', icon: 'i-lucide-briefcase' },
  user: { label: 'Usuário', color: 'neutral', icon: 'i-lucide-user' }
}

function roleOf(member: MemberDirectoryEntry) {
  return rolePresentation[member.role] ?? { label: member.role, color: 'neutral' as const, icon: 'i-lucide-user' }
}

function memberActions(member: MemberDirectoryEntry): DropdownMenuItem[] {
  return [{
    label: 'Remover membro',
    icon: 'i-lucide-trash',
    color: 'error',
    onSelect: () => emit('remove', member)
  }]
}

function onRoleChange(member: MemberDirectoryEntry, role: AccountMemberRole) {
  if (role === member.role) return
  emit('update:role', member, role)
}
</script>

<template>
  <ul role="list" class="divide-y divide-default">
    <li
      v-for="member in members"
      :key="member.id"
      class="flex items-center justify-between gap-3 px-4 py-3 sm:px-6"
    >
      <div class="flex min-w-0 items-center gap-3">
        <UAvatar :alt="member.name" size="md" />

        <div class="min-w-0 text-sm">
          <p class="truncate font-medium text-highlighted">
            {{ member.name }}
          </p>
          <div v-if="member.departments.length" class="mt-1 flex flex-wrap gap-1">
            <UBadge
              v-for="department in member.departments"
              :key="department.id"
              :label="department.name"
              :color="department.color"
              variant="subtle"
              size="xs"
            />
          </div>
          <p v-else class="mt-0.5 truncate text-xs text-muted">
            Sem departamento
          </p>
        </div>
      </div>

      <div class="flex shrink-0 items-center gap-2 sm:gap-3">
        <USelect
          v-if="canManage"
          :model-value="(member.role as AccountMemberRole)"
          :items="roleItems"
          value-key="value"
          label-key="label"
          color="neutral"
          class="w-32"
          @update:model-value="(value) => onRoleChange(member, value as AccountMemberRole)"
        />
        <UBadge
          v-else
          :label="roleOf(member).label"
          :color="roleOf(member).color"
          :icon="roleOf(member).icon"
          variant="subtle"
        />

        <DataTableRowActionsMenu
          v-if="canManage"
          :items="memberActions(member)"
          :label="`Ações de ${member.name}`"
        />
      </div>
    </li>
  </ul>
</template>
