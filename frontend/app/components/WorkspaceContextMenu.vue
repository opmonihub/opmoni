<script setup lang="ts">
const { user } = useAuth()
const hasOffices = computed(() => (user.value?.accounts.length ?? 0) > 0)
const hasCompanies = computed(() => (user.value?.companies.length ?? 0) > 0)
</script>

<template>
  <div class="flex w-full min-w-0 flex-col gap-3">
    <div v-if="hasOffices" class="space-y-1.5">
      <p class="text-muted px-1 text-xs font-medium tracking-wide uppercase">
        Escritórios
      </p>
      <AccountMenu />
    </div>
    <div v-if="hasCompanies" class="space-y-1.5">
      <p class="text-muted px-1 text-xs font-medium tracking-wide uppercase">
        Empresas
      </p>
      <CompanyMenu />
    </div>
  </div>
</template>
