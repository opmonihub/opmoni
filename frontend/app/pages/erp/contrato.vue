<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Contrato ERP · Opmoni' })

interface ErpSubscriptionPayload {
  data: {
    status: 'pending' | 'active' | 'past_due' | 'canceled'
    plan: { name: string, modules: Array<{ module_key: string }> }
    company: { name: string, status: string }
  }
}

const { $api } = useNuxtApp()
const loading = ref(true)
const error = ref<unknown>(null)
const subscription = ref<ErpSubscriptionPayload['data'] | null>(null)

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
  loading.value = true
  error.value = null
  try {
    const response = await $api<ErpSubscriptionPayload>('/erp/subscription')
    subscription.value = response.data
  } catch (err) {
    error.value = err
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-6 p-6">
    <div>
      <h1 class="text-2xl font-semibold">
        Contrato ERP
      </h1>
      <p class="text-muted text-sm">
        Status real do contrato da empresa selecionada. Nenhum pagamento é processado nesta etapa.
      </p>
    </div>
    <UAlert v-if="error" color="error" title="Não foi possível carregar o contrato">
      <template #description>
        <UButton variant="link" @click="load">
          Tentar novamente
        </UButton>
      </template>
    </UAlert>
    <UCard v-else-if="subscription">
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
