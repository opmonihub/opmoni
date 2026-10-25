<script setup lang="ts">
definePageMeta({ middleware: ['auth', 'super-admin'] })

const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const context = useOfficeErpContext()
const checking = ref(true)
const message = ref('')

function identityStamp() {
  return JSON.stringify([user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null])
}

onMounted(async () => {
  const originalIdentity = identityStamp()
  if (currentAccount.value?.status !== 'active' || currentAccount.value.role !== 'admin') {
    message.value = 'Selecione manualmente um escritório ativo em que sua conta tenha perfil administrador para acessar a gestão comercial.'
    checking.value = false
    return
  }
  try {
    await context.refresh()
    if (originalIdentity !== identityStamp()) return
    if (context.capabilities.value?.can_read) {
      await navigateTo({ path: '/gestao-erp/planos', query: { origem: 'admin-erp' } }, { replace: true })
      return
    }
    message.value = 'O escritório selecionado não autorizou a consulta comercial. Nenhum escritório foi selecionado automaticamente.'
  } catch {
    if (originalIdentity === identityStamp())
      message.value = 'Não foi possível confirmar o escritório selecionado. Tente novamente pela área do escritório.'
  } finally {
    if (originalIdentity === identityStamp()) checking.value = false
  }
})
</script>

<template>
  <div class="p-4 sm:p-6">
    <UAlert
      v-if="checking"
      color="neutral"
      title="Confirmando o escritório selecionado"
      description="A área global não escolhe um escritório em seu nome."
    />
    <UAlert
      v-else
      color="warning"
      title="Assinaturas ERP foram movidas para o contexto do escritório"
    >
      <template #description>
        <div class="flex flex-col gap-3">
          <p>{{ message }}</p>
          <UButton
            to="/selecionar-escritorio"
            color="neutral"
            variant="outline"
            class="w-fit"
          >
            Escolher escritório
          </UButton>
        </div>
      </template>
    </UAlert>
  </div>
</template>
