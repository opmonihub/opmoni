<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type { ErpContactNote, ErpContactNoteAttachment } from '#shared/types/erpContactNotes'
import { useErpContactNotes } from '~/composables/useErpContactNotes'

const props = defineProps<{ contactId: number }>()
const contactId = computed(() => props.contactId)
const notesApi = useErpContactNotes(contactId)
const {
  notes: rows,
  selectedNote,
  page,
  filter,
  listMeta,
  attachmentsMeta,
  pending,
  hasLoaded,
  loadingDetail,
  saving,
  archiving,
  uploading,
  archivingAttachment,
  mutationPending,
  error,
  fieldErrors,
  stale,
  authorityBlocked,
  permissions,
  contextKey
} = notesApi

const createDraft = reactive({ heading: '', description: '', is_private: false })
const editDraft = reactive({ heading: '', description: '', is_private: false })
const selectedFile = ref<File | null>(null)
const selectedNoteId = ref<number | null>(null)
const removalTarget = ref<{ kind: 'note' | 'attachment', noteId: number, attachment?: ErpContactNoteAttachment } | null>(null)
const removalOpen = computed({
  get: () => removalTarget.value !== null,
  set: (open: boolean) => {
    if (!open && !mutationPending.value)
      removalTarget.value = null
  }
})
const canCreate = computed(() => permissions.store.value && !authorityBlocked.value)
const canShow = computed(() => permissions.show.value && !authorityBlocked.value)
const canEditSelected = computed(() => permissions.update.value && !authorityBlocked.value && !loadingDetail.value && selectedNote.value?.id === selectedNoteId.value && selectedNote.value.state === 'active')
const canChangeSelectedPrivacy = computed(() => canEditSelected.value && selectedNote.value?.can_change_privacy === true)
const canUploadSelected = computed(() => permissions.attachmentStore.value && !authorityBlocked.value && !loadingDetail.value && selectedNote.value?.id === selectedNoteId.value && selectedNote.value.state === 'active')
const canDownloadSelected = computed(() => permissions.show.value && permissions.attachmentDownload.value && !authorityBlocked.value && !loadingDetail.value && selectedNote.value?.id === selectedNoteId.value && selectedNote.value.state === 'active')
const canArchiveSelected = computed(() => permissions.archive.value && !authorityBlocked.value && !loadingDetail.value && selectedNote.value?.id === selectedNoteId.value)
const pageCount = computed(() => listMeta.value?.last_page ?? 1)
const attachmentPageCount = computed(() => attachmentsMeta.value?.last_page ?? 1)

watch([
  () => selectedNote.value?.id,
  () => selectedNote.value?.heading,
  () => selectedNote.value?.description,
  () => selectedNote.value?.is_private
], ([noteId, heading, description, isPrivate]) => {
  if (noteId === undefined || noteId !== selectedNoteId.value)
    return
  editDraft.heading = heading ?? ''
  editDraft.description = description ?? ''
  editDraft.is_private = isPrivate ?? false
}, { immediate: true })

function clearLocalState() {
  selectedNoteId.value = null
  selectedFile.value = null
  removalTarget.value = null
  resetCreateDraft()
  resetEditDraft()
}

watch(contextKey, () => {
  clearLocalState()
}, { flush: 'sync' })

watch(authorityBlocked, (blocked) => {
  if (blocked)
    clearLocalState()
}, { flush: 'sync' })

function resetCreateDraft() {
  createDraft.heading = ''
  createDraft.description = ''
  createDraft.is_private = false
}

function resetEditDraft() {
  editDraft.heading = ''
  editDraft.description = ''
  editDraft.is_private = false
}

async function openNote(noteId: number) {
  const requestContext = contextKey.value
  if (selectedNoteId.value !== noteId) {
    selectedFile.value = null
    resetEditDraft()
  }
  selectedNoteId.value = noteId
  await notesApi.selectNote(noteId)
  if (contextKey.value !== requestContext || selectedNoteId.value !== noteId)
    return
}

async function changeNotesPage(nextPage: number) {
  await notesApi.refresh(nextPage)
}

async function changeAttachmentsPage(nextPage: number) {
  const noteId = selectedNoteId.value
  const requestContext = contextKey.value
  if (noteId === null)
    return
  await notesApi.selectNote(noteId, nextPage)
  if (contextKey.value !== requestContext || selectedNoteId.value !== noteId)
    return
}

async function submitCreate() {
  const requestContext = contextKey.value
  const payload = {
    heading: createDraft.heading.trim() || null,
    description: createDraft.description.trim() || null,
    is_private: createDraft.is_private
  }
  const saved = await notesApi.createNote(payload)
  if (contextKey.value !== requestContext)
    return
  if (saved)
    resetCreateDraft()
}

async function submitUpdate() {
  const note = selectedNote.value
  const requestContext = contextKey.value
  if (!note || note.id !== selectedNoteId.value || loadingDetail.value)
    return
  const noteId = note.id
  const payload = {
    heading: editDraft.heading.trim() || null,
    description: editDraft.description.trim() || null,
    ...(canChangeSelectedPrivacy.value ? { is_private: editDraft.is_private } : {})
  }
  await notesApi.updateNote(noteId, payload)
  if (contextKey.value !== requestContext || selectedNoteId.value !== noteId)
    return
}

async function submitUpload() {
  const noteId = selectedNoteId.value
  const file = selectedFile.value
  const requestContext = contextKey.value
  if (!file || noteId === null || loadingDetail.value || selectedNote.value?.id !== noteId)
    return
  const uploaded = await notesApi.uploadAttachment(noteId, file)
  if (contextKey.value !== requestContext || selectedNoteId.value !== noteId)
    return
  if (uploaded)
    selectedFile.value = null
}

function askArchiveNote(note: ErpContactNote) {
  if (canArchiveSelected.value || (permissions.archive.value && !authorityBlocked.value))
    removalTarget.value = { kind: 'note', noteId: note.id }
}

function askArchiveAttachment(attachment: ErpContactNoteAttachment) {
  if (permissions.attachmentArchive.value && !authorityBlocked.value && selectedNote.value)
    removalTarget.value = { kind: 'attachment', noteId: selectedNote.value.id, attachment }
}

async function confirmRemoval() {
  const target = removalTarget.value
  const requestContext = contextKey.value
  if (!target)
    return
  const accepted = target.kind === 'note'
    ? await notesApi.archiveNote(target.noteId)
    : target.attachment
      ? await notesApi.archiveAttachment(target.noteId, target.attachment.id)
      : false
  if (contextKey.value !== requestContext || removalTarget.value !== target)
    return
  if (accepted)
    removalTarget.value = null
}

function privacyError(note: ErpContactNote | null) {
  return note?.can_change_privacy ? fieldErrors.value.is_private?.[0] : undefined
}

function attachmentStatus(status: ErpContactNoteAttachment['status']) {
  if (status === 'ready') return 'Disponível'
  if (status === 'deletion_pending') return 'Aguardando remoção'
  return 'Indisponível'
}

function formatBytes(bytes: number) {
  return `${new Intl.NumberFormat('pt-BR').format(bytes)} bytes`
}

function formatDate(value: string | null) {
  if (!value)
    return '—'
  const date = new Date(value)
  return Number.isNaN(date.valueOf()) ? '—' : new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(date)
}
</script>

<template>
  <section aria-labelledby="contact-notes-heading" class="min-w-0 space-y-5 py-2">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h3 id="contact-notes-heading" class="text-highlighted text-lg font-semibold">
          Notas e anexos
        </h3>
        <p class="text-muted mt-1 text-sm">
          Registros compartilhados ou privados e seus arquivos de apoio.
        </p>
      </div>
      <div class="flex flex-wrap gap-2" aria-label="Filtrar anotações">
        <UButton
          label="Ativas"
          :variant="filter === 'active' ? 'solid' : 'outline'"
          :disabled="pending || mutationPending"
          @click="notesApi.setFilter('active')"
        />
        <UButton
          label="Aguardando remoção"
          :variant="filter === 'deletion_pending' ? 'solid' : 'outline'"
          :disabled="pending || mutationPending"
          @click="notesApi.setFilter('deletion_pending')"
        />
      </div>
    </header>

    <UAlert
      v-if="error"
      color="error"
      variant="soft"
      :title="stale ? 'Exibindo os últimos dados carregados' : 'Não foi possível concluir'"
      :description="error"
      :close="false"
    />
    <UButton
      v-if="error && !hasLoaded && !authorityBlocked && permissions.index.value"
      label="Tentar carregar novamente"
      variant="outline"
      :disabled="pending"
      @click="notesApi.refresh()"
    />
    <div v-if="stale" role="status" class="text-warning text-sm">
      A consulta falhou. Os dados exibidos podem estar desatualizados.
      <UButton
        label="Tentar novamente"
        variant="link"
        size="xs"
        :disabled="pending"
        @click="notesApi.refresh()"
      />
    </div>
    <div v-if="authorityBlocked" role="alert" class="text-error text-sm">
      O acesso foi alterado. Feche este cadastro e confirme novamente a empresa selecionada.
    </div>

    <UForm
      v-if="canCreate"
      :state="createDraft"
      class="border-default grid gap-4 rounded-lg border p-4"
      @submit="submitCreate"
    >
      <h4 class="text-highlighted font-medium">
        Nova anotação
      </h4>
      <UFormField label="Título" name="heading" :error="fieldErrors.heading?.[0]">
        <UInput
          v-model="createDraft.heading"
          name="heading"
          maxlength="255"
          class="w-full"
        />
      </UFormField>
      <UFormField label="Anotação" name="description" :error="fieldErrors.description?.[0]">
        <UTextarea
          v-model="createDraft.description"
          name="description"
          :rows="4"
          maxlength="10000"
          class="w-full"
        />
      </UFormField>
      <UFormField name="is_private" :error="fieldErrors.is_private?.[0]">
        <UCheckbox v-model="createDraft.is_private" name="is_private" label="Manter esta anotação privada para mim" />
      </UFormField>
      <div class="flex justify-end">
        <UButton
          type="submit"
          label="Salvar anotação"
          icon="i-lucide-plus"
          :loading="saving"
          :disabled="mutationPending || authorityBlocked"
        />
      </div>
    </UForm>

    <div class="grid min-w-0 gap-5 xl:grid-cols-[minmax(16rem,0.9fr)_minmax(0,1.4fr)]">
      <section aria-label="Lista de anotações" class="min-w-0 space-y-3">
        <div v-if="pending && !hasLoaded" role="status" class="text-muted text-sm">
          Carregando anotações…
        </div>
        <div v-else-if="!permissions.index.value" class="text-muted border-default rounded-lg border p-4 text-sm">
          Seu acesso não permite consultar a lista de anotações deste contato.
        </div>
        <div v-else-if="hasLoaded && !rows.length" class="text-muted border-default rounded-lg border p-4 text-sm">
          {{ filter === 'active' ? 'Ainda não há anotações ativas.' : 'Não há remoções aguardando conclusão.' }}
        </div>
        <article v-for="note in rows" :key="note.id" class="border-default rounded-lg border p-3">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <h4 class="text-highlighted truncate font-medium">
                {{ note.heading || 'Anotação sem título' }}
              </h4>
              <p class="text-muted mt-1 line-clamp-3 whitespace-pre-wrap text-sm">
                {{ note.description || 'Sem descrição.' }}
              </p>
              <p class="text-muted mt-2 text-xs">
                {{ note.is_private ? 'Privada' : 'Compartilhada' }} · {{ note.attachments_count }} {{ note.attachments_count === 1 ? 'arquivo' : 'arquivos' }} · {{ formatDate(note.updated_at) }}
              </p>
              <p v-if="note.state === 'deletion_pending'" class="text-warning mt-1 text-xs">
                Aguardando conclusão da remoção.
              </p>
            </div>
            <UBadge
              v-if="note.state === 'deletion_pending'"
              label="Pendente"
              color="warning"
              variant="subtle"
            />
          </div>
          <div class="mt-3 flex flex-wrap gap-2">
            <UButton
              v-if="canShow"
              :label="selectedNoteId === note.id ? 'Atualizar detalhes' : 'Ver detalhes'"
              size="sm"
              variant="outline"
              :disabled="loadingDetail || mutationPending || authorityBlocked"
              @click="openNote(note.id)"
            />
            <UButton
              v-if="permissions.archive.value"
              :label="note.state === 'deletion_pending' ? 'Tentar remoção novamente' : 'Solicitar remoção'"
              size="sm"
              color="error"
              variant="ghost"
              :disabled="mutationPending || authorityBlocked"
              @click="askArchiveNote(note)"
            />
          </div>
        </article>
        <UPagination
          v-if="listMeta && pageCount > 1"
          v-model:page="page"
          :total="listMeta.total"
          :items-per-page="listMeta.per_page"
          :disabled="pending || mutationPending"
          aria-label="Páginas de anotações"
          @update:page="changeNotesPage"
        />
      </section>

      <section aria-label="Detalhes e arquivos da anotação" class="border-default min-w-0 space-y-4 rounded-lg border p-4">
        <div v-if="!canShow" class="text-muted text-sm">
          É necessária permissão de consulta para abrir os detalhes e arquivos.
        </div>
        <div v-else-if="!selectedNote" class="text-muted text-sm">
          Selecione uma anotação para consultar o conteúdo e os arquivos.
        </div>
        <template v-else>
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <h4 class="text-highlighted break-words font-semibold">
                {{ selectedNote.heading || 'Anotação sem título' }}
              </h4>
              <p class="text-muted mt-1 text-xs">
                {{ selectedNote.is_private ? 'Privada' : 'Compartilhada' }} · Atualizada em {{ formatDate(selectedNote.updated_at) }}
              </p>
            </div>
            <UButton
              v-if="canArchiveSelected"
              :label="selectedNote.state === 'deletion_pending' ? 'Tentar remoção novamente' : 'Solicitar remoção'"
              color="error"
              variant="ghost"
              size="sm"
              :disabled="mutationPending"
              @click="askArchiveNote(selectedNote)"
            />
          </div>

          <p v-if="loadingDetail" role="status" class="text-muted text-sm">
            Atualizando detalhes…
          </p>

          <UForm
            v-if="canEditSelected"
            :state="editDraft"
            class="space-y-4"
            @submit="submitUpdate"
          >
            <UFormField label="Título" name="heading" :error="fieldErrors.heading?.[0]">
              <UInput
                v-model="editDraft.heading"
                name="heading"
                maxlength="255"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Anotação" name="description" :error="fieldErrors.description?.[0]">
              <UTextarea
                v-model="editDraft.description"
                name="description"
                :rows="4"
                maxlength="10000"
                class="w-full"
              />
            </UFormField>
            <UFormField v-if="selectedNote.can_change_privacy" name="is_private" :error="privacyError(selectedNote)">
              <UCheckbox
                v-model="editDraft.is_private"
                name="is_private"
                label="Manter esta anotação privada para mim"
                :disabled="!canChangeSelectedPrivacy || mutationPending"
              />
            </UFormField>
            <UFormField v-else name="is_private" label="Privacidade">
              <p class="text-muted text-sm">
                {{ selectedNote.is_private ? 'Esta anotação é privada.' : 'Esta anotação é compartilhada.' }} Somente quem a criou pode alterar essa opção.
              </p>
            </UFormField>
            <div class="flex justify-end">
              <UButton
                type="submit"
                label="Salvar alterações"
                :loading="saving"
                :disabled="mutationPending || authorityBlocked"
              />
            </div>
          </UForm>
          <div v-else class="border-default bg-elevated/40 rounded-md border p-3">
            <p class="whitespace-pre-wrap text-sm">
              {{ selectedNote.description || 'Esta anotação não possui descrição.' }}
            </p>
          </div>

          <div class="border-default border-t pt-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h5 class="text-highlighted font-medium">
                  Arquivos anexados
                </h5>
                <p class="text-muted mt-1 text-xs">
                  PDF, CSV, ZIP, DOC, DOCX, JPEG ou PNG. Limite de 1.000.000 bytes por arquivo.
                </p>
              </div>
              <UBadge :label="`${selectedNote.attachments_count} ativos`" color="neutral" variant="subtle" />
            </div>

            <div v-if="selectedNote.deletion_pending_attachments_count" class="text-warning mt-3 text-sm" role="status">
              {{ selectedNote.deletion_pending_attachments_count }} {{ selectedNote.deletion_pending_attachments_count === 1 ? 'arquivo aguarda' : 'arquivos aguardam' }} remoção.
            </div>

            <UForm
              v-if="canUploadSelected"
              :state="{ file: selectedFile }"
              class="mt-4 space-y-3"
              @submit="submitUpload"
            >
              <UFormField label="Selecionar arquivo" name="file" :error="fieldErrors.file?.[0]">
                <UFileUpload
                  v-model="selectedFile"
                  name="file"
                  accept=".pdf,.csv,.zip,.doc,.docx,.jpg,.jpeg,.png"
                  :file-image="false"
                  :disabled="uploading || mutationPending || authorityBlocked"
                  class="w-full"
                />
              </UFormField>
              <UButton
                type="submit"
                label="Enviar arquivo"
                icon="i-lucide-upload"
                :loading="uploading"
                :disabled="!selectedFile || mutationPending || authorityBlocked"
              />
            </UForm>
            <p v-else-if="selectedNote.state === 'deletion_pending'" class="text-muted mt-3 text-sm">
              A anotação aguarda remoção; novos arquivos e downloads estão desativados.
            </p>

            <div v-if="selectedNote.attachments.length" class="mt-4 divide-default divide-y">
              <div v-for="attachment in selectedNote.attachments" :key="attachment.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                <div class="min-w-0">
                  <p class="text-highlighted break-all text-sm font-medium">
                    {{ attachment.original_name }}
                  </p>
                  <p class="text-muted mt-1 text-xs">
                    {{ attachment.mime_type }} · {{ formatBytes(attachment.byte_length) }} · {{ attachmentStatus(attachment.status) }}
                  </p>
                </div>
                <div class="flex flex-wrap gap-2">
                  <UButton
                    v-if="permissions.attachmentDownload.value && permissions.show.value"
                    label="Baixar"
                    icon="i-lucide-download"
                    size="sm"
                    variant="outline"
                    :disabled="!canDownloadSelected || attachment.status !== 'ready'"
                    @click="notesApi.downloadAttachment(selectedNote.id, attachment)"
                  />
                  <UButton
                    v-if="permissions.attachmentArchive.value"
                    :label="attachment.status === 'deletion_pending' ? 'Tentar remoção novamente' : 'Remover'"
                    size="sm"
                    color="error"
                    variant="ghost"
                    :disabled="mutationPending || authorityBlocked"
                    @click="askArchiveAttachment(attachment)"
                  />
                </div>
              </div>
            </div>
            <p v-else-if="!loadingDetail" class="text-muted mt-4 text-sm">
              Nenhum arquivo anexado.
            </p>
            <UPagination
              v-if="attachmentsMeta && attachmentPageCount > 1"
              :page="attachmentsMeta.current_page"
              :total="attachmentsMeta.total"
              :items-per-page="attachmentsMeta.per_page"
              :disabled="loadingDetail"
              aria-label="Páginas de arquivos anexados"
              @update:page="changeAttachmentsPage"
            />
          </div>
        </template>
      </section>
    </div>

    <UModal v-model:open="removalOpen" title="Confirmar remoção" :description="removalTarget?.kind === 'attachment' ? 'O arquivo ficará indisponível enquanto a remoção aguarda conclusão.' : 'A anotação ficará disponível no filtro de remoções pendentes enquanto o processo termina.'">
      <template #body>
        <p class="text-sm">
          {{ removalTarget?.attachment?.status === 'deletion_pending' || removalTarget?.kind === 'note' && rows.find(note => note.id === removalTarget?.noteId)?.state === 'deletion_pending' ? 'Solicitar nova tentativa de remoção?' : 'Deseja solicitar a remoção deste item?' }}
        </p>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton
            label="Cancelar"
            color="neutral"
            variant="ghost"
            :disabled="mutationPending"
            @click="removalOpen = false"
          />
          <UButton
            label="Confirmar remoção"
            color="error"
            :loading="archiving || archivingAttachment"
            :disabled="mutationPending || authorityBlocked"
            @click="confirmRemoval"
          />
        </div>
      </template>
    </UModal>
  </section>
</template>
