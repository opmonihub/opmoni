<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import { apiFailure } from '#shared/utils/access'

const props = withDefaults(defineProps<{
  collapsed?: boolean
  context?: 'account' | 'company'
}>(), { context: 'account' })

const { user, switchAccount, switchCompany } = useAuth()
const toast = useToast()
const busy = ref(false)
const activeContext = computed(() => props.context === 'company' || !user.value?.current_account ? 'company' : 'account')
const selected = computed(() => {
  const companyContext = activeContext.value === 'company'
  const current = companyContext ? user.value?.current_company : user.value?.current_account
  return {
    label: current?.name || 'Selecionar contexto',
    icon: companyContext ? 'i-lucide-factory' : 'i-lucide-building-2'
  }
})

async function changeContext(kind: 'account' | 'company', id: number) {
  const choices = kind === 'account' ? user.value?.accounts : user.value?.companies
  const current = kind === 'account' ? user.value?.current_account : user.value?.current_company
  if (busy.value || !choices?.some(choice => choice.id === id && choice.status === 'active'))
    return
  if (current?.id === id) {
    if (props.context !== kind)
      await navigateTo(kind === 'account' ? '/' : '/erp')
    return
  }

  busy.value = true
  try {
    await (kind === 'account' ? switchAccount : switchCompany)(id)
  } catch (error) {
    toast.add({ title: apiFailure(error).message, color: 'error' })
  } finally {
    busy.value = false
  }
}

const items = computed<DropdownMenuItem[][]>(() => {
  const groups: DropdownMenuItem[][] = []
  if (user.value?.accounts.length) {
    groups.push([{ label: 'Escritórios', type: 'label' }, ...user.value.accounts.map(account => ({
      label: account.name + (account.status === 'suspended' ? ' · Suspenso' : ''),
      icon: 'i-lucide-building-2',
      active: activeContext.value === 'account' && account.id === user.value?.current_account?.id,
      disabled: busy.value || account.status !== 'active',
      onSelect: () => changeContext('account', account.id)
    }))])
  }
  if (user.value?.companies.length) {
    groups.push([{ label: 'Empresas', type: 'label' }, ...user.value.companies.map(company => ({
      label: company.name + (company.status === 'suspended' ? ' · Suspenso' : company.access_kind === 'delegated' ? ' · Delegada' : ''),
      icon: 'i-lucide-factory',
      active: activeContext.value === 'company' && company.id === user.value?.current_company?.id,
      disabled: busy.value || company.status !== 'active',
      onSelect: () => changeContext('company', company.id)
    }))])
  }
  return groups
})
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'center', collisionPadding: 12 }"
    :ui="{ content: collapsed ? 'w-64' : 'w-(--reka-dropdown-menu-trigger-width)' }"
  >
    <UButton
      :label="collapsed ? undefined : selected.label"
      :icon="selected.icon"
      :trailing-icon="collapsed ? undefined : 'i-lucide-chevrons-up-down'"
      :aria-label="`Selecionar contexto: ${selected.label}`"
      :loading="busy"
      :disabled="busy || !items.length"
      color="neutral"
      variant="ghost"
      block
      :square="collapsed"
      class="min-w-0 data-[state=open]:bg-elevated"
      :class="!collapsed && 'py-2'"
      :ui="{ label: 'truncate', trailingIcon: 'text-dimmed' }"
    />

    <template #item-trailing="{ item }">
      <UIcon
        v-if="item.active"
        name="i-lucide-check"
        class="size-5 shrink-0 text-primary"
        aria-hidden="true"
      />
    </template>
  </UDropdownMenu>
</template>
