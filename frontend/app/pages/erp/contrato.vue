<script setup lang="ts">
definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Contrato ERP · Opmoni' })

interface ErpSubscriptionPayload {
  data: {
    status: 'pending' | 'active' | 'past_due' | 'canceled'
    plan: { name: string, modules: Array<{ module_key: string }> }
    company: { name: string, status: string }
  }
}

const { $api } = useNuxtApp()
const { currentCompany, currentAccount } = useAuth()
const loading = ref(true)
const error = ref<unknown>(null)
const subscription = ref<ErpSubscriptionPayload['data'] | null>(null)
let generation = 0

watch([() => currentCompany.value?.id, () => currentAccount.value?.id], () => {
  generation++
  subscription.value = null
  error.value = null
  loading.value = false
}, { flush: 'sync' })
onScopeDispose(() => {
  generation++
})

const statusLabel = computed(() => {
  const map: Record<string, string> = {
    pending: 'Pendente de habilitação',
    active: 'Ativo',
    past_due: 'Em atraso',
    canceled: 'Cancelado'
  }
  return subscription.value ? map[subscription.value.status] ?? subscription.value.status : ''
})

async function load() {
  const version = ++generation
  loading.value = true
  error.value = null
  try {
    const response = await $api<ErpSubscriptionPayload>('/erp/subscription')
    if (version === generation)
      subscription.value = response.data
  } catch (err) {
    if (version === generation)
      error.value = err
  } finally {
    if (version === generation)
      loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-6 p-6">
    <div class="flex items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">
          Contrato ERP
        </h1>
        <p class="text-muted text-sm">
          Status real do contrato da empresa selecionada. Nenhum pagamento é processado nesta etapa.
        </p>
      </div>
      <UButton
        color="neutral"
        variant="outline"
        :loading="loading"
        @click="load"
      >
        Atualizar
      </UButton>
    </div>
    <UAlert v-if="error" color="error" title="Não foi possível carregar o contrato">
      <template #description>
        <UButton variant="link" @click="load">
          Tentar novamente
        </UButton>
      </template>
    </UAlert>
    <UCard v-if="subscription">
      <dl class="grid gap-3 text-sm">
        <div class="flex justify-between gap-4">
          <dt class="text-muted">
            Empresa
          </dt>
          <dd>{{ subscription.company.name }}</dd>
        </div>
        <div class="flex justify-between gap-4">
          <dt class="text-muted">
            Plano
          </dt>
          <dd>{{ subscription.plan.name }}</dd>
        </div>
        <div class="flex justify-between gap-4">
          <dt class="text-muted">
            Situação
          </dt>
          <dd>{{ statusLabel }}</dd>
        </div>
        <div>
          <dt class="text-muted mb-1">
            Módulos do plano
          </dt>
          <dd class="flex flex-wrap gap-1">
            <UBadge v-for="mod in subscription.plan.modules" :key="mod.module_key" variant="subtle">
              {{ mod.module_key }}
            </UBadge>
          </dd>
        </div>
      </dl>
    </UCard>
    <USkeleton v-else-if="loading" class="h-40 w-full" />
  </div>
</template>
