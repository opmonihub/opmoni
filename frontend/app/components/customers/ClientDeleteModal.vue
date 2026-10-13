<script setup lang="ts">
defineOptions({ inheritAttrs: false })

const props = defineProps<{
  open: boolean
  client?: { id: number, name: string } | null
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'deleted': [id: number]
}>()

const isOpen = computed({
  get: () => props.open,
  set: value => emit('update:open', value)
})

const { remove } = useClients()
const toast = useToast()

const confirmation = ref('')
const deleting = ref(false)

const matchesName = computed(() => {
  if (!props.client) return false
  return confirmation.value.trim() === props.client.name.trim()
})

watch(isOpen, (open) => {
  if (!open) confirmation.value = ''
})

async function onDelete() {
  if (!props.client || !matchesName.value) return
  deleting.value = true
  try {
    await remove(props.client.id)
    toast.add({ title: 'Cliente excluído da carteira', color: 'success' })
    emit('deleted', props.client.id)
    isOpen.value = false
  } catch {
    toast.add({ title: 'Não foi possível excluir o cliente', color: 'error' })
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <UModal
    v-bind="$attrs"
    v-model:open="isOpen"
    title="Excluir cliente"
    :description="client ? `Excluir ${client.name} da carteira` : 'Excluir cliente da carteira'"
  >
    <template #body>
      <div class="space-y-4">
        <UAlert
          color="error"
          variant="subtle"
          title="Exclusão lógica"
          description="O cliente sai da carteira e o certificado A1 ativo será eliminado. Digite o nome exato do cliente para confirmar."
        />
        <UFormField label="Nome do cliente para confirmar" name="confirmation">
          <UInput v-model="confirmation" :placeholder="client?.name ?? 'Nome do cliente'" class="w-full" />
        </UFormField>
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton
          label="Cancelar"
          color="neutral"
          variant="subtle"
          @click="isOpen = false"
        />
        <UButton
          label="Excluir cliente"
          color="error"
          variant="solid"
          :loading="deleting"
          :disabled="!matchesName"
          @click="onDelete"
        />
      </div>
    </template>
  </UModal>
</template>
