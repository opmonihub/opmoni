import type {
  ErpContactNote,
  ErpContactNoteAttachment,
  ErpContactNoteDetail,
  ErpContactNoteDetailResponse,
  ErpContactNoteListResponse,
  ErpContactNoteListState,
  ErpContactNotePageMeta,
  ErpContactNotePayload,
  ErpContactNoteUpdatePayload
} from '#shared/types/erpContactNotes'
import { apiFailure } from '#shared/utils/access'
import { erpContactNoteRoutes } from '#shared/utils/erpContactNotes'
import { useErpAdministrationContext } from '~/composables/useErpAdministrationContext'
import { erpCompanyHasPermission } from '~/utils/erpModulesNav'

type NoteAction = 'index' | 'show' | 'store' | 'update' | 'archive' | 'attachments.store' | 'attachments.download' | 'attachments.archive'
type NoteResponse = { data: ErpContactNote }
type AttachmentResponse = { data: ErpContactNoteAttachment }
type UnavailableAttachmentResponse = { data?: ErpContactNoteAttachment }
type NoteVisibilityEvent = { noteId: number, phase: 'resolving' | 'retryable' | 'lost', sequence: number }
type NoteVisibilityRecovery = {
  noteId: number
  contextKey: string
  phase: 'resolving' | 'retryable'
  rowIndex: number
  wasProjected: boolean
  restoreSelected: boolean
  filter: ErpContactNoteListState
  totalBeforeHide: number | null
  listDataRevision: number
}

const permissionNames: Record<NoteAction, string> = {
  'index': 'erp.contacts.notes.index',
  'show': 'erp.contacts.notes.show',
  'store': 'erp.contacts.notes.store',
  'update': 'erp.contacts.notes.update',
  'archive': 'erp.contacts.notes.archive',
  'attachments.store': 'erp.contacts.notes.attachments.store',
  'attachments.download': 'erp.contacts.notes.attachments.download',
  'attachments.archive': 'erp.contacts.notes.attachments.archive'
}

export function useErpContactNotes(contactId: Ref<number>) {
  const { api, refreshCsrf } = useAccessApi()
  const { user, currentCompany, currentAccount } = useAuth()
  const context = useErpAdministrationContext(() => contactId.value)
  const notes = ref<ErpContactNote[]>([])
  const selectedNote = ref<ErpContactNoteDetail | null>(null)
  const page = ref(1)
  const filter = ref<ErpContactNoteListState>('active')
  const listMeta = ref<(ErpContactNotePageMeta & { state: ErpContactNoteListState }) | null>(null)
  const attachmentsMeta = ref<ErpContactNotePageMeta | null>(null)
  const pending = ref(false)
  const hasLoaded = ref(false)
  const loadingDetail = ref(false)
  const saving = ref(false)
  const archiving = ref(false)
  const uploading = ref(false)
  const archivingAttachment = ref(false)
  const error = ref('')
  const fieldErrors = ref<Record<string, string[]>>({})
  const staleList = ref(false)
  const staleDetail = ref(false)
  const blockedContextKey = ref<string | null>(null)
  const noteVisibilityEvent = ref<NoteVisibilityEvent | null>(null)
  const noteVisibilityRecovery = ref<NoteVisibilityRecovery | null>(null)
  const authorityBlocked = computed(() => blockedContextKey.value === context.key.value)
  const mutationPending = computed(() => saving.value || archiving.value || uploading.value || archivingAttachment.value)
  const stale = computed(() => staleList.value || staleDetail.value)
  const controllers = new Set<AbortController>()
  const objectUrls = new Map<string, { timer: ReturnType<typeof setTimeout>, noteId: number }>()
  const noteVisibilityGenerations = new Map<number, number>()
  const pendingDownloadGenerations = new Map<number, number>()
  let listController: AbortController | null = null
  let detailController: AbortController | null = null
  let listSequence = 0
  let listDataRevision = 0
  let detailSequence = 0
  let mutationSequence = 0
  let visibilitySequence = 0
  let visibilityEventSequence = 0
  let visibilityController: AbortController | null = null
  let disposed = false

  function hasAction(action: NoteAction): boolean {
    const actor = user.value
    const company = currentCompany.value
    if (!actor?.id || !company || company.status !== 'active')
      return false

    const permission = permissionNames[action]
    const write = !['index', 'show', 'attachments.download'].includes(action)
    if (company.access_kind === 'delegated') {
      const office = currentAccount.value
      if (office?.status !== 'active' || office.id !== company.delegated_office_id)
        return false
      if (write && !['admin', 'operador'].includes(office.role))
        return false
      return company.permissions.includes(permission)
    }
    if (company.access_kind !== 'direct')
      return false
    if (write && !['owner', 'manager', 'operator'].includes(company.role))
      return false
    return erpCompanyHasPermission(company.permissions, permission)
  }

  const permissions = {
    index: computed(() => hasAction('index')),
    show: computed(() => hasAction('show')),
    store: computed(() => hasAction('store')),
    update: computed(() => hasAction('update')),
    archive: computed(() => hasAction('archive')),
    attachmentStore: computed(() => hasAction('attachments.store')),
    attachmentDownload: computed(() => hasAction('attachments.download')),
    attachmentArchive: computed(() => hasAction('attachments.archive'))
  }

  function isCurrent(token: ReturnType<typeof context.capture>, sequence?: number, expected?: number): boolean {
    return !disposed && !authorityBlocked.value && context.isCurrent(token) && (expected === undefined || sequence === expected)
  }

  function isNoteMutationCurrent(
    token: ReturnType<typeof context.capture>,
    requestSequence: number,
    noteId: number,
    visibilityGeneration: number
  ): boolean {
    return isCurrent(token, mutationSequence, requestSequence)
      && noteVisibilityGeneration(noteId) === visibilityGeneration
  }

  function makeController() {
    const controller = new AbortController()
    controllers.add(controller)
    return controller
  }

  function finishController(controller: AbortController) {
    controllers.delete(controller)
  }

  function abortVisibilityReconciliation() {
    visibilitySequence++
    visibilityController?.abort()
    if (visibilityController)
      finishController(visibilityController)
    visibilityController = null
  }

  function abortReads() {
    listSequence++
    detailSequence++
    abortVisibilityReconciliation()
    listController?.abort()
    detailController?.abort()
    if (listController)
      finishController(listController)
    if (detailController)
      finishController(detailController)
    listController = null
    detailController = null
    pending.value = false
    loadingDetail.value = false
  }

  function interruptSelectedVisibilityForMutation() {
    const recovery = noteVisibilityRecovery.value
    if (!recovery || !recovery.restoreSelected || recovery.phase !== 'resolving')
      return

    visibilitySequence++
    visibilityController?.abort()
    if (visibilityController)
      finishController(visibilityController)
    visibilityController = null
    loadingDetail.value = false
    noteVisibilityRecovery.value = { ...recovery, phase: 'retryable' }
    emitNoteVisibility(recovery.noteId, 'retryable')
  }

  function beginMutation(noteId?: number) {
    interruptSelectedVisibilityForMutation()
    if (noteId !== undefined) {
      pendingDownloadGenerations.set(noteId, pendingDownloadGeneration(noteId) + 1)
    }
    abortReads()
    const requestSequence = ++mutationSequence
    const token = context.capture()
    const controller = makeController()
    return { requestSequence, token, controller }
  }

  function revokeAllObjectUrls() {
    for (const [url, owned] of objectUrls) {
      clearTimeout(owned.timer)
      URL.revokeObjectURL(url)
    }
    objectUrls.clear()
  }

  function noteVisibilityGeneration(noteId: number): number {
    return noteVisibilityGenerations.get(noteId) ?? 0
  }

  function pendingDownloadGeneration(noteId: number): number {
    return pendingDownloadGenerations.get(noteId) ?? 0
  }

  function invalidateNoteVisibility(noteId: number) {
    noteVisibilityGenerations.set(noteId, noteVisibilityGeneration(noteId) + 1)
    for (const [url, owned] of objectUrls) {
      if (owned.noteId !== noteId)
        continue
      clearTimeout(owned.timer)
      URL.revokeObjectURL(url)
      objectUrls.delete(url)
    }
  }

  function abortAll() {
    abortReads()
    for (const controller of controllers)
      controller.abort()
    controllers.clear()
    mutationSequence++
    saving.value = false
    archiving.value = false
    uploading.value = false
    archivingAttachment.value = false
  }

  function clearData() {
    notes.value = []
    selectedNote.value = null
    page.value = 1
    filter.value = 'active'
    listMeta.value = null
    attachmentsMeta.value = null
    hasLoaded.value = false
    staleList.value = false
    staleDetail.value = false
    error.value = ''
    fieldErrors.value = {}
    noteVisibilityEvent.value = null
    noteVisibilityRecovery.value = null
  }

  function clearErrors() {
    error.value = ''
    fieldErrors.value = {}
  }

  function isAuthorityLoss(status: number, code: string): boolean {
    return status === 401 || status === 403 || (status === 409 && code === 'company_context_changed')
  }

  function blockAuthority(message: string) {
    blockedContextKey.value = context.key.value
    abortAll()
    revokeAllObjectUrls()
    clearData()
    error.value = message
  }

  function handleFailure(failure: unknown) {
    const details = apiFailure(failure)
    if (isAuthorityLoss(details.status, details.code)) {
      blockAuthority(details.message)
      return details
    }
    error.value = details.message
    fieldErrors.value = details.errors ?? {}
    return details
  }

  function updateListRow(note: ErpContactNote) {
    notes.value = notes.value.map(row => row.id === note.id ? note : row)
  }

  function removeVisibleNote(noteId: number): boolean {
    const hadRow = notes.value.some(note => note.id === noteId)
    const hadSelected = selectedNote.value?.id === noteId
    notes.value = notes.value.filter(note => note.id !== noteId)
    if (hadSelected) {
      selectedNote.value = null
      attachmentsMeta.value = null
    }
    if (hadRow && listMeta.value && listMeta.value.total > 0) {
      listMeta.value = { ...listMeta.value, total: listMeta.value.total - 1 }
    }
    if (hadRow)
      listDataRevision++
    return hadRow
  }

  function noteRowFromDetail(detail: ErpContactNoteDetail): ErpContactNote {
    return {
      id: detail.id,
      heading: detail.heading,
      description: detail.description,
      is_private: detail.is_private,
      state: detail.state,
      can_change_privacy: detail.can_change_privacy,
      attachments_count: detail.attachments_count,
      created_at: detail.created_at,
      updated_at: detail.updated_at
    }
  }

  function emitNoteVisibility(noteId: number, phase: NoteVisibilityEvent['phase']) {
    noteVisibilityEvent.value = { noteId, phase, sequence: ++visibilityEventSequence }
  }

  function loseNoteVisibility(noteId: number, message = 'Esta anotação não está mais disponível para este contato.') {
    abortReads()
    invalidateNoteVisibility(noteId)
    if (noteVisibilityRecovery.value?.noteId === noteId)
      noteVisibilityRecovery.value = null
    removeVisibleNote(noteId)
    staleDetail.value = false
    fieldErrors.value = {}
    error.value = message
    emitNoteVisibility(noteId, 'lost')
  }

  async function reconcileAttachmentNotFound(
    noteId: number,
    token: ReturnType<typeof context.capture>,
    operationIsCurrent: () => boolean,
    recovery?: NoteVisibilityRecovery
  ): Promise<void> {
    if (!isCurrent(token) || !operationIsCurrent())
      return
    if (!permissions.show.value) {
      loseNoteVisibility(noteId)
      return
    }

    const rowIndex = notes.value.findIndex(note => note.id === noteId)
    const currentRecovery = recovery ?? {
      noteId,
      contextKey: context.key.value,
      phase: 'resolving' as const,
      rowIndex,
      wasProjected: rowIndex >= 0,
      restoreSelected: selectedNote.value?.id === noteId,
      filter: filter.value,
      totalBeforeHide: listMeta.value?.total ?? null,
      listDataRevision
    }
    if (currentRecovery.noteId !== noteId || currentRecovery.contextKey !== context.key.value) {
      return
    }
    const selectedRecovery = currentRecovery.restoreSelected || noteVisibilityRecovery.value?.noteId === noteId
    const previousRecovery = noteVisibilityRecovery.value
    if (previousRecovery?.phase === 'resolving' && previousRecovery.noteId !== noteId) {
      visibilitySequence++
      visibilityController?.abort()
      if (visibilityController)
        finishController(visibilityController)
      visibilityController = null
      loadingDetail.value = false
      noteVisibilityRecovery.value = { ...previousRecovery, phase: 'retryable' }
      emitNoteVisibility(previousRecovery.noteId, 'retryable')
    }
    abortReads()
    invalidateNoteVisibility(noteId)
    const requestSequence = visibilitySequence
    removeVisibleNote(noteId)
    const activeRecovery = {
      ...currentRecovery,
      phase: 'resolving' as const,
      listDataRevision: recovery ? currentRecovery.listDataRevision : listDataRevision
    }
    if (selectedRecovery)
      noteVisibilityRecovery.value = activeRecovery
    if (currentRecovery.restoreSelected)
      staleDetail.value = false
    loadingDetail.value = currentRecovery.restoreSelected
    emitNoteVisibility(noteId, 'resolving')
    const controller = makeController()
    visibilityController = controller

    // The triggering operation's note generation is checked before this
    // reconciliation starts. Reconciliation itself advances that generation
    // to invalidate older callbacks for this note, so its lease must use the
    // operation sequence and visibility sequence only.
    const current = () => isCurrent(token, visibilitySequence, requestSequence) && operationIsCurrent()
    try {
      const response = await api<ErpContactNoteDetailResponse>(
        erpContactNoteRoutes.show(contactId.value, noteId, 1),
        { signal: controller.signal }
      )
      if (!current())
        return

      let refreshProjection = false
      if (currentRecovery.wasProjected && filter.value === response.data.state) {
        const existingIndex = notes.value.findIndex(note => note.id === noteId)
        if (existingIndex >= 0) {
          updateListRow(noteRowFromDetail(response.data))
        } else if (filter.value === activeRecovery.filter && listDataRevision === activeRecovery.listDataRevision) {
          const nextRows = [...notes.value]
          nextRows.splice(Math.min(currentRecovery.rowIndex, nextRows.length), 0, noteRowFromDetail(response.data))
          notes.value = nextRows
          if (listMeta.value && currentRecovery.totalBeforeHide !== null)
            listMeta.value = { ...listMeta.value, total: currentRecovery.totalBeforeHide }
        } else {
          refreshProjection = true
        }
      }
      if (currentRecovery.restoreSelected) {
        selectedNote.value = response.data
        attachmentsMeta.value = response.meta.attachments
      }
      if (refreshProjection) {
        await refresh(page.value, true)
        if (!current())
          return
      }
      if (noteVisibilityRecovery.value?.noteId === noteId)
        noteVisibilityRecovery.value = null
      error.value = 'O arquivo não está mais disponível para esta anotação.'
      fieldErrors.value = {}
    } catch (failure) {
      if (!current())
        return
      const details = apiFailure(failure)
      if (details.status === 404) {
        loseNoteVisibility(noteId)
      } else {
        if (isAuthorityLoss(details.status, details.code)) {
          handleFailure(failure)
        } else {
          if (selectedRecovery) {
            noteVisibilityRecovery.value = { ...activeRecovery, phase: 'retryable' }
            error.value = 'Não foi possível confirmar a disponibilidade da anotação. O conteúdo permanece oculto.'
          } else {
            staleList.value = true
          }
          fieldErrors.value = {}
          emitNoteVisibility(noteId, 'retryable')
        }
      }
    } finally {
      finishController(controller)
      if (visibilityController === controller)
        visibilityController = null
      if (current())
        loadingDetail.value = false
    }
  }

  async function retryNoteAvailability(noteId: number): Promise<boolean> {
    const recovery = noteVisibilityRecovery.value
    if (
      !recovery
      || recovery.noteId !== noteId
      || recovery.phase !== 'retryable'
      || recovery.contextKey !== context.key.value
      || authorityBlocked.value
      || mutationPending.value
      || loadingDetail.value
      || !permissions.show.value
    ) {
      return false
    }

    const token = context.capture()
    await reconcileAttachmentNotFound(noteId, token, () => isCurrent(token), recovery)
    return selectedNote.value?.id === noteId
  }

  function setPendingNote(noteId: number) {
    updateListRow({
      ...(notes.value.find(note => note.id === noteId) ?? selectedNote.value ?? {
        id: noteId,
        heading: null,
        description: null,
        is_private: false,
        state: 'active' as const,
        can_change_privacy: false,
        attachments_count: 0,
        created_at: null,
        updated_at: null
      }),
      state: 'deletion_pending'
    })
    if (selectedNote.value?.id === noteId)
      selectedNote.value = { ...selectedNote.value, state: 'deletion_pending' }
  }

  async function refresh(nextPage = page.value, afterMutation = false): Promise<void> {
    if (mutationPending.value && !afterMutation)
      return
    if (authorityBlocked.value || !permissions.index.value) {
      pending.value = false
      if (!authorityBlocked.value && !permissions.index.value)
        error.value = 'Você não tem permissão para consultar as anotações deste contato.'
      return
    }

    page.value = Math.max(1, nextPage)
    const requestSequence = ++listSequence
    const token = context.capture()
    listController?.abort()
    const controller = makeController()
    listController = controller
    pending.value = true
    clearErrors()
    try {
      const response = await api<ErpContactNoteListResponse>(
        erpContactNoteRoutes.index(contactId.value, page.value, filter.value),
        { signal: controller.signal }
      )
      if (!isCurrent(token, listSequence, requestSequence))
        return
      notes.value = response.data
      listDataRevision++
      listMeta.value = response.meta
      page.value = response.meta.current_page
      hasLoaded.value = true
      staleList.value = false
    } catch (failure) {
      if (isCurrent(token, listSequence, requestSequence)) {
        const details = handleFailure(failure)
        staleList.value = hasLoaded.value && !isAuthorityLoss(details.status, details.code)
      }
    } finally {
      finishController(controller)
      if (listController === controller)
        listController = null
      if (isCurrent(token, listSequence, requestSequence))
        pending.value = false
    }
  }

  async function setFilter(nextFilter: ErpContactNoteListState): Promise<void> {
    if (mutationPending.value || filter.value === nextFilter)
      return
    filter.value = nextFilter
    await refresh(1)
  }

  async function selectNote(noteId: number, attachmentsPage = 1): Promise<void> {
    if (mutationPending.value)
      return
    if (authorityBlocked.value || !permissions.show.value) {
      if (!authorityBlocked.value)
        error.value = 'Você não tem permissão para consultar o conteúdo desta anotação.'
      return
    }
    if (noteVisibilityRecovery.value && noteVisibilityRecovery.value.noteId !== noteId) {
      noteVisibilityRecovery.value = null
      if (noteVisibilityEvent.value?.phase === 'retryable')
        noteVisibilityEvent.value = null
    }
    abortVisibilityReconciliation()
    const requestSequence = ++detailSequence
    const token = context.capture()
    detailController?.abort()
    const controller = makeController()
    detailController = controller
    if (selectedNote.value?.id !== noteId) {
      selectedNote.value = null
      attachmentsMeta.value = null
      staleDetail.value = false
    }
    loadingDetail.value = true
    clearErrors()
    try {
      const response = await api<ErpContactNoteDetailResponse>(
        erpContactNoteRoutes.show(contactId.value, noteId, Math.max(1, attachmentsPage)),
        { signal: controller.signal }
      )
      if (!isCurrent(token, detailSequence, requestSequence))
        return
      if (noteVisibilityRecovery.value?.noteId === noteId) {
        noteVisibilityRecovery.value = null
        if (noteVisibilityEvent.value?.noteId === noteId)
          noteVisibilityEvent.value = null
      }
      selectedNote.value = response.data
      attachmentsMeta.value = response.meta.attachments
      staleDetail.value = false
    } catch (failure) {
      if (isCurrent(token, detailSequence, requestSequence)) {
        const details = apiFailure(failure)
        if (details.status === 404) {
          loseNoteVisibility(noteId)
        } else {
          handleFailure(failure)
          staleDetail.value = selectedNote.value !== null && !isAuthorityLoss(details.status, details.code)
        }
      }
    } finally {
      finishController(controller)
      if (detailController === controller)
        detailController = null
      if (isCurrent(token, detailSequence, requestSequence))
        loadingDetail.value = false
    }
  }

  async function createNote(payload: ErpContactNotePayload): Promise<boolean> {
    if (authorityBlocked.value || mutationPending.value || !permissions.store.value) {
      error.value = 'Você não tem permissão para criar anotações neste contato.'
      return false
    }
    const { requestSequence, token, controller } = beginMutation()
    saving.value = true
    clearErrors()
    try {
      await refreshCsrf()
      if (!isCurrent(token, mutationSequence, requestSequence))
        return false
      await api<NoteResponse>(erpContactNoteRoutes.store(contactId.value), {
        method: 'POST',
        body: payload,
        signal: controller.signal
      })
      if (!isCurrent(token, mutationSequence, requestSequence))
        return false
      if (filter.value === 'active')
        await refresh(1, true)
      if (!isCurrent(token, mutationSequence, requestSequence))
        return false
      return true
    } catch (failure) {
      if (isCurrent(token, mutationSequence, requestSequence))
        handleFailure(failure)
      return false
    } finally {
      finishController(controller)
      if (isCurrent(token, mutationSequence, requestSequence))
        saving.value = false
    }
  }

  async function updateNote(noteId: number, payload: ErpContactNoteUpdatePayload): Promise<boolean> {
    const note = selectedNote.value?.id === noteId ? selectedNote.value : notes.value.find(row => row.id === noteId)
    if (authorityBlocked.value || mutationPending.value || !permissions.update.value) {
      error.value = 'Você não tem permissão para editar esta anotação.'
      return false
    }
    if (Object.hasOwn(payload, 'is_private') && !note?.can_change_privacy) {
      error.value = 'Somente a pessoa que criou a anotação pode alterar sua privacidade.'
      return false
    }
    const { requestSequence, token, controller } = beginMutation(noteId)
    const visibilityGeneration = noteVisibilityGeneration(noteId)
    saving.value = true
    clearErrors()
    try {
      await refreshCsrf()
      if (!isNoteMutationCurrent(token, requestSequence, noteId, visibilityGeneration))
        return false
      const response = await api<NoteResponse>(erpContactNoteRoutes.note(contactId.value, noteId), {
        method: 'PATCH',
        body: payload,
        signal: controller.signal
      })
      if (!isNoteMutationCurrent(token, requestSequence, noteId, visibilityGeneration))
        return false
      updateListRow(response.data)
      if (selectedNote.value?.id === noteId)
        selectedNote.value = { ...selectedNote.value, ...response.data }
      return true
    } catch (failure) {
      if (isNoteMutationCurrent(token, requestSequence, noteId, visibilityGeneration)) {
        const details = apiFailure(failure)
        if (details.status === 404)
          loseNoteVisibility(noteId)
        else
          handleFailure(failure)
      }
      return false
    } finally {
      finishController(controller)
      if (isCurrent(token, mutationSequence, requestSequence))
        saving.value = false
    }
  }

  async function archiveNote(noteId: number): Promise<boolean> {
    if (authorityBlocked.value || mutationPending.value || !permissions.archive.value) {
      error.value = 'Você não tem permissão para solicitar a remoção desta anotação.'
      return false
    }
    const { requestSequence, token, controller } = beginMutation(noteId)
    const visibilityGeneration = noteVisibilityGeneration(noteId)
    archiving.value = true
    clearErrors()
    try {
      await refreshCsrf()
      if (!isNoteMutationCurrent(token, requestSequence, noteId, visibilityGeneration))
        return false
      await api(erpContactNoteRoutes.note(contactId.value, noteId), { method: 'DELETE', signal: controller.signal })
      if (!isNoteMutationCurrent(token, requestSequence, noteId, visibilityGeneration))
        return false
      setPendingNote(noteId)
      await refresh(page.value, true)
      if (!isNoteMutationCurrent(token, requestSequence, noteId, visibilityGeneration))
        return false
      return true
    } catch (failure) {
      if (isNoteMutationCurrent(token, requestSequence, noteId, visibilityGeneration)) {
        const details = apiFailure(failure)
        if (details.status === 404)
          loseNoteVisibility(noteId)
        else
          handleFailure(failure)
      }
      return false
    } finally {
      finishController(controller)
      if (isCurrent(token, mutationSequence, requestSequence))
        archiving.value = false
    }
  }

  async function uploadAttachment(noteId: number, file: File): Promise<boolean> {
    const note = selectedNote.value?.id === noteId ? selectedNote.value : notes.value.find(row => row.id === noteId)
    if (authorityBlocked.value || mutationPending.value || !permissions.attachmentStore.value || note?.state !== 'active') {
      error.value = 'O envio de arquivos não está disponível para esta anotação.'
      return false
    }
    const { requestSequence, token, controller } = beginMutation(noteId)
    const visibilityGeneration = noteVisibilityGeneration(noteId)
    const formData = new FormData()
    formData.append('file', file, file.name)
    uploading.value = true
    clearErrors()
    try {
      await refreshCsrf()
      if (!isNoteMutationCurrent(token, requestSequence, noteId, visibilityGeneration))
        return false
      const response = await api<AttachmentResponse>(erpContactNoteRoutes.attachments(contactId.value, noteId), {
        method: 'POST',
        body: formData,
        signal: controller.signal
      })
      if (!isNoteMutationCurrent(token, requestSequence, noteId, visibilityGeneration))
        return false
      if (selectedNote.value?.id === noteId) {
        selectedNote.value = {
          ...selectedNote.value,
          attachments: [response.data, ...selectedNote.value.attachments.filter(item => item.id !== response.data.id)],
          attachments_count: selectedNote.value.attachments_count + 1
        }
      }
      return true
    } catch (failure) {
      if (isNoteMutationCurrent(token, requestSequence, noteId, visibilityGeneration)) {
        const details = apiFailure(failure)
        if (details.status === 404) {
          await reconcileAttachmentNotFound(noteId, token, () => isCurrent(token, mutationSequence, requestSequence))
        } else {
          handleFailure(failure)
          const unavailable = (failure as { data?: UnavailableAttachmentResponse }).data?.data
          if (details.status === 503 && unavailable && selectedNote.value?.id === noteId) {
            selectedNote.value = {
              ...selectedNote.value,
              attachments: [unavailable, ...selectedNote.value.attachments.filter(item => item.id !== unavailable.id)],
              attachments_count: selectedNote.value.attachments_count + 1
            }
          }
        }
      }
      return false
    } finally {
      finishController(controller)
      if (isCurrent(token, mutationSequence, requestSequence))
        uploading.value = false
    }
  }

  async function archiveAttachment(noteId: number, attachmentId: number): Promise<boolean> {
    if (authorityBlocked.value || mutationPending.value || !permissions.attachmentArchive.value) {
      error.value = 'Você não tem permissão para solicitar a remoção deste arquivo.'
      return false
    }
    const { requestSequence, token, controller } = beginMutation(noteId)
    const visibilityGeneration = noteVisibilityGeneration(noteId)
    archivingAttachment.value = true
    clearErrors()
    try {
      await refreshCsrf()
      if (!isNoteMutationCurrent(token, requestSequence, noteId, visibilityGeneration))
        return false
      await api(erpContactNoteRoutes.attachment(contactId.value, noteId, attachmentId), {
        method: 'DELETE',
        signal: controller.signal
      })
      if (!isNoteMutationCurrent(token, requestSequence, noteId, visibilityGeneration))
        return false
      if (selectedNote.value?.id === noteId) {
        const attachment = selectedNote.value.attachments.find(item => item.id === attachmentId)
        const becomesPending = attachment !== undefined && attachment.status !== 'deletion_pending'
        const attachmentCount = selectedNote.value.state === 'deletion_pending'
          ? selectedNote.value.attachments_count + (becomesPending ? 1 : 0)
          : Math.max(0, selectedNote.value.attachments_count - (becomesPending ? 1 : 0))
        selectedNote.value = {
          ...selectedNote.value,
          attachments: selectedNote.value.attachments.map(attachment => attachment.id === attachmentId
            ? { ...attachment, status: 'deletion_pending' }
            : attachment),
          attachments_count: attachmentCount,
          deletion_pending_attachments_count: selectedNote.value.deletion_pending_attachments_count + (becomesPending ? 1 : 0)
        }
      }
      return true
    } catch (failure) {
      if (isNoteMutationCurrent(token, requestSequence, noteId, visibilityGeneration)) {
        const details = apiFailure(failure)
        if (details.status === 404)
          await reconcileAttachmentNotFound(noteId, token, () => isCurrent(token, mutationSequence, requestSequence))
        else
          handleFailure(failure)
      }
      return false
    } finally {
      finishController(controller)
      if (isCurrent(token, mutationSequence, requestSequence))
        archivingAttachment.value = false
    }
  }

  async function downloadAttachment(noteId: number, attachment: ErpContactNoteAttachment): Promise<boolean> {
    if (mutationPending.value) {
      error.value = 'Aguarde a conclusão da alteração antes de baixar um arquivo.'
      return false
    }
    if (authorityBlocked.value || !permissions.show.value || !permissions.attachmentDownload.value) {
      error.value = 'Você não tem permissão para baixar este arquivo.'
      return false
    }
    if (selectedNote.value?.id !== noteId) {
      error.value = 'Abra os detalhes da anotação antes de baixar um arquivo.'
      return false
    }
    if (selectedNote.value.state !== 'active') {
      error.value = 'A anotação está aguardando remoção e não permite novos downloads.'
      return false
    }
    if (attachment.status !== 'ready') {
      error.value = attachment.status === 'deletion_pending'
        ? 'O arquivo está aguardando remoção.'
        : 'O arquivo não está disponível para download.'
      return false
    }
    if (typeof document === 'undefined' || typeof URL.createObjectURL !== 'function') {
      error.value = 'O download não está disponível neste ambiente.'
      return false
    }
    const token = context.capture()
    const visibilityGeneration = noteVisibilityGeneration(noteId)
    const downloadMutationGeneration = pendingDownloadGeneration(noteId)
    const downloadIsCurrent = () => isCurrent(token)
      && noteVisibilityGeneration(noteId) === visibilityGeneration
      && pendingDownloadGeneration(noteId) === downloadMutationGeneration
    const controller = makeController()
    clearErrors()
    try {
      const blob = await api<Blob, 'blob'>(erpContactNoteRoutes.download(contactId.value, noteId, attachment.id), {
        responseType: 'blob',
        signal: controller.signal
      })
      if (!downloadIsCurrent())
        return false
      const url = URL.createObjectURL(blob)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = attachment.original_name
      anchor.rel = 'noopener'
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
      const timer = setTimeout(() => {
        URL.revokeObjectURL(url)
        objectUrls.delete(url)
      }, 60_000)
      objectUrls.set(url, { timer, noteId })
      return true
    } catch (failure) {
      if (downloadIsCurrent()) {
        const details = apiFailure(failure)
        if (details.status === 404) {
          await reconcileAttachmentNotFound(noteId, token, () => isCurrent(token))
        } else {
          handleFailure(failure)
        }
      }
      return false
    } finally {
      finishController(controller)
    }
  }

  watch(context.key, () => {
    abortAll()
    revokeAllObjectUrls()
    blockedContextKey.value = null
    clearData()
    if (permissions.index.value)
      void refresh(1)
  }, { flush: 'sync' })

  onScopeDispose(() => {
    disposed = true
    abortAll()
    revokeAllObjectUrls()
    clearData()
  })

  if (permissions.index.value)
    void refresh()

  return {
    notes,
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
    noteVisibilityEvent,
    noteVisibilityRecovery,
    permissions,
    contextKey: context.key,
    refresh,
    setFilter,
    selectNote,
    retryNoteAvailability,
    createNote,
    updateNote,
    archiveNote,
    uploadAttachment,
    downloadAttachment,
    archiveAttachment
  }
}
