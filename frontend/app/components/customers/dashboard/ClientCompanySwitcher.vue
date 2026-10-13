<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import type { ClientSheet } from '~/types/client'

const props = defineProps<{
  modelValue: number | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: number | null]
}>()

const { list } = useClients()
const toast = useToast()

const clients = ref<ClientSheet[]>([])
const loading = ref(true)

const selected = computed(() =>
  props.modelValue == null
    ? null
    : clients.value.find(client => client.id === props.modelValue) ?? null
)

const label = computed(() => selected.value?.name ?? 'Todas as empresas')

const items = computed<DropdownMenuItem[][]>(() => {
  const rows: DropdownMenuItem[] = [
    {
      label: 'Todas as empresas',
      icon: props.modelValue == null ? 'i-lucide-check' : 'i-lucide-building-2',
      onSelect() {
        emit('update:modelValue', null)
      }
    }
  ]

  for (const client of clients.value.slice(0, 80)) {
    rows.push({
      label: client.name,
      description: client.tax_id ?? undefined,
      icon: client.id === props.modelValue ? 'i-lucide-check' : 'i-lucide-building-2',
      onSelect() {
        emit('update:modelValue', client.id)
      }
    })
  }

  return [rows]
})

onMounted(async () => {
  try {
    const response = await list({
      sheet: 1,
      sort: 'name',
      direction: 'asc'
    })
    clients.value = response.data
  } catch {
    toast.add({ title: 'Não foi possível carregar as empresas', color: 'error' })
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'end', collisionPadding: 12 }"
    :ui="{ content: 'w-72 max-h-80 overflow-y-auto' }"
  >
    <UButton
      icon="i-lucide-building-2"
      :label="label"
      trailing-icon="i-lucide-chevrons-up-down"
      color="neutral"
      variant="outline"
      :loading="loading"
      class="max-w-72 shadow-xs data-[state=open]:bg-elevated"
      :ui="{
        leadingIcon: 'text-primary',
        label: 'truncate',
        trailingIcon: 'text-dimmed'
      }"
    />
  </UDropdownMenu>
</template>
