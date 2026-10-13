<script setup lang="ts">
definePageMeta({ layout: 'access' })
const { user } = useAuth()
const active = computed(() => user.value?.accounts.filter(a => a.status === 'active') || [])
</script>

<template>
  <UCard class="space-y-4">
    <h1 class="text-2xl font-semibold">
      Acesso ao escritório indisponível
    </h1><p class="my-4 text-muted">
      {{ user?.current_account?.status === 'suspended' ? 'O escritório selecionado está suspenso. Procure o administrador da plataforma.' : 'Você não possui um escritório ativo disponível. Procure o administrador da plataforma.' }}
    </p><AccountMenu v-if="active.length" class="mb-4" /><UButton
      v-if="user?.is_super_admin"
      to="/admin/contas"
      label="Administrar contas"
      variant="outline"
      class="mb-4"
    /><AccessLogout />
  </UCard>
</template>
