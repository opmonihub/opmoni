<script setup lang="ts">
definePageMeta({ layout: 'erp', middleware: 'auth' })

const { currentCompany } = useAuth()
</script>

<template>
  <UDashboardPanelContent class="space-y-4 p-4 sm:p-6">
    <div>
      <h1 class="text-highlighted text-2xl font-semibold">
        {{ currentCompany?.name || 'Empresa' }}
      </h1>
      <p class="text-muted mt-1 text-sm">
        Espaço ERP unificado. Os módulos operacionais serão habilitados conforme a liberação comercial.
      </p>
    </div>
    <UAlert
      color="neutral"
      variant="subtle"
      icon="i-lucide-info"
      title="Base de empresa ativa"
      description="Contexto de empresa, delegação e navegação compartilhada estão disponíveis. Escritas operacionais permanecem bloqueadas até a ativação comercial."
    />
  </UDashboardPanelContent>
</template>
