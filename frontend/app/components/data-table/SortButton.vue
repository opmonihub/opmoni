<script setup lang="ts">
const props = defineProps<{
  label: string
  sorted?: 'asc' | 'desc' | false
}>()

const emit = defineEmits<{ toggle: [] }>()

const icon = computed(() => {
  if (props.sorted === 'asc') return 'i-lucide-arrow-up-narrow-wide'
  if (props.sorted === 'desc') return 'i-lucide-arrow-down-wide-narrow'
  return 'i-lucide-arrow-up-down'
})
</script>

<template>
  <UButton
    color="neutral"
    variant="ghost"
    :label="label"
    :icon="icon"
    class="-mx-1.5 shrink-0 whitespace-nowrap"
    :ui="{ label: 'whitespace-nowrap' }"
    @click="emit('toggle')"
  />
</template>
