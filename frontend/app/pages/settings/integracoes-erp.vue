<script setup lang="ts">
definePageMeta({ middleware: ['auth', 'account-erp-admin'] })
</script>

<template>
  <div class="flex flex-col gap-4">
    <ErpAccountingMappingApproval mode="office" />
    <section class="flex flex-col gap-3 rounded-lg border border-default p-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h2 class="font-semibold">
          Documentos recebidos
        </h2>
        <p class="mt-1 max-w-3xl text-sm text-muted">
          Após a aprovação e a entrega, consulte o documento no Fiscal deste escritório e use o detalhe e o download XML privados com verificação de integridade. A disponibilidade do XML não confirma manifestação do destinatário nem validação fiscal conclusiva.
        </p>
      </div>
      <UButton
        label="Abrir documentos fiscais"
        icon="i-lucide-file-search"
        color="neutral"
        variant="outline"
        to="/fiscal/documentos"
      />
    </section>
  </div>
</template>
