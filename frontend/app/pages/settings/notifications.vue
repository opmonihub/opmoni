<script setup lang="ts">
const state = reactive<{ [key: string]: boolean }>({
  email: true,
  desktop: false,
  product_updates: true,
  weekly_digest: false,
  important_updates: true
})

const sections = [{
  title: 'Canais de notificação',
  description: 'Por onde você quer ser avisado?',
  fields: [{
    name: 'email',
    label: 'Email',
    description: 'Receber um resumo diário por email.'
  }, {
    name: 'desktop',
    label: 'Desktop',
    description: 'Receber notificações no navegador.'
  }]
}, {
  title: 'Atualizações da conta',
  description: 'Avisos sobre a conta e o produto.',
  fields: [{
    name: 'weekly_digest',
    label: 'Resumo semanal',
    description: 'Receber um resumo semanal por email.'
  }, {
    name: 'product_updates',
    label: 'Novidades',
    description: 'Receber um email mensal com novidades.'
  }, {
    name: 'important_updates',
    label: 'Avisos importantes',
    description: 'Receber emails sobre segurança e manutenção.'
  }]
}]

async function onChange() {
  // Preferências locais até a API de notificações existir.
}
</script>

<template>
  <div v-for="(section, index) in sections" :key="index">
    <UPageCard
      :title="section.title"
      :description="section.description"
      variant="naked"
      class="mb-4"
    />

    <UPageCard variant="subtle" :ui="{ container: 'divide-y divide-default' }">
      <UFormField
        v-for="field in section.fields"
        :key="field.name"
        :name="field.name"
        :label="field.label"
        :description="field.description"
        class="flex items-center justify-between not-last:pb-4 gap-2"
      >
        <USwitch
          v-model="state[field.name]"
          @update:model-value="onChange"
        />
      </UFormField>
    </UPageCard>
  </div>
</template>
