<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

defineProps<{
  collapsed?: boolean
}>()

const { accounts, currentAccount, switchAccount, isSuperAdmin } = useAuth()
const toast = useToast()

const teams = computed(() => accounts.value.map(account => ({
  id: account.id,
  label: account.name,
  avatar: {
    alt: account.name
  }
})))

const selectedTeam = computed(() => {
  const current = currentAccount.value
  const team = teams.value.find(item => item.id === current?.id)
  if (team) {
    return {
      label: team.label,
      avatar: team.avatar
    }
  }
  if (current) {
    return {
      label: current.name,
      avatar: { alt: current.name }
    }
  }
  return {
    label: 'Contas',
    avatar: { alt: 'Contas' }
  }
})

async function selectAccount(id: number) {
  if (id === currentAccount.value?.id) {
    return
  }
  try {
    await switchAccount(id)
    window.location.assign('/')
  } catch {
    toast.add({ title: 'Não foi possível trocar de conta', color: 'error' })
  }
}

const items = computed<DropdownMenuItem[][]>(() => {
  const accountItems = teams.value.map((team) => {
    const link = accounts.value.find(account => account.id === team.id)
    const isCurrent = team.id === currentAccount.value?.id
    return {
      label: team.label,
      avatar: team.avatar,
      icon: link?.is_member === false ? 'i-lucide-life-buoy' : undefined,
      disabled: isCurrent,
      onSelect() {
        void selectAccount(team.id)
      }
    }
  })

  if (!isSuperAdmin.value) {
    return [accountItems]
  }

  return [accountItems, [{
    label: 'Gerenciar contas',
    icon: 'i-lucide-cog',
    to: '/admin/contas'
  }]]
})
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'center', collisionPadding: 12 }"
    :ui="{ content: collapsed ? 'w-40' : 'w-(--reka-dropdown-menu-trigger-width)' }"
  >
    <UButton
      v-bind="{
        ...selectedTeam,
        label: collapsed ? undefined : selectedTeam?.label,
        trailingIcon: collapsed ? undefined : 'i-lucide-chevrons-up-down'
      }"
      color="neutral"
      variant="ghost"
      block
      :square="collapsed"
      class="data-[state=open]:bg-elevated"
      :class="[!collapsed && 'py-2']"
      :ui="{
        trailingIcon: 'text-dimmed'
      }"
    />
  </UDropdownMenu>
</template>
