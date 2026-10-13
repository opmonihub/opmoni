<script setup lang="ts">
import type { AccountMemberRole } from '~/composables/useMembers'
import type { MemberDirectoryEntry } from '~/types/team'
import { useDepartments } from '~/composables/useDepartments'
import { useMembers } from '~/composables/useMembers'

definePageMeta({ middleware: 'auth' })

const { list } = useDepartments()
const { listDirectory, create, update, remove } = useMembers()
const toast = useToast()
const { canManageMembers } = useAuth()

const search = ref('')
const departmentFilter = ref<number | null>(null)

const inviteOpen = ref(false)
const inviting = ref(false)
const inviteName = ref('')
const inviteEmail = ref('')
const invitePassword = ref('')
const inviteRole = ref<AccountMemberRole>('user')

const removeOpen = ref(false)
const removing = ref(false)
const pendingRemove = ref<MemberDirectoryEntry | null>(null)

const roleItems: { label: string, value: AccountMemberRole }[] = [
  { label: 'Admin', value: 'admin' },
  { label: 'Operador', value: 'operador' },
  { label: 'Usuário', value: 'user' }
]

const { data, status, error, refresh: reload } = await useAsyncData('equipe-directory', async () => {
  const [members, departments] = await Promise.all([listDirectory(), list()])
  return { members, departments }
})

/**
 * `sticky`: the alert is fatal to this page — it is `v-else-if` of the list — so a
 * failed load has to survive the next `useAsyncData` run, which clears `error`
 * and would otherwise flash the alert away and the stale content back in.
 */
const { isLoading, showError, retry } = useRetryableLoad({
  refresh: reload,
  error,
  loading: computed(() => status.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar a equipe',
  sticky: true
})

const members = computed<MemberDirectoryEntry[]>(() => data.value?.members ?? [])

const departmentOptions = computed(() => (data.value?.departments ?? []).map(department => ({
  label: department.name,
  value: department.id
})))

const visibleMembers = computed(() => {
  const term = search.value.trim().toLocaleLowerCase('pt-BR')
  const departmentId = departmentFilter.value
  return [...members.value]
    .filter((member) => {
      if (departmentId != null && !member.departments.some(department => department.id === departmentId)) return false
      if (!term) return true
      return member.name.toLocaleLowerCase('pt-BR').includes(term)
        || member.role.toLocaleLowerCase('pt-BR').includes(term)
    })
    .sort((a, b) => a.name.localeCompare(b.name, 'pt-BR'))
})

const hasActiveFilters = computed(() => !!search.value.trim() || departmentFilter.value != null)

function clearFilters() {
  search.value = ''
  departmentFilter.value = null
}

function openInvite() {
  inviteName.value = ''
  inviteEmail.value = ''
  invitePassword.value = ''
  inviteRole.value = 'user'
  inviteOpen.value = true
}

async function submitInvite() {
  if (!canManageMembers.value || inviting.value) return
  const name = inviteName.value.trim()
  const email = inviteEmail.value.trim()
  const password = invitePassword.value
  if (!name || !email || password.length < 8) return

  inviting.value = true
  try {
    await create({ name, email, password, role: inviteRole.value })
    toast.add({ title: 'Membro convidado', color: 'success' })
    inviteOpen.value = false
    await reload()
  } catch {
    toast.add({ title: 'Não foi possível convidar o membro', color: 'error' })
  } finally {
    inviting.value = false
  }
}

async function onRoleUpdate(member: MemberDirectoryEntry, role: AccountMemberRole) {
  if (!canManageMembers.value) return
  try {
    await update(member.id, { role })
    toast.add({ title: 'Papel atualizado', color: 'success' })
    await reload()
  } catch {
    toast.add({ title: 'Não foi possível atualizar o papel', color: 'error' })
  }
}

function askRemove(member: MemberDirectoryEntry) {
  pendingRemove.value = member
  removeOpen.value = true
}

async function confirmRemove() {
  if (!pendingRemove.value || removing.value) return
  removing.value = true
  try {
    await remove(pendingRemove.value.id)
    toast.add({ title: 'Membro removido', color: 'success' })
    removeOpen.value = false
    pendingRemove.value = null
    await reload()
  } catch {
    toast.add({ title: 'Não foi possível remover o membro', color: 'error' })
  } finally {
    removing.value = false
  }
}
</script>

<template>
  <DataTablePanelList
    title="Membros"
    description="Convide novos membros por e-mail."
  >
    <template #action>
      <UButton
        v-if="canManageMembers"
        label="Convidar"
        color="neutral"
        class="w-fit lg:ms-auto"
        :disabled="isLoading"
        @click="openInvite"
      />
    </template>

    <template #toolbar>
      <UInput
        v-model="search"
        icon="i-lucide-search"
        placeholder="Buscar membros"
        autofocus
        class="min-w-0 flex-1"
        :disabled="isLoading"
      />
      <USelectMenu
        v-model="departmentFilter"
        :items="departmentOptions"
        value-key="value"
        label-key="label"
        placeholder="Filtrar por departamento"
        clear
        class="w-full sm:w-64"
        :disabled="isLoading"
      />
    </template>

    <div
      v-if="isLoading"
      class="divide-y divide-default"
      aria-busy="true"
      aria-label="Carregando equipe"
    >
      <div
        v-for="index in 5"
        :key="index"
        class="flex items-center justify-between gap-3 px-4 py-3 sm:px-6"
      >
        <div class="flex min-w-0 flex-1 items-center gap-3">
          <USkeleton class="size-10 shrink-0 rounded-full" />
          <div class="flex min-w-0 flex-1 flex-col gap-1.5">
            <USkeleton class="h-4 w-36 max-w-full" />
            <USkeleton class="h-3 w-24 max-w-full" />
          </div>
        </div>
        <USkeleton class="hidden h-8 w-28 shrink-0 rounded-md sm:block" />
      </div>
    </div>

    <ErrorRetryAlert
      v-else-if="showError"
      class="m-4"
      title="Não foi possível carregar a equipe"
      @retry="retry"
    />

    <template v-else>
      <UEmpty
        v-if="!members.length"
        class="py-10"
        icon="i-lucide-users"
        title="Nenhum membro na equipe"
        description="Os membros da conta aparecem aqui automaticamente."
        variant="naked"
        :actions="canManageMembers ? [{ label: 'Convidar', onClick: openInvite }] : undefined"
      />

      <UEmpty
        v-else-if="!visibleMembers.length"
        class="py-10"
        icon="i-lucide-search-x"
        title="Nenhum membro encontrado"
        description="Ajuste a busca ou limpe o filtro de departamento."
        variant="naked"
        :actions="hasActiveFilters ? [{ label: 'Limpar filtros', color: 'neutral', variant: 'outline', onClick: clearFilters }] : undefined"
      />

      <EquipeMembersList
        v-else
        :members="visibleMembers"
        :can-manage="canManageMembers"
        @update:role="onRoleUpdate"
        @remove="askRemove"
      />
    </template>
  </DataTablePanelList>

  <UModal
    v-if="canManageMembers"
    v-model:open="inviteOpen"
    title="Convidar membro"
    description="Crie o acesso com nome, e-mail, senha e papel na conta."
  >
    <template #body>
      <form id="invite-member-form" class="space-y-4" @submit.prevent="submitInvite">
        <UFormField label="Nome" name="name" required>
          <UInput v-model="inviteName" class="w-full" autofocus />
        </UFormField>
        <UFormField label="E-mail" name="email" required>
          <UInput v-model="inviteEmail" type="email" class="w-full" />
        </UFormField>
        <UFormField
          label="Senha"
          name="password"
          required
          description="Mínimo de 8 caracteres."
        >
          <UInput v-model="invitePassword" type="password" class="w-full" />
        </UFormField>
        <UFormField label="Papel" name="role" required>
          <USelect
            v-model="inviteRole"
            :items="roleItems"
            value-key="value"
            label-key="label"
            class="w-full"
          />
        </UFormField>
      </form>
    </template>
    <template #footer="{ close }">
      <UButton
        label="Cancelar"
        color="neutral"
        variant="outline"
        @click="close"
      />
      <UButton
        type="submit"
        form="invite-member-form"
        label="Convidar"
        :loading="inviting"
        :disabled="!inviteName.trim() || !inviteEmail.trim() || invitePassword.length < 8"
      />
    </template>
  </UModal>

  <UModal
    v-if="canManageMembers"
    v-model:open="removeOpen"
    title="Remover membro"
    :description="pendingRemove ? `Remover ${pendingRemove.name} desta conta? Essa ação não pode ser desfeita.` : 'Remover membro.'"
  >
    <template #footer="{ close }">
      <UButton
        label="Cancelar"
        color="neutral"
        variant="outline"
        @click="close"
      />
      <UButton
        label="Remover"
        color="error"
        :loading="removing"
        @click="confirmRemove"
      />
    </template>
  </UModal>
</template>
