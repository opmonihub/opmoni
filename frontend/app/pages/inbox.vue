<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { breakpointsTailwind } from '@vueuse/core'
import { apiStatus } from '~/composables/useApiError'
import type { Mail } from '~/types'

const tabItems = [{
  label: 'Todas',
  value: 'all'
}, {
  label: 'Não lidas',
  value: 'unread'
}]
const selectedTab = ref('all')

const { $api } = useNuxtApp()

// O Laravel ainda não expõe e-mails: 404 entra como lista vazia para a tela não
// quebrar. O mesmo vale para as notificações do slideover.
const { data: mails } = await useAsyncData<Mail[]>('inbox-mails', async () => {
  try {
    return await $api<Mail[]>('/mails')
  } catch (error) {
    if (apiStatus(error) !== 404) {
      throw error
    }

    return []
  }
}, { default: () => [] })

// Filter mails based on the selected tab
const filteredMails = computed(() => {
  if (selectedTab.value === 'unread') {
    return mails.value.filter(mail => !!mail.unread)
  }

  return mails.value
})

const selectedMail = ref<Mail | null>()

const isMailPanelOpen = computed({
  get() {
    return !!selectedMail.value
  },
  set(value: boolean) {
    if (!value) {
      selectedMail.value = null
    }
  }
})

// Reset selected mail if it's not in the filtered mails
watch(filteredMails, () => {
  if (!filteredMails.value.find(mail => mail.id === selectedMail.value?.id)) {
    selectedMail.value = null
  }
})

const breakpoints = useBreakpoints(breakpointsTailwind)
const isMobile = breakpoints.smaller('lg')
</script>

<template>
  <UDashboardPanel
    id="inbox-1"
    :default-size="25"
    :min-size="20"
    :max-size="30"
    resizable
  >
    <UDashboardNavbar title="Caixa de entrada">
      <template #leading>
        <UDashboardSidebarCollapse />
      </template>
      <template #trailing>
        <UBadge :label="filteredMails.length" variant="subtle" />
      </template>

      <template #right>
        <UTabs
          v-model="selectedTab"
          :items="tabItems"
          :content="false"
          size="xs"
        />
      </template>
    </UDashboardNavbar>
    <InboxList v-model="selectedMail" :mails="filteredMails" />
  </UDashboardPanel>

  <InboxMail v-if="selectedMail" :mail="selectedMail" @close="selectedMail = null" />
  <div v-else class="hidden lg:flex flex-1 items-center justify-center">
    <UIcon name="i-lucide-inbox" class="size-32 text-dimmed" />
  </div>

  <ClientOnly>
    <USlideover v-if="isMobile" v-model:open="isMailPanelOpen">
      <template #content>
        <InboxMail v-if="selectedMail" :mail="selectedMail" @close="selectedMail = null" />
      </template>
    </USlideover>
  </ClientOnly>
</template>
