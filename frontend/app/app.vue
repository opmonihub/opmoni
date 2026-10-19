<script setup lang="ts">
import { pt_br } from '@nuxt/ui/locale'

useHead({ htmlAttrs: { lang: 'pt-BR' }, title: 'Opmoni' })
</script>

<template>
  <UApp :locale="pt_br">
    <NuxtLoadingIndicator /><NuxtLayout><NuxtPage /></NuxtLayout>
  </UApp>
</template>
