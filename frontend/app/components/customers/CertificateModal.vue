<script setup lang="ts">
import type { Client, ClientCertificateCapture } from '~/types/client'

defineOptions({ inheritAttrs: false })

const props = defineProps<{
  open: boolean
  client?: Client | null
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'saved': [client: Client]
}>()

const isOpen = computed({
  get: () => props.open,
  set: value => emit('update:open', value)
})

const { uploadCertificate, removeCertificate } = useClients()
const toast = useToast()

const file = shallowRef<File | null>(null)
const password = ref('')
const uploading = ref(false)
const removing = ref(false)
const confirmingRemove = ref(false)

const canSubmit = computed(() => !!props.client && !!file.value && password.value.length > 0)

/**
 * O aviso do que o upload fez com a captura imediata.
 *
 * O texto afirma o que o pedido fez — enfileirou, ficou de fora por bloqueio,
 * ou não tinha como consultar — e nunca o que o lote vai trazer. É a mesma
 * disciplina de `captureQueuedCopy` do painel fiscal: prometer documento a um
 * cliente que a captura não alcança é a mentira que o motivo de atenção existe
 * para corrigir.
 */
function captureToast(capture: ClientCertificateCapture | undefined): { title: string, description: string, color: 'warning' | 'neutral' } | null {
  if (!capture) return null

  if (capture.status === 'queued') {
    return {
      title: 'Captura de documentos enfileirada',
      description: 'A consulta deste cliente entrou na fila para cada fonte habilitada. O que o lote encontrar aparece na tabela de documentos quando ele terminar.',
      color: 'neutral'
    }
  }

  if (capture.status === 'blocked') {
    const fim = capture.blocked_until ? ` A janela termina em ${formatDate(capture.blocked_until)}.` : ''
    return {
      title: 'Captura ainda bloqueada pelo fisco',
      description: `O certificado foi guardado, mas este CNPJ está dentro da janela de bloqueio e nenhuma consulta foi enfileirada.${fim}`,
      color: 'warning'
    }
  }

  const motivo = capture.reason ? ` (${capture.reason})` : ''
  return {
    title: 'Captura não enfileirada',
    description: `O certificado foi guardado, mas ele não serve para consultar o fisco agora${motivo}. A lista de atenção do painel fiscal mostra o motivo.`,
    color: 'warning'
  }
}

async function submitCertificate() {
  if (!props.client || !file.value || !password.value) return
  uploading.value = true
  try {
    const response = await uploadCertificate(props.client.id, file.value, password.value)
    emit('saved', response.data)
    isOpen.value = false
    toast.add({ title: 'Certificado A1 atualizado', color: 'success' })
    const aviso = captureToast(response.meta?.capture)
    if (aviso) toast.add(aviso)
  } catch {
    toast.add({ title: 'Não foi possível validar o certificado', description: 'Confira o arquivo e a senha.', color: 'error' })
  } finally {
    password.value = ''
    file.value = null
    uploading.value = false
  }
}

async function confirmRemove() {
  if (!props.client) return
  removing.value = true
  try {
    await removeCertificate(props.client.id)
    emit('saved', { ...props.client, certificate: null, certificate_status: 'missing' })
    confirmingRemove.value = false
    isOpen.value = false
    toast.add({ title: 'Certificado removido', color: 'success' })
  } catch {
    toast.add({ title: 'Não foi possível remover o certificado', color: 'error' })
  } finally {
    removing.value = false
  }
}

watch(isOpen, (open) => {
  if (!open) {
    password.value = ''
    file.value = null
    confirmingRemove.value = false
  }
})
</script>

<template>
  <UModal
    v-bind="$attrs"
    v-model:open="isOpen"
    title="Certificado digital A1"
    :description="client ? `Gerenciar certificado de ${client.name}` : 'Gerenciar certificado digital'"
  >
    <template #body>
      <div class="space-y-4">
        <div v-if="client?.certificate" class="space-y-1 text-sm">
          <p class="font-medium">
            Certificado atual
          </p>
          <p class="text-muted">
            {{ client.certificate.subject }} ({{ client.certificate.serial_number }})
          </p>
          <p class="text-muted">
            Válido até {{ client.certificate.valid_until }}
          </p>
        </div>
        <p v-else class="text-sm text-muted">
          Nenhum certificado cadastrado para este cliente.
        </p>

        <USeparator />

        <UFormField label="Arquivo do certificado (.pfx ou .p12)" name="certificate">
          <UFileUpload
            v-model="file"
            accept=".pfx,.p12"
            label="Selecionar arquivo"
            description="Arraste o arquivo ou clique para selecionar"
            class="w-full"
          />
        </UFormField>

        <UFormField label="Senha do certificado" name="password">
          <UInput
            v-model="password"
            type="password"
            autocomplete="off"
            placeholder="Senha do arquivo"
            class="w-full"
          />
        </UFormField>

        <div v-if="client?.certificate && !confirmingRemove" class="flex justify-start">
          <UButton
            label="Remover certificado"
            icon="i-lucide-trash-2"
            color="error"
            variant="ghost"
            type="button"
            @click="confirmingRemove = true"
          />
        </div>

        <UAlert
          v-if="confirmingRemove"
          color="error"
          variant="subtle"
          title="Remover certificado?"
          description="O certificado atual será excluído e precisará ser cadastrado novamente."
        />
        <div v-if="confirmingRemove" class="flex justify-end gap-2">
          <UButton
            label="Manter"
            color="neutral"
            variant="subtle"
            type="button"
            @click="confirmingRemove = false"
          />
          <UButton
            label="Confirmar remoção"
            color="error"
            variant="solid"
            type="button"
            :loading="removing"
            @click="confirmRemove"
          />
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton
          label="Cancelar"
          color="neutral"
          variant="subtle"
          type="button"
          @click="isOpen = false"
        />
        <UButton
          label="Enviar certificado"
          color="primary"
          variant="solid"
          type="button"
          :loading="uploading"
          :disabled="!canSubmit"
          @click="submitCertificate"
        />
      </div>
    </template>
  </UModal>
</template>
