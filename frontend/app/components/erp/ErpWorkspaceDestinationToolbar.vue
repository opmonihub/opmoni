<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'
import type { ComponentPublicInstance } from 'vue'

const props = defineProps<{ items: NavigationMenuItem[] }>()
const toolbar = ref<ComponentPublicInstance | null>(null)

function revealDestination(linkElement: HTMLElement | null | undefined) {
  const element = toolbar.value?.$el as HTMLElement | undefined
  if (!element || !linkElement) return
  const bounds = element.getBoundingClientRect()
  const link = linkElement.getBoundingClientRect()
  if (link.left < bounds.left || link.right > bounds.right)
    linkElement.scrollIntoView({ block: 'nearest', inline: 'nearest' })
}

function revealActiveDestination() {
  const element = toolbar.value?.$el as HTMLElement | undefined
  revealDestination(element?.querySelector<HTMLElement>('a[aria-current="page"]'))
}

function revealFocusedDestination(event: FocusEvent) {
  revealDestination((event.target as HTMLElement).closest('a'))
}

onMounted(revealActiveDestination)
watch(() => props.items, revealActiveDestination, { flush: 'post' })
</script>

<template>
  <UDashboardToolbar ref="toolbar" class="min-w-0" @focusin="revealFocusedDestination">
    <UNavigationMenu
      :items="items"
      highlight
      class="-mx-1 w-max min-w-max flex-none"
      :ui="{ list: 'flex-nowrap', item: 'shrink-0' }"
    />
  </UDashboardToolbar>
</template>
