<script setup lang="ts">
import { clientListMenuItems } from '~/utils/clientListMenu'

defineOptions({ inheritAttrs: false })

const props = defineProps<{
  canManageTags: boolean
  compact?: boolean
}>()

const emit = defineEmits<{
  'open-tags': [intent: 'catalog', focusCreate: boolean]
}>()

const items = computed(() => clientListMenuItems({
  canManageTags: props.canManageTags,
  onTags: focusCreate => emit('open-tags', 'catalog', focusCreate)
}))
</script>

<template>
  <UDropdownMenu
    v-if="items.length"
    :items="items"
    :content="{ align: 'end' }"
    :ui="{ content: 'min-w-56' }"
  >
    <UButton
      v-bind="$attrs"
      :label="compact ? undefined : 'Tags'"
      icon="i-lucide-tags"
      :trailing-icon="compact ? undefined : 'i-lucide-chevron-down'"
      color="neutral"
      variant="outline"
      aria-label="Gerenciar tags"
    />
  </UDropdownMenu>
</template>
