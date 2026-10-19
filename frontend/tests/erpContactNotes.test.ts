import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { erpCompanyHasPermission } from '../app/utils/erpModulesNav.ts'
import { apiFailure } from '../shared/utils/access.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

type Company = {
  id: number
  status: 'active' | 'suspended'
  access_kind: 'direct' | 'delegated'
  role: string
  permissions: string[]
  delegated_office_id: number | null
}

type Account = { id: number, status: 'active' | 'suspended', role: 'admin' | 'operador' | 'user' }
type Request = { url: string, options: Record<string, unknown> & { method?: string, signal?: AbortSignal, responseType?: string, body?: unknown }, resolve(value: unknown): void, reject(reason: unknown): void }
type Attachment = { id: number, original_name: string, mime_type: string, byte_length: number, sha256: string, status: 'ready' | 'unavailable' | 'deletion_pending' }
type Note = { id: number, heading: string | null, description: string | null, is_private: boolean, state: 'active' | 'deletion_pending', can_change_privacy: boolean, attachments_count: number, created_at: string | null, updated_at: string | null }
type NoteDetail = Note & { deletion_pending_attachments_count: number, attachments: Attachment[] }
function loadNotesModule() {
  return loadAppComposable<{ useErpContactNotes(contactId: Vue.Ref<number>): {
    notes: Vue.Ref<Note[]>
    selectedNote: Vue.Ref<NoteDetail | null>
    page: Vue.Ref<number>
    filter: Vue.Ref<'active' | 'deletion_pending'>
    pending: Vue.Ref<boolean>
    loadingDetail: Vue.Ref<boolean>
    mutationPending: Vue.Ref<boolean>
    error: Vue.Ref<string>
    fieldErrors: Vue.Ref<Record<string, string[]>>
    stale: Vue.ComputedRef<boolean>
    authorityBlocked: Vue.ComputedRef<boolean>
    noteVisibilityEvent: Vue.Ref<{ noteId: number, phase: 'resolving' | 'retryable' | 'lost', sequence: number } | null>
    retryNoteAvailability(noteId: number): Promise<boolean>
    permissions: Record<'index' | 'show' | 'store' | 'update' | 'archive' | 'attachmentStore' | 'attachmentDownload' | 'attachmentArchive', Vue.ComputedRef<boolean>>
    refresh(page?: number): Promise<void>
    setFilter(filter: 'active' | 'deletion_pending'): Promise<void>
    selectNote(noteId: number, attachmentsPage?: number): Promise<void>
    createNote(payload: { heading: string | null, description: string | null, is_private: boolean }): Promise<boolean>
    updateNote(noteId: number, payload: { heading?: string | null, description?: string | null, is_private?: boolean }): Promise<boolean>
    archiveNote(noteId: number): Promise<boolean>
    uploadAttachment(noteId: number, file: File): Promise<boolean>
    downloadAttachment(noteId: number, attachment: Attachment): Promise<boolean>
    archiveAttachment(noteId: number, attachmentId: number): Promise<boolean>
    contextKey: Vue.ComputedRef<string>
  } }>('composables/useErpContactNotes.ts', {
    computed: Vue.computed,
    onScopeDispose: Vue.onScopeDispose,
    ref: Vue.ref,
    watch: Vue.watch,
    useAuth,
    useState,
    useAccessApi: () => ({ api, refreshCsrf }),
    apiFailure,
    erpCompanyHasPermission
  })
}

let user: Vue.Ref<{ id: number } | null>
let currentCompany: Vue.Ref<Company | null>
let currentAccount: Vue.Ref<Account | null>
let sessionGeneration: Vue.Ref<number>
let requests: Request[]
let api: (url: string, options?: Record<string, unknown>) => Promise<unknown>
let refreshCsrf: () => Promise<void>

function useAuth() {
  return { user, currentCompany, currentAccount }
}

function useState<T>(key: string, init: () => T): Vue.Ref<T> {
  return key === 'access-session-generation' ? sessionGeneration as Vue.Ref<T> : Vue.ref(init())
}

const noteRow: Note = {
  id: 7,
  heading: 'Acordo comercial',
  description: 'Revisar no próximo mês.',
  is_private: false,
  state: 'active',
  can_change_privacy: true,
  attachments_count: 1,
  created_at: '2026-10-19T12:00:00.000Z',
  updated_at: '2026-10-19T12:00:00.000Z'
}
const attachment: Attachment = {
  id: 9,
  original_name: 'acordo.pdf',
  mime_type: 'application/pdf',
  byte_length: 16,
  sha256: 'a'.repeat(64),
  status: 'ready'
}
const detail: NoteDetail = { ...noteRow, attachments: [attachment], deletion_pending_attachments_count: 0 }
const otherNoteRow: Note = { ...noteRow, id: 8, heading: 'Outra anotação', attachments_count: 0 }
const otherDetail: NoteDetail = { ...detail, ...otherNoteRow, attachments: [], deletion_pending_attachments_count: 0 }

function setup(initial: {
  permissions?: string[]
  role?: string
  accessKind?: 'direct' | 'delegated'
  accountRole?: Account['role']
  actorId?: number | null
  refreshCsrf?: () => Promise<void>
  ignoreAbort?: (url: string, options: Request['options']) => boolean
} = {}) {
  user = Vue.ref(initial.actorId === null ? null : { id: initial.actorId ?? 5 })
  currentCompany = Vue.ref({
    id: 41,
    status: 'active',
    access_kind: initial.accessKind ?? 'direct',
    role: initial.role ?? 'owner',
    permissions: initial.permissions ?? ['*'],
    delegated_office_id: initial.accessKind === 'delegated' ? 77 : null
  })
  currentAccount = Vue.ref(initial.accessKind === 'delegated'
    ? { id: 77, status: 'active', role: initial.accountRole ?? 'admin' }
    : null)
  sessionGeneration = Vue.ref(7)
  requests = []
  api = (url, options = {}) => new Promise((resolve, reject) => {
    const requestOptions = options as Request['options']
    requests.push({ url, options: requestOptions, resolve, reject })
    if (requestOptions.signal && !initial.ignoreAbort?.(url, requestOptions)) {
      requestOptions.signal.addEventListener('abort', () => reject(new DOMException('Request aborted', 'AbortError')), { once: true })
    }
  })
  refreshCsrf = initial.refreshCsrf ?? (async () => {})
  const scope = Vue.effectScope()
  const contactId = Vue.ref(31)
  const { useErpContactNotes } = loadNotesModule()
  const notes = scope.run(() => useErpContactNotes(contactId))!
  return {
    notes,
    contactId,
    requests,
    user,
    currentCompany,
    currentAccount,
    sessionGeneration,
    scope,
    stop: () => scope.stop(),
    settle: async () => {
      for (let turn = 0; turn < 6; turn++) {
        await Vue.nextTick()
        await Promise.resolve()
      }
    },
    initialListRequest: () => requests.find(request => request.url === '/erp/contacts/31/notes?page=1&state=active')
  }
}

function listResponse(rows: Note[] = [noteRow]) {
  return { data: rows, meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length, state: 'active' } }
}

describe('ERP Contact notes lifecycle', () => {
  it('loads active notes through the fixed nested index route', async (t) => {
    const app = setup()
    t.after(app.stop)
    const request = app.initialListRequest()
    assert.ok(request)
    assert.equal(request.options.method ?? 'GET', 'GET')
    request.resolve(listResponse())
    await app.settle()
    assert.deepEqual(app.notes.notes.value, [noteRow])
    assert.equal(app.notes.pending.value, false)
    assert.equal(app.notes.permissions.index.value, true)
    assert.equal(app.notes.permissions.show.value, true)
  })

  it('keeps the last good list and marks it stale when a same-context read fails', async (t) => {
    const app = setup()
    t.after(app.stop)
    app.initialListRequest()!.resolve(listResponse())
    await app.settle()
    const refresh = app.notes.refresh()
    const second = app.requests.filter(request => request.url.includes('/notes?page=1&state=active')).at(-1)!
    second.reject({ statusCode: 503, data: { message: 'Indisponível' } })
    await refresh
    assert.deepEqual(app.notes.notes.value, [noteRow])
    assert.equal(app.notes.stale.value, true)
    assert.match(app.notes.error.value, /temporariamente indisponível/i)
  })

  it('requires separate route grants and keeps delegated user access read-only', async (t) => {
    const onlyIndex = setup({ permissions: ['erp.contacts.notes.index'] })
    t.after(onlyIndex.stop)
    assert.equal(onlyIndex.notes.permissions.index.value, true)
    assert.equal(onlyIndex.notes.permissions.show.value, false)
    assert.equal(onlyIndex.notes.permissions.store.value, false)
    assert.equal(onlyIndex.notes.permissions.attachmentDownload.value, false)

    const delegatedViewer = setup({
      accessKind: 'delegated',
      accountRole: 'user',
      permissions: ['erp.contacts.notes.index', 'erp.contacts.notes.show', 'erp.contacts.notes.store', 'erp.contacts.notes.update', 'erp.contacts.notes.archive', 'erp.contacts.notes.attachments.store', 'erp.contacts.notes.attachments.download', 'erp.contacts.notes.attachments.archive']
    })
    t.after(delegatedViewer.stop)
    assert.equal(delegatedViewer.notes.permissions.index.value, true)
    assert.equal(delegatedViewer.notes.permissions.show.value, true)
    assert.equal(delegatedViewer.notes.permissions.store.value, false)
    assert.equal(delegatedViewer.notes.permissions.update.value, false)
    assert.equal(delegatedViewer.notes.permissions.archive.value, false)
    assert.equal(delegatedViewer.notes.permissions.attachmentDownload.value, true)
    assert.equal(delegatedViewer.notes.permissions.attachmentStore.value, false)
  })

  it('clears and aborts old Company/session data when a download resolves after context changes', async (t) => {
    const app = setup()
    t.after(app.stop)
    app.initialListRequest()!.resolve(listResponse())
    await app.settle()
    const opening = app.notes.selectNote(noteRow.id)
    const detailRequest = app.requests.find(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`)!
    detailRequest.resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
    await opening
    const urlDescriptor = Object.getOwnPropertyDescriptor(URL, 'createObjectURL')
    const revokeDescriptor = Object.getOwnPropertyDescriptor(URL, 'revokeObjectURL')
    let urlsCreated = 0
    let anchorClicks = 0
    Object.defineProperty(URL, 'createObjectURL', {
      configurable: true,
      value: () => {
        urlsCreated++
        return 'blob:old-context'
      }
    })
    Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: () => {} })
    const previousDocument = (globalThis as typeof globalThis & { document?: unknown }).document
    Object.defineProperty(globalThis, 'document', {
      configurable: true,
      value: {
        body: { appendChild() {} },
        createElement: () => ({ click() { anchorClicks++ }, remove() {} })
      }
    })
    try {
      const downloading = app.notes.downloadAttachment(noteRow.id, attachment)
      const request = app.requests.find(item => item.options.responseType === 'blob')!
      assert.ok(request)
      assert.equal(request.url, `/erp/contacts/31/notes/${noteRow.id}/attachments/${attachment.id}/download`)
      assert.equal(request.options.signal?.aborted, false)
      app.currentCompany.value = { ...app.currentCompany.value!, id: 42, permissions: [] }
      await Vue.nextTick()
      assert.equal(app.notes.notes.value.length, 0)
      assert.equal(app.notes.selectedNote.value, null)
      assert.equal(request.options.signal?.aborted, true)
      request.resolve(new Blob(['protected bytes'], { type: 'application/pdf' }))
      assert.equal(await downloading, false)
      assert.equal(urlsCreated, 0)
      assert.equal(anchorClicks, 0)
    } finally {
      if (urlDescriptor) Object.defineProperty(URL, 'createObjectURL', urlDescriptor)
      else Reflect.deleteProperty(URL, 'createObjectURL')
      if (revokeDescriptor) Object.defineProperty(URL, 'revokeObjectURL', revokeDescriptor)
      else Reflect.deleteProperty(URL, 'revokeObjectURL')
      if (previousDocument === undefined) Reflect.deleteProperty(globalThis, 'document')
      else Object.defineProperty(globalThis, 'document', { configurable: true, value: previousDocument })
    }
  })

  it('blocks a late download after a separate same-context request loses authority', async (t) => {
    const app = setup({ ignoreAbort: (_url, options) => options.responseType === 'blob' })
    t.after(app.stop)
    app.initialListRequest()!.resolve(listResponse())
    await app.settle()
    const opening = app.notes.selectNote(noteRow.id)
    const detailRequest = app.requests.find(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`)!
    detailRequest.resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
    await opening

    const urlDescriptor = Object.getOwnPropertyDescriptor(URL, 'createObjectURL')
    const revokeDescriptor = Object.getOwnPropertyDescriptor(URL, 'revokeObjectURL')
    let urlsCreated = 0
    let anchorClicks = 0
    Object.defineProperty(URL, 'createObjectURL', {
      configurable: true,
      value: () => {
        urlsCreated++
        return 'blob:blocked-context'
      }
    })
    Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: () => {} })
    const previousDocument = (globalThis as typeof globalThis & { document?: unknown }).document
    Object.defineProperty(globalThis, 'document', {
      configurable: true,
      value: {
        body: { appendChild() {} },
        createElement: () => ({ click() { anchorClicks++ }, remove() {} })
      }
    })
    try {
      const downloading = app.notes.downloadAttachment(noteRow.id, attachment)
      const downloadRequest = app.requests.find(request => request.options.responseType === 'blob')!
      assert.ok(downloadRequest)
      const refresh = app.notes.refresh()
      const authorityRequest = app.requests.filter(request => request.url === '/erp/contacts/31/notes?page=1&state=active').at(-1)!
      authorityRequest.reject({ statusCode: 403, data: { message: 'Acesso negado' } })
      await refresh
      assert.equal(app.notes.authorityBlocked.value, true)
      assert.deepEqual(app.notes.notes.value, [])
      assert.equal(app.notes.selectedNote.value, null)
      assert.equal(downloadRequest.options.signal?.aborted, true)

      downloadRequest.resolve(new Blob(['protected bytes'], { type: 'application/pdf' }))
      assert.equal(await downloading, false)
      assert.equal(urlsCreated, 0)
      assert.equal(anchorClicks, 0)
    } finally {
      if (urlDescriptor) Object.defineProperty(URL, 'createObjectURL', urlDescriptor)
      else Reflect.deleteProperty(URL, 'createObjectURL')
      if (revokeDescriptor) Object.defineProperty(URL, 'revokeObjectURL', revokeDescriptor)
      else Reflect.deleteProperty(URL, 'revokeObjectURL')
      if (previousDocument === undefined) Reflect.deleteProperty(globalThis, 'document')
      else Object.defineProperty(globalThis, 'document', { configurable: true, value: previousDocument })
    }
  })

  it('ignores list and detail reads that resolve after an accepted update', async (t) => {
    const app = setup({ ignoreAbort: () => true })
    t.after(app.stop)
    app.initialListRequest()!.resolve(listResponse())
    await app.settle()
    const opening = app.notes.selectNote(noteRow.id)
    const firstDetail = app.requests.find(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`)!
    firstDetail.resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
    await opening

    const staleList = app.notes.refresh()
    const staleListRequest = app.requests.filter(request => request.url === '/erp/contacts/31/notes?page=1&state=active').at(-1)!
    const staleDetail = app.notes.selectNote(noteRow.id)
    const staleDetailRequest = app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
    const update = app.notes.updateNote(noteRow.id, { heading: 'Título aceito' })
    await app.settle()
    const patchRequest = app.requests.filter(request => request.options.method === 'PATCH').at(-1)!
    assert.equal(patchRequest.url, `/erp/contacts/31/notes/${noteRow.id}`)
    const updatedNote = { ...noteRow, heading: 'Título aceito' }
    patchRequest.resolve({ data: updatedNote })
    assert.equal(await update, true)

    staleListRequest.resolve(listResponse([noteRow]))
    staleDetailRequest.resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
    await Promise.all([staleList, staleDetail])
    assert.equal(app.notes.notes.value[0]?.heading, 'Título aceito')
    assert.equal(app.notes.selectedNote.value?.heading, 'Título aceito')
  })

  it('removes a note after a visibility 404 and fences its late list and download responses only', async (t) => {
    const app = setup({ ignoreAbort: () => true })
    t.after(app.stop)
    app.initialListRequest()!.resolve(listResponse([noteRow, otherNoteRow]))
    await app.settle()

    const openingFirst = app.notes.selectNote(noteRow.id)
    app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
      .resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
    await openingFirst
    const staleRefresh = app.notes.refresh()
    const staleList = app.requests.filter(request => request.url === '/erp/contacts/31/notes?page=1&state=active').at(-1)!

    const urlDescriptor = Object.getOwnPropertyDescriptor(URL, 'createObjectURL')
    const revokeDescriptor = Object.getOwnPropertyDescriptor(URL, 'revokeObjectURL')
    let urlsCreated = 0
    let clicks = 0
    const revoked: string[] = []
    Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: () => `blob:visible-${++urlsCreated}` })
    Object.defineProperty(URL, 'revokeObjectURL', {
      configurable: true,
      value: (url: string) => {
        revoked.push(url)
      }
    })
    const previousDocument = (globalThis as typeof globalThis & { document?: unknown }).document
    Object.defineProperty(globalThis, 'document', {
      configurable: true,
      value: {
        body: { appendChild() {} },
        createElement: () => ({ click() { clicks++ }, remove() {} })
      }
    })

    try {
      const alreadyVisibleDownload = app.notes.downloadAttachment(noteRow.id, attachment)
      const alreadyVisibleBlob = app.requests.filter(request => request.options.responseType === 'blob').at(-1)!
      alreadyVisibleBlob.resolve(new Blob(['already visible'], { type: 'application/pdf' }))
      assert.equal(await alreadyVisibleDownload, true)
      assert.equal(urlsCreated, 1)

      const lateDetail = app.notes.selectNote(noteRow.id)
      const lateDetailRequest = app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
      const firstDownload = app.notes.downloadAttachment(noteRow.id, attachment)
      const lateBlob = app.requests.filter(request => request.options.responseType === 'blob').at(-1)!

      const update = app.notes.updateNote(noteRow.id, { heading: 'No longer visible' })
      await app.settle()
      const patch = app.requests.filter(request => request.options.method === 'PATCH').at(-1)!
      patch.reject({ statusCode: 404, data: { message: 'Not found' } })
      assert.equal(await update, false)
      assert.deepEqual(app.notes.notes.value.map(note => note.id), [otherNoteRow.id])
      assert.equal(app.notes.selectedNote.value, null)
      assert.deepEqual(app.notes.noteVisibilityEvent.value?.noteId, noteRow.id)
      assert.equal(app.notes.noteVisibilityEvent.value?.phase, 'lost')
      assert.deepEqual(revoked, ['blob:visible-1'])

      staleList.resolve(listResponse([noteRow, otherNoteRow]))
      lateDetailRequest.resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
      await Promise.all([staleRefresh, lateDetail])
      assert.deepEqual(app.notes.notes.value.map(note => note.id), [otherNoteRow.id])
      assert.equal(app.notes.selectedNote.value, null)

      const openingSecond = app.notes.selectNote(otherNoteRow.id)
      app.requests.filter(request => request.url === `/erp/contacts/31/notes/${otherNoteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
        .resolve({ data: otherDetail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } })
      await openingSecond
      const secondDownload = app.notes.downloadAttachment(otherNoteRow.id, { ...attachment, id: 10 })
      const secondBlob = app.requests.filter(request => request.options.responseType === 'blob').at(-1)!

      lateBlob.resolve(new Blob(['private bytes'], { type: 'application/pdf' }))
      secondBlob.resolve(new Blob(['visible bytes'], { type: 'application/pdf' }))
      assert.equal(await firstDownload, false)
      assert.equal(await secondDownload, true)
      assert.equal(urlsCreated, 2)
      assert.equal(clicks, 2)
    } finally {
      if (urlDescriptor) Object.defineProperty(URL, 'createObjectURL', urlDescriptor)
      else Reflect.deleteProperty(URL, 'createObjectURL')
      if (revokeDescriptor) Object.defineProperty(URL, 'revokeObjectURL', revokeDescriptor)
      else Reflect.deleteProperty(URL, 'revokeObjectURL')
      if (previousDocument === undefined) Reflect.deleteProperty(globalThis, 'document')
      else Object.defineProperty(globalThis, 'document', { configurable: true, value: previousDocument })
    }
  })

  it('hides a note while an attachment 404 is reconciled and restores it only when detail remains visible', async (t) => {
    const app = setup({ ignoreAbort: () => true })
    t.after(app.stop)
    app.initialListRequest()!.resolve(listResponse())
    await app.settle()
    const opening = app.notes.selectNote(noteRow.id)
    app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
      .resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
    await opening

    const upload = app.notes.uploadAttachment(noteRow.id, new File(['new bytes'], 'new.pdf', { type: 'application/pdf' }))
    await app.settle()
    const post = app.requests.filter(request => request.options.method === 'POST').at(-1)!
    post.reject({ statusCode: 404, data: { message: 'Not found' } })
    await app.settle()
    assert.deepEqual(app.notes.notes.value, [])
    assert.equal(app.notes.selectedNote.value, null)
    assert.equal(app.notes.noteVisibilityEvent.value?.phase, 'resolving')
    assert.equal(app.notes.loadingDetail.value, true)

    const recheck = app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
    assert.notEqual(recheck, post)
    recheck.resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
    assert.equal(await upload, false)
    assert.deepEqual(app.notes.notes.value, [noteRow])
    assert.equal(app.notes.selectedNote.value?.id, noteRow.id)
    assert.equal(app.notes.loadingDetail.value, false)
    assert.match(app.notes.error.value, /arquivo não está mais disponível/i)
  })

  it('reconciles download 404s with the note detail route and clears loading after either result', async (t) => {
    const previousDocument = (globalThis as typeof globalThis & { document?: unknown }).document
    Object.defineProperty(globalThis, 'document', {
      configurable: true,
      value: { body: { appendChild() {} }, createElement: () => ({ click() {}, remove() {} }) }
    })

    try {
      for (const remainsVisible of [true, false]) {
        const app = setup({ ignoreAbort: () => true })
        t.after(app.stop)
        app.initialListRequest()!.resolve(listResponse())
        await app.settle()
        const opening = app.notes.selectNote(noteRow.id)
        app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
          .resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
        await opening

        const download = app.notes.downloadAttachment(noteRow.id, attachment)
        const blobRequest = app.requests.filter(request => request.options.responseType === 'blob').at(-1)!
        blobRequest.reject({ statusCode: 404, data: { message: 'Not found' } })
        await app.settle()
        const recheck = app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
        assert.equal(app.notes.noteVisibilityEvent.value?.phase, 'resolving')
        assert.equal(app.notes.loadingDetail.value, true)

        if (remainsVisible) {
          recheck.resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
        } else {
          recheck.reject({ statusCode: 404, data: { message: 'Not found' } })
        }

        assert.equal(await download, false)
        assert.equal(app.notes.loadingDetail.value, false)
        if (remainsVisible) {
          assert.deepEqual(app.notes.notes.value, [noteRow])
          assert.equal(app.notes.selectedNote.value?.id, noteRow.id)
          assert.equal(app.notes.noteVisibilityEvent.value?.phase, 'resolving')
        } else {
          assert.deepEqual(app.notes.notes.value, [])
          assert.equal(app.notes.selectedNote.value, null)
          assert.equal(app.notes.noteVisibilityEvent.value?.phase, 'lost')
        }
      }
    } finally {
      if (previousDocument === undefined) Reflect.deleteProperty(globalThis, 'document')
      else Object.defineProperty(globalThis, 'document', { configurable: true, value: previousDocument })
    }
  })

  it('keeps note recovery retryable and restores or loses only after its authorized retry', async (t) => {
    for (const remainsVisible of [true, false]) {
      const app = setup({ ignoreAbort: () => true })
      t.after(app.stop)
      app.initialListRequest()!.resolve(listResponse())
      await app.settle()
      const opening = app.notes.selectNote(noteRow.id)
      app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
        .resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
      await opening

      const upload = app.notes.uploadAttachment(noteRow.id, new File(['new bytes'], 'new.pdf', { type: 'application/pdf' }))
      await app.settle()
      app.requests.filter(request => request.options.method === 'POST').at(-1)!
        .reject({ statusCode: 404, data: { message: 'Not found' } })
      await app.settle()
      const firstRecheck = app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
      firstRecheck.reject({ statusCode: 503, data: { message: 'Unavailable' } })
      assert.equal(await upload, false)
      assert.deepEqual(app.notes.notes.value, [])
      assert.equal(app.notes.selectedNote.value, null)
      assert.equal(app.notes.listMeta.value?.total, 0)
      assert.equal(app.notes.noteVisibilityEvent.value?.phase, 'retryable')
      assert.match(app.notes.error.value, /conteúdo permanece oculto/i)

      const firstRetry = app.notes.retryNoteAvailability(noteRow.id)
      await app.settle()
      const retryRequest = app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
      if (remainsVisible) {
        retryRequest.reject({ statusCode: 503, data: { message: 'Unavailable again' } })
        assert.equal(await firstRetry, false)
        assert.deepEqual(app.notes.notes.value, [])
        assert.equal(app.notes.noteVisibilityEvent.value?.phase, 'retryable')

        const refreshedWhileHidden = app.notes.refresh()
        app.requests.filter(request => request.url === '/erp/contacts/31/notes?page=1&state=active').at(-1)!
          .resolve(listResponse([otherNoteRow]))
        await refreshedWhileHidden

        const finalRetry = app.notes.retryNoteAvailability(noteRow.id)
        await app.settle()
        const finalRequest = app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
        finalRequest.resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
        await app.settle()
        const recoveredList = app.requests.filter(request => request.url === '/erp/contacts/31/notes?page=1&state=active').at(-1)!
        recoveredList.resolve(listResponse([noteRow, otherNoteRow]))
        assert.equal(await finalRetry, true)
        assert.deepEqual(app.notes.notes.value, [noteRow, otherNoteRow])
        assert.equal(app.notes.listMeta.value?.total, 2)
        assert.equal(app.notes.selectedNote.value?.id, noteRow.id)
      } else {
        retryRequest.reject({ statusCode: 404, data: { message: 'Not found' } })
        assert.equal(await firstRetry, false)
        assert.deepEqual(app.notes.notes.value, [])
        assert.equal(app.notes.selectedNote.value, null)
        assert.equal(app.notes.noteVisibilityEvent.value?.phase, 'lost')
      }
    }
  })

  it('binds a retryable note check to the current selection and context', async (t) => {
    const app = setup({ ignoreAbort: () => true })
    t.after(app.stop)
    app.initialListRequest()!.resolve(listResponse([noteRow, otherNoteRow]))
    await app.settle()
    const opening = app.notes.selectNote(noteRow.id)
    app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
      .resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
    await opening

    const upload = app.notes.uploadAttachment(noteRow.id, new File(['new bytes'], 'new.pdf', { type: 'application/pdf' }))
    await app.settle()
    app.requests.filter(request => request.options.method === 'POST').at(-1)!
      .reject({ statusCode: 404, data: { message: 'Not found' } })
    await app.settle()
    app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
      .reject({ statusCode: 503, data: { message: 'Unavailable' } })
    assert.equal(await upload, false)
    assert.equal(app.notes.noteVisibilityEvent.value?.phase, 'retryable')

    const requestCount = app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).length
    const updateOther = app.notes.updateNote(otherNoteRow.id, { heading: 'Alteração em andamento' })
    await app.settle()
    const otherPatch = app.requests.filter(request => request.options.method === 'PATCH').at(-1)!
    assert.equal(await app.notes.retryNoteAvailability(noteRow.id), false)
    assert.equal(app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).length, requestCount)
    otherPatch.resolve({ data: { ...otherNoteRow, heading: 'Alteração em andamento' } })
    assert.equal(await updateOther, true)

    const openingOther = app.notes.selectNote(otherNoteRow.id)
    const otherRequest = app.requests.filter(request => request.url === `/erp/contacts/31/notes/${otherNoteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
    otherRequest.resolve({ data: otherDetail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } })
    await openingOther
    assert.equal(app.notes.selectedNote.value?.id, otherNoteRow.id)
  })

  it('clears a stale retry marker after an authorized manual detail read of the same note', async (t) => {
    const app = setup({ ignoreAbort: () => true })
    t.after(app.stop)
    app.initialListRequest()!.resolve(listResponse())
    await app.settle()
    const opening = app.notes.selectNote(noteRow.id)
    app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
      .resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
    await opening

    const upload = app.notes.uploadAttachment(noteRow.id, new File(['new bytes'], 'new.pdf', { type: 'application/pdf' }))
    await app.settle()
    app.requests.filter(request => request.options.method === 'POST').at(-1)!
      .reject({ statusCode: 404, data: { message: 'Not found' } })
    await app.settle()
    app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
      .reject({ statusCode: 503, data: { message: 'Unavailable' } })
    assert.equal(await upload, false)
    assert.equal(app.notes.noteVisibilityEvent.value?.phase, 'retryable')

    const refreshed = app.notes.refresh()
    app.requests.filter(request => request.url === '/erp/contacts/31/notes?page=1&state=active').at(-1)!
      .resolve(listResponse())
    await refreshed
    const manualOpen = app.notes.selectNote(noteRow.id)
    app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
      .resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
    await manualOpen

    assert.equal(app.notes.noteVisibilityEvent.value, null)
    assert.equal(app.notes.selectedNote.value?.heading, detail.heading)
    assert.equal(await app.notes.retryNoteAvailability(noteRow.id), false)
  })

  it('does not decrement the pending-filter count when a retained active selection is lost', async (t) => {
    const app = setup()
    t.after(app.stop)
    app.initialListRequest()!.resolve(listResponse())
    await app.settle()
    const opening = app.notes.selectNote(noteRow.id)
    app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
      .resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
    await opening

    const changingFilter = app.notes.setFilter('deletion_pending')
    const pendingRow = { ...otherNoteRow, state: 'deletion_pending' as const }
    app.requests.filter(request => request.url === '/erp/contacts/31/notes?page=1&state=deletion_pending').at(-1)!
      .resolve({ data: [pendingRow], meta: { current_page: 1, last_page: 1, per_page: 25, total: 3, state: 'deletion_pending' } })
    await changingFilter
    assert.equal(app.notes.selectedNote.value?.id, noteRow.id, 'the active detail remains selected while the list uses another filter')
    assert.equal(app.notes.listMeta.value?.total, 3)

    const previousDocument = (globalThis as typeof globalThis & { document?: unknown }).document
    const urlDescriptor = Object.getOwnPropertyDescriptor(URL, 'createObjectURL')
    Object.defineProperty(globalThis, 'document', {
      configurable: true,
      value: { body: { appendChild() {} }, createElement: () => ({ click() {}, remove() {} }) }
    })
    Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: () => 'blob:pending-filter' })
    try {
      const download = app.notes.downloadAttachment(noteRow.id, attachment)
      app.requests.filter(request => request.options.responseType === 'blob').at(-1)!
        .reject({ statusCode: 404, data: { message: 'Not found' } })
      await app.settle()
      assert.equal(app.notes.listMeta.value?.total, 3, 'the active note was not a member of the pending list projection')
      app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
        .reject({ statusCode: 404, data: { message: 'Not found' } })
      assert.equal(await download, false)
      assert.equal(app.notes.listMeta.value?.total, 3)
    } finally {
      if (urlDescriptor) Object.defineProperty(URL, 'createObjectURL', urlDescriptor)
      else Reflect.deleteProperty(URL, 'createObjectURL')
      if (previousDocument === undefined) Reflect.deleteProperty(globalThis, 'document')
      else Object.defineProperty(globalThis, 'document', { configurable: true, value: previousDocument })
    }
  })

  it('refuses new downloads during a write and ignores a same-note download started before it', async (t) => {
    const previousDocument = (globalThis as typeof globalThis & { document?: unknown }).document
    Object.defineProperty(globalThis, 'document', {
      configurable: true,
      value: { body: { appendChild() {} }, createElement: () => ({ click() {}, remove() {} }) }
    })

    try {
      const sameNoteApp = setup({ ignoreAbort: () => true })
      t.after(sameNoteApp.stop)
      sameNoteApp.initialListRequest()!.resolve(listResponse())
      await sameNoteApp.settle()
      const opening = sameNoteApp.notes.selectNote(noteRow.id)
      sameNoteApp.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
        .resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
      await opening

      const sameNoteUpdate = sameNoteApp.notes.updateNote(noteRow.id, { heading: 'First accepted update' })
      await sameNoteApp.settle()
      const sameNotePatch = sameNoteApp.requests.filter(request => request.options.method === 'PATCH').at(-1)!
      assert.equal(await sameNoteApp.notes.downloadAttachment(noteRow.id, attachment), false)
      assert.equal(sameNoteApp.requests.filter(request => request.options.responseType === 'blob').length, 0)
      sameNotePatch.resolve({ data: { ...noteRow, heading: 'First accepted update' } })
      assert.equal(await sameNoteUpdate, true)
      assert.equal(sameNoteApp.notes.selectedNote.value?.heading, 'First accepted update')

      const oldDownload = sameNoteApp.notes.downloadAttachment(noteRow.id, attachment)
      const oldBlob = sameNoteApp.requests.filter(request => request.options.responseType === 'blob').at(-1)!
      const acceptedUpdate = sameNoteApp.notes.updateNote(noteRow.id, { heading: 'Second accepted update' })
      await sameNoteApp.settle()
      const acceptedPatch = sameNoteApp.requests.filter(request => request.options.method === 'PATCH').at(-1)!
      assert.equal(oldBlob.options.signal?.aborted, false, 'starting the write does not revoke an issued download request')
      oldBlob.reject({ statusCode: 404, data: { message: 'Not found' } })
      assert.equal(await oldDownload, false)
      assert.equal(sameNoteApp.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).length, 1, 'the late download 404 must not start a detail recheck')
      acceptedPatch.resolve({ data: { ...noteRow, heading: 'Second accepted update' } })
      assert.equal(await acceptedUpdate, true)
      assert.equal(sameNoteApp.notes.selectedNote.value?.heading, 'Second accepted update')

      const unrelatedApp = setup({ ignoreAbort: () => true })
      t.after(unrelatedApp.stop)
      unrelatedApp.initialListRequest()!.resolve(listResponse([noteRow, otherNoteRow]))
      await unrelatedApp.settle()
      const openingOther = unrelatedApp.notes.selectNote(noteRow.id)
      unrelatedApp.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
        .resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
      await openingOther

      const otherDownload = unrelatedApp.notes.downloadAttachment(noteRow.id, attachment)
      const otherBlob = unrelatedApp.requests.filter(request => request.options.responseType === 'blob').at(-1)!
      const otherUpdate = unrelatedApp.notes.updateNote(otherNoteRow.id, { heading: 'Still visible' })
      await unrelatedApp.settle()
      const otherPatch = unrelatedApp.requests.filter(request => request.options.method === 'PATCH').at(-1)!
      assert.equal(otherBlob.options.signal?.aborted, false, 'an unrelated note write must not abort the pending file request')
      otherBlob.reject({ statusCode: 404, data: { message: 'Not found' } })
      await unrelatedApp.settle()
      const noteRecheck = unrelatedApp.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
      noteRecheck.reject({ statusCode: 404, data: { message: 'Not found' } })
      assert.equal(await otherDownload, false)
      otherPatch.resolve({ data: { ...otherNoteRow, heading: 'Still visible' } })
      assert.equal(await otherUpdate, true)
      assert.deepEqual(unrelatedApp.notes.notes.value.map(note => note.id), [otherNoteRow.id])
      assert.equal(unrelatedApp.notes.notes.value[0]?.heading, 'Still visible')
    } finally {
      if (previousDocument === undefined) Reflect.deleteProperty(globalThis, 'document')
      else Object.defineProperty(globalThis, 'document', { configurable: true, value: previousDocument })
    }
  })

  it('treats an attachment 404 followed by a note 404 as note visibility loss', async (t) => {
    const app = setup({ ignoreAbort: () => true })
    t.after(app.stop)
    app.initialListRequest()!.resolve(listResponse())
    await app.settle()
    const opening = app.notes.selectNote(noteRow.id)
    app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
      .resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
    await opening

    const upload = app.notes.uploadAttachment(noteRow.id, new File(['new bytes'], 'new.pdf', { type: 'application/pdf' }))
    await app.settle()
    app.requests.filter(request => request.options.method === 'POST').at(-1)!
      .reject({ statusCode: 404, data: { message: 'Not found' } })
    await app.settle()
    const recheck = app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
    recheck.reject({ statusCode: 404, data: { message: 'Not found' } })
    assert.equal(await upload, false)
    assert.deepEqual(app.notes.notes.value, [])
    assert.equal(app.notes.selectedNote.value, null)
    assert.equal(app.notes.noteVisibilityEvent.value?.phase, 'lost')
  })

  it('does not restore a note after attachment reconciliation when the user selects another note', async (t) => {
    const app = setup({ ignoreAbort: () => true })
    t.after(app.stop)
    app.initialListRequest()!.resolve(listResponse([noteRow, otherNoteRow]))
    await app.settle()
    const opening = app.notes.selectNote(noteRow.id)
    app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
      .resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
    await opening

    const previousDocument = (globalThis as typeof globalThis & { document?: unknown }).document
    Object.defineProperty(globalThis, 'document', { configurable: true, value: { body: { appendChild() {} }, createElement: () => ({ click() {}, remove() {} }) } })
    try {
      const download = app.notes.downloadAttachment(noteRow.id, attachment)
      const blobRequest = app.requests.filter(request => request.options.responseType === 'blob').at(-1)!
      blobRequest.reject({ statusCode: 404, data: { message: 'Not found' } })
      await app.settle()
      const oldNoteRecheck = app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
      assert.equal(app.notes.selectedNote.value, null)
      assert.deepEqual(app.notes.notes.value.map(note => note.id), [otherNoteRow.id])

      const openingOther = app.notes.selectNote(otherNoteRow.id)
      app.requests.filter(request => request.url === `/erp/contacts/31/notes/${otherNoteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
        .resolve({ data: otherDetail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } })
      await openingOther
      oldNoteRecheck.resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
      assert.equal(await download, false)
      assert.deepEqual(app.notes.notes.value.map(note => note.id), [otherNoteRow.id])
      assert.equal(app.notes.selectedNote.value?.id, otherNoteRow.id)
    } finally {
      if (previousDocument === undefined) Reflect.deleteProperty(globalThis, 'document')
      else Object.defineProperty(globalThis, 'document', { configurable: true, value: previousDocument })
    }
  })

  it('does not restore attachment detail from a prior Company after its 404 recheck resolves', async (t) => {
    const app = setup({ ignoreAbort: () => true })
    t.after(app.stop)
    app.initialListRequest()!.resolve(listResponse())
    await app.settle()
    const opening = app.notes.selectNote(noteRow.id)
    app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!
      .resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
    await opening

    const previousDocument = (globalThis as typeof globalThis & { document?: unknown }).document
    Object.defineProperty(globalThis, 'document', { configurable: true, value: { body: { appendChild() {} }, createElement: () => ({ click() {}, remove() {} }) } })
    try {
      const download = app.notes.downloadAttachment(noteRow.id, attachment)
      const blobRequest = app.requests.filter(request => request.options.responseType === 'blob').at(-1)!
      blobRequest.reject({ statusCode: 404, data: { message: 'Not found' } })
      await app.settle()
      const oldCompanyRecheck = app.requests.filter(request => request.url === `/erp/contacts/31/notes/${noteRow.id}?attachments_page=1&attachments_per_page=25`).at(-1)!

      app.currentCompany.value = { ...app.currentCompany.value!, id: 42 }
      await app.settle()
      const newCompanyList = app.requests.filter(request => request.url === '/erp/contacts/31/notes?page=1&state=active').at(-1)!
      newCompanyList.resolve(listResponse([]))
      await app.settle()
      oldCompanyRecheck.resolve({ data: detail, meta: { attachments: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
      assert.equal(await download, false)
      assert.deepEqual(app.notes.notes.value, [])
      assert.equal(app.notes.selectedNote.value, null)
    } finally {
      if (previousDocument === undefined) Reflect.deleteProperty(globalThis, 'document')
      else Object.defineProperty(globalThis, 'document', { configurable: true, value: previousDocument })
    }
  })
})

type HostNode = {
  tag: string
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
  text: string
}

function makeHostRenderer() {
  const createNode = (tag: string, text = ''): HostNode => ({ tag, props: {}, children: [], parent: null, text })
  const hostRenderer = Vue.createRenderer<HostNode, HostNode>({
    patchProp(node, key, _previous, next) { node.props[key] = next },
    insert(node, parent, anchor = null) {
      if (node.parent) {
        const index = node.parent.children.indexOf(node)
        if (index >= 0) node.parent.children.splice(index, 1)
      }
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
    },
    remove(node) {
      if (!node.parent) return
      const index = node.parent.children.indexOf(node)
      if (index >= 0) node.parent.children.splice(index, 1)
      node.parent = null
    },
    createElement: tag => createNode(tag),
    createText: text => createNode('#text', text),
    createComment: text => createNode('#comment', text),
    setText(node, text) { node.text = text },
    setElementText(node, text) {
      node.text = text
      node.children = []
    },
    parentNode: node => node.parent,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insertStaticContent(content, parent, anchor) {
      const node = createNode('#static', content.replace(/<[^>]*>/g, ''))
      this.insert(node, parent, anchor)
      return [node, node]
    }
  })
  return { hostRenderer, createNode }
}

function hostDescendants(root: HostNode): HostNode[] {
  return [root, ...root.children.flatMap(hostDescendants)]
}

function hostText(root: HostNode): string {
  return `${root.text}${root.children.map(hostText).join('')}`
}

function mountPanel(initial: { deferNoteId?: number, uploadSucceeds?: boolean, deferCreate?: boolean } = {}) {
  const fieldErrors = Vue.ref<Record<string, string[]>>({})
  const error = Vue.ref('')
  const selectedNote = Vue.ref<NoteDetail | null>(null)
  const rows = Vue.ref<Note[]>(initial.deferNoteId === otherNoteRow.id ? [noteRow, otherNoteRow] : [noteRow])
  const contextKey = Vue.ref('actor-5-company-41')
  const noteVisibilityEvent = Vue.ref<{ noteId: number, phase: 'resolving' | 'retryable' | 'lost', sequence: number } | null>(null)
  const mutationPending = Vue.ref(false)
  let createPayload: unknown
  let uploadFile: File | undefined
  let retriedNoteId: number | null = null
  let releaseCreatePost: (() => void) | null = null
  let releaseCreateRefresh: (() => void) | null = null
  let createRefreshPending = false
  let releaseDetail: (() => void) | null = null
  const loadingDetail = Vue.ref(false)
  const notesApi = {
    notes: rows,
    selectedNote,
    page: Vue.ref(1),
    filter: Vue.ref<'active' | 'deletion_pending'>('active'),
    listMeta: Vue.ref({ current_page: 1, last_page: 1, per_page: 25, total: 1, state: 'active' as const }),
    attachmentsMeta: Vue.ref({ current_page: 1, last_page: 1, per_page: 25, total: 1 }),
    pending: Vue.ref(false),
    hasLoaded: Vue.ref(true),
    loadingDetail,
    saving: Vue.ref(false),
    archiving: Vue.ref(false),
    uploading: Vue.ref(false),
    archivingAttachment: Vue.ref(false),
    mutationPending,
    error,
    fieldErrors,
    stale: Vue.ref(false),
    authorityBlocked: Vue.ref(false),
    noteVisibilityEvent,
    permissions: {
      index: Vue.ref(true), show: Vue.ref(true), store: Vue.ref(true), update: Vue.ref(true), archive: Vue.ref(true),
      attachmentStore: Vue.ref(true), attachmentDownload: Vue.ref(true), attachmentArchive: Vue.ref(true)
    },
    contextKey,
    retryNoteAvailability: async (noteId: number) => {
      retriedNoteId = noteId
      return false
    },
    refresh: async () => undefined,
    setFilter: async () => undefined,
    selectNote: async (noteId: number) => {
      if (initial.deferNoteId === noteId) {
        selectedNote.value = null
        loadingDetail.value = true
        await new Promise<void>((resolve) => {
          releaseDetail = () => {
            selectedNote.value = otherDetail
            loadingDetail.value = false
            resolve()
          }
        })
        return
      }
      selectedNote.value = noteId === otherNoteRow.id ? otherDetail : detail
    },
    createNote: async (payload: unknown) => {
      createPayload = payload
      if (initial.deferCreate) {
        await new Promise<void>((resolve) => {
          releaseCreatePost = resolve
        })
        createRefreshPending = true
        await new Promise<void>((resolve) => {
          releaseCreateRefresh = resolve
        })
        return true
      }
      fieldErrors.value = { heading: ['O título deve ter no máximo 255 caracteres.'] }
      error.value = 'Não foi possível salvar.'
      return false
    },
    updateNote: async () => false,
    archiveNote: async () => false,
    uploadAttachment: async (_noteId: number, file: File) => {
      uploadFile = file
      if (initial.uploadSucceeds) {
        selectedNote.value = {
          ...selectedNote.value!,
          attachments: [...selectedNote.value!.attachments, { ...attachment, id: 10, original_name: 'comprovante.pdf' }],
          attachments_count: selectedNote.value!.attachments_count + 1
        }
        return true
      }
      fieldErrors.value = { file: ['O arquivo enviado não é válido.'] }
      error.value = 'Serviço temporariamente indisponível.'
      return false
    },
    downloadAttachment: async () => true,
    archiveAttachment: async () => false
  }
  const component = compileVueComponent(
    'components/erp/catalogs/ErpContactNotesPanel.vue',
    {},
    { '~/composables/useErpContactNotes': { useErpContactNotes: () => notesApi } }
  )
  const { hostRenderer, createNode } = makeHostRenderer()
  const app = hostRenderer.createApp(component, { contactId: 31 })
  app.component('UForm', Vue.defineComponent({
    props: ['state', 'disabled'],
    emits: ['submit'],
    setup(props, { slots, emit }) {
      return () => Vue.h('form', {
        'data-test-form': 'true',
        'onSubmit': (event: { preventDefault(): void }) => {
          event.preventDefault()
          emit('submit', { data: props.state })
        }
      }, slots.default?.())
    }
  }))
  app.component('UFormField', Vue.defineComponent({
    props: ['name', 'label', 'error'],
    setup(props, { slots }) {
      return () => Vue.h('div', { 'data-field': props.name }, [
        Vue.h('label', String(props.label ?? '')),
        slots.default?.(),
        props.error ? Vue.h('span', { 'data-error': props.error }, String(props.error)) : null
      ])
    }
  }))
  app.component('UInput', Vue.defineComponent({
    props: ['modelValue', 'name', 'type', 'disabled', 'maxlength'],
    emits: ['update:modelValue'],
    setup(props, { emit }) {
      return () => Vue.h('input', {
        name: props.name,
        type: props.type ?? 'text',
        value: props.modelValue ?? '',
        disabled: props.disabled,
        maxlength: props.maxlength,
        onInput: (event: { target: { value: string } }) => emit('update:modelValue', event.target.value)
      })
    }
  }))
  app.component('UTextarea', Vue.defineComponent({
    props: ['modelValue', 'name', 'disabled', 'maxlength'],
    emits: ['update:modelValue'],
    setup(props, { emit }) {
      return () => Vue.h('textarea', {
        name: props.name,
        value: props.modelValue ?? '',
        disabled: props.disabled,
        maxlength: props.maxlength,
        onInput: (event: { target: { value: string } }) => emit('update:modelValue', event.target.value)
      })
    }
  }))
  app.component('UCheckbox', Vue.defineComponent({
    props: ['modelValue', 'name', 'label', 'disabled'],
    emits: ['update:modelValue'],
    setup(props, { emit }) {
      return () => Vue.h('label', [
        Vue.h('input', {
          type: 'checkbox',
          name: props.name,
          checked: props.modelValue,
          disabled: props.disabled,
          onChange: (event: { target: { checked: boolean } }) => emit('update:modelValue', event.target.checked)
        }),
        String(props.label ?? '')
      ])
    }
  }))
  app.component('UFileUpload', Vue.defineComponent({
    props: ['modelValue', 'name', 'disabled', 'fileImage', 'accept'],
    emits: ['update:modelValue'],
    setup(props, { emit }) {
      return () => Vue.h('input', {
        'type': 'file',
        'name': props.name,
        'disabled': props.disabled,
        'accept': props.accept,
        'data-file-image': String(props.fileImage),
        'data-selected-file': (props.modelValue as File | null)?.name ?? '',
        'onChange': (event: { target: { files: File[] } }) => emit('update:modelValue', event.target.files[0] ?? null)
      })
    }
  }))
  app.component('UButton', Vue.defineComponent({
    props: ['label', 'disabled', 'type'],
    setup(props, { attrs }) {
      return () => Vue.h('button', { ...attrs, type: props.type ?? 'button', disabled: props.disabled }, String(props.label ?? ''))
    }
  }))
  app.component('UAlert', Vue.defineComponent({
    props: ['title', 'description'],
    setup(props) { return () => Vue.h('div', { role: 'alert' }, `${props.title ?? ''} ${props.description ?? ''}`) }
  }))
  app.component('UBadge', Vue.defineComponent({ props: ['label'], setup: props => () => Vue.h('span', String(props.label ?? '')) }))
  app.component('UPagination', Vue.defineComponent({ setup: () => () => Vue.h('nav') }))
  app.component('UModal', Vue.defineComponent({
    props: ['open', 'title', 'description'],
    setup(props, { slots }) {
      return () => props.open ? Vue.h('div', [slots.body?.(), slots.footer?.()]) : null
    }
  }))
  const root = createNode('root')
  app.mount(root)
  return {
    app,
    root,
    notesApi,
    getCreatePayload: () => createPayload,
    getUploadFile: () => uploadFile,
    resolveCreatePost: () => releaseCreatePost?.(),
    resolveCreateRefresh: () => releaseCreateRefresh?.(),
    isCreateRefreshPending: () => createRefreshPending,
    setNoteVisibilityEvent: (event: { noteId: number, phase: 'resolving' | 'retryable' | 'lost', sequence: number } | null) => { noteVisibilityEvent.value = event },
    getRetriedNoteId: () => retriedNoteId,
    resolveDetail: () => releaseDetail?.()
  }
}

describe('ERP Contact notes panel', () => {
  it('mounts sibling forms, retains typed text and selected file after field validation errors, and clears local input on context change', async (t) => {
    const panel = mountPanel()
    t.after(() => panel.app.unmount())
    const settle = async () => {
      await Vue.nextTick()
      await Promise.resolve()
      await Vue.nextTick()
    }
    const findAll = (predicate: (node: HostNode) => boolean) => hostDescendants(panel.root).filter(predicate)
    const headingInput = () => findAll(node => node.tag === 'input' && node.props.name === 'heading')[0]
    headingInput().props.onInput?.({ target: { value: 'Sentinela para erro 422' } })
    await settle()

    const createForm = findAll(node => node.tag === 'form')[0]
    createForm.props.onSubmit?.({ preventDefault() {} })
    await settle()
    assert.deepEqual(panel.getCreatePayload(), {
      heading: 'Sentinela para erro 422',
      description: null,
      is_private: false
    })
    assert.equal(headingInput().props.value, 'Sentinela para erro 422')
    assert.ok(findAll(node => node.props['data-error'] === 'O título deve ter no máximo 255 caracteres.').length)

    const openDetails = findAll(node => node.tag === 'button' && hostText(node) === 'Ver detalhes')[0]
    openDetails.props.onClick?.()
    await settle()
    const fileInput = () => findAll(node => node.tag === 'input' && node.props.type === 'file')[0]
    const file = new File(['protected sample'], 'cliente.pdf', { type: 'application/pdf' })
    assert.ok(fileInput())
    assert.equal(fileInput().props['data-file-image'], 'false')
    fileInput().props.onChange?.({ target: { files: [file] } })
    await settle()
    assert.ok(findAll(node => node.tag === 'form').every((form) => {
      let parent = form.parent
      while (parent) {
        if (parent.tag === 'form') return false
        parent = parent.parent
      }
      return true
    }), 'UForm elements must remain siblings and never nest')

    const uploadForm = findAll(node => node.tag === 'form').find(form => hostDescendants(form).some(node => node.tag === 'input' && node.props.type === 'file'))!
    uploadForm.props.onSubmit?.({ preventDefault() {} })
    await settle()
    assert.equal(panel.getUploadFile(), file)
    assert.equal(fileInput().props['data-selected-file'], 'cliente.pdf')
    assert.ok(findAll(node => node.props['data-error'] === 'O arquivo enviado não é válido.').length)

    panel.notesApi.contextKey.value = 'actor-6-company-41'
    await settle()
    findAll(node => node.tag === 'button' && hostText(node) === 'Ver detalhes')[0].props.onClick?.()
    await settle()
    assert.equal(fileInput().props['data-selected-file'], '')
  })

  it('clears a file selected for note A while note B detail is loading', async (t) => {
    const panel = mountPanel({ deferNoteId: otherNoteRow.id })
    t.after(() => panel.app.unmount())
    const settle = async () => {
      await Vue.nextTick()
      await Promise.resolve()
      await Vue.nextTick()
    }
    const findAll = (predicate: (node: HostNode) => boolean) => hostDescendants(panel.root).filter(predicate)
    const fileInput = () => findAll(node => node.tag === 'input' && node.props.type === 'file')[0]
    const openButtons = () => findAll(node => node.tag === 'button' && hostText(node) === 'Ver detalhes')

    openButtons()[0].props.onClick?.()
    await settle()
    const file = new File(['note A attachment'], 'nota-a.pdf', { type: 'application/pdf' })
    fileInput().props.onChange?.({ target: { files: [file] } })
    await settle()
    assert.equal(fileInput().props['data-selected-file'], 'nota-a.pdf')

    openButtons()[0].props.onClick?.()
    await settle()
    assert.equal(panel.notesApi.loadingDetail.value, true)
    assert.equal(panel.notesApi.selectedNote.value, null)
    assert.equal(fileInput(), undefined)

    panel.resolveDetail()
    await settle()
    assert.equal(panel.notesApi.selectedNote.value?.id, otherNoteRow.id)
    assert.equal(fileInput().props['data-selected-file'], '')
    const uploadForm = findAll(node => node.tag === 'form').find(form => hostDescendants(form).some(node => node.tag === 'input' && node.props.type === 'file'))!
    uploadForm.props.onSubmit?.({ preventDefault() {} })
    await settle()
    assert.equal(panel.getUploadFile(), undefined, 'note A file must never be submitted to note B')
  })

  it('preserves an unsaved edit when an attachment response updates note details', async (t) => {
    const panel = mountPanel({ uploadSucceeds: true })
    t.after(() => panel.app.unmount())
    const settle = async () => {
      await Vue.nextTick()
      await Promise.resolve()
      await Vue.nextTick()
    }
    const findAll = (predicate: (node: HostNode) => boolean) => hostDescendants(panel.root).filter(predicate)
    findAll(node => node.tag === 'button' && hostText(node) === 'Ver detalhes')[0].props.onClick?.()
    await settle()
    const headingInputs = () => findAll(node => node.tag === 'input' && node.props.name === 'heading')
    const editHeading = headingInputs()[1]
    editHeading.props.onInput?.({ target: { value: 'Rascunho ainda não salvo' } })
    await settle()
    const fileInput = findAll(node => node.tag === 'input' && node.props.type === 'file')[0]
    fileInput.props.onChange?.({ target: { files: [new File(['receipt'], 'comprovante.pdf', { type: 'application/pdf' })] } })
    await settle()
    const uploadForm = findAll(node => node.tag === 'form').find(form => hostDescendants(form).some(node => node.tag === 'input' && node.props.type === 'file'))!
    uploadForm.props.onSubmit?.({ preventDefault() {} })
    await settle()

    assert.equal(panel.notesApi.selectedNote.value?.attachments_count, 2)
    assert.equal(headingInputs()[1].props.value, 'Rascunho ainda não salvo')
    assert.equal(fileInput.props['data-selected-file'], '')
  })

  it('clears note-local input during visibility reconciliation while preserving a new note draft', async (t) => {
    const panel = mountPanel()
    t.after(() => panel.app.unmount())
    const settle = async () => {
      await Vue.nextTick()
      await Promise.resolve()
      await Vue.nextTick()
    }
    const findAll = (predicate: (node: HostNode) => boolean) => hostDescendants(panel.root).filter(predicate)
    const createHeading = () => findAll(node => node.tag === 'input' && node.props.name === 'heading')[0]
    createHeading().props.onInput?.({ target: { value: 'Novo rascunho preservado' } })
    findAll(node => node.tag === 'button' && hostText(node) === 'Ver detalhes')[0].props.onClick?.()
    await settle()

    const editHeading = () => findAll(node => node.tag === 'input' && node.props.name === 'heading')[1]
    editHeading().props.onInput?.({ target: { value: 'Rascunho da nota afetada' } })
    const file = new File(['attachment'], 'anexo-local.pdf', { type: 'application/pdf' })
    const fileInput = () => findAll(node => node.tag === 'input' && node.props.type === 'file')[0]
    fileInput().props.onChange?.({ target: { files: [file] } })
    await settle()
    assert.equal(fileInput().props['data-selected-file'], 'anexo-local.pdf')
    findAll(node => node.tag === 'button' && hostText(node) === 'Solicitar remoção').at(-1)!.props.onClick?.()
    await settle()
    assert.equal(findAll(node => node.tag === 'button' && hostText(node) === 'Confirmar remoção').length, 1)

    panel.setNoteVisibilityEvent({ noteId: noteRow.id, phase: 'resolving', sequence: 1 })
    panel.notesApi.selectedNote.value = null
    panel.notesApi.loadingDetail.value = true
    panel.notesApi.notes.value = []
    await settle()
    assert.equal(findAll(node => node.tag === 'input' && node.props.name === 'heading').length, 1)
    assert.equal(findAll(node => node.tag === 'button' && hostText(node) === 'Confirmar remoção').length, 0)

    panel.notesApi.selectedNote.value = detail
    panel.notesApi.loadingDetail.value = false
    panel.notesApi.notes.value = [noteRow]
    await settle()
    assert.equal(fileInput().props['data-selected-file'], '')
    assert.equal(editHeading().props.value, detail.heading)
    assert.equal(createHeading().props.value, 'Novo rascunho preservado')

    panel.setNoteVisibilityEvent({ noteId: noteRow.id, phase: 'lost', sequence: 2 })
    panel.notesApi.selectedNote.value = null
    panel.notesApi.notes.value = []
    await settle()
    createHeading().props.onInput?.({ target: { value: 'Rascunho após 404' } })
    await settle()
    assert.equal(createHeading().props.value, 'Rascunho após 404')
    assert.equal(findAll(node => node.tag === 'button' && hostText(node) === 'Atualizar detalhes').length, 0)
  })

  it('keeps note content hidden during a retryable check and retries from the mounted panel', async (t) => {
    const panel = mountPanel()
    t.after(() => panel.app.unmount())
    const settle = async () => {
      await Vue.nextTick()
      await Promise.resolve()
      await Vue.nextTick()
    }
    const findAll = (predicate: (node: HostNode) => boolean) => hostDescendants(panel.root).filter(predicate)
    findAll(node => node.tag === 'button' && hostText(node) === 'Ver detalhes')[0].props.onClick?.()
    await settle()
    assert.ok(hostText(panel.root).includes(noteRow.heading!))
    const downloadButton = findAll(node => node.tag === 'button' && hostText(node) === 'Baixar')[0]
    assert.ok(downloadButton)
    panel.notesApi.mutationPending.value = true
    await settle()
    assert.equal(downloadButton.props.disabled, true)
    panel.notesApi.mutationPending.value = false
    await settle()

    panel.setNoteVisibilityEvent({ noteId: noteRow.id, phase: 'resolving', sequence: 1 })
    panel.notesApi.selectedNote.value = null
    panel.notesApi.notes.value = []
    panel.notesApi.loadingDetail.value = true
    await settle()
    panel.notesApi.loadingDetail.value = false
    panel.setNoteVisibilityEvent({ noteId: noteRow.id, phase: 'retryable', sequence: 2 })
    await settle()

    const hiddenText = hostText(panel.root)
    assert.ok(hiddenText.includes('O conteúdo continua oculto'))
    assert.equal(hiddenText.includes(noteRow.heading!), false)
    assert.equal(hiddenText.includes(noteRow.description!), false)
    assert.equal(hiddenText.includes(attachment.original_name), false)
    const retryButton = findAll(node => node.tag === 'button' && hostText(node) === 'Tentar novamente')[0]
    assert.ok(retryButton)
    panel.notesApi.mutationPending.value = true
    await settle()
    assert.equal(retryButton.props.disabled, true)
    panel.notesApi.mutationPending.value = false
    await settle()
    retryButton.props.onClick?.()
    await settle()
    assert.equal(panel.getRetriedNoteId(), noteRow.id)
  })

  it('clears private drafts and a selected file after same-context authority is blocked', async (t) => {
    const panel = mountPanel()
    t.after(() => panel.app.unmount())
    const settle = async () => {
      await Vue.nextTick()
      await Promise.resolve()
      await Vue.nextTick()
    }
    const findAll = (predicate: (node: HostNode) => boolean) => hostDescendants(panel.root).filter(predicate)
    const createHeading = () => findAll(node => node.tag === 'input' && node.props.name === 'heading')[0]
    createHeading().props.onInput?.({ target: { value: 'Rascunho privado local' } })
    const createPrivacy = findAll(node => node.tag === 'input' && node.props.type === 'checkbox')[0]
    createPrivacy.props.onChange?.({ target: { checked: true } })
    findAll(node => node.tag === 'button' && hostText(node) === 'Ver detalhes')[0].props.onClick?.()
    await settle()
    const fileInput = () => findAll(node => node.tag === 'input' && node.props.type === 'file')[0]
    fileInput().props.onChange?.({ target: { files: [new File(['private attachment'], 'privado.pdf', { type: 'application/pdf' })] } })
    await settle()

    panel.notesApi.authorityBlocked.value = true
    panel.notesApi.selectedNote.value = null
    panel.notesApi.notes.value = []
    await settle()
    assert.equal(findAll(node => node.tag === 'form').length, 0)

    panel.notesApi.authorityBlocked.value = false
    panel.notesApi.notes.value = [noteRow]
    await settle()
    assert.equal(createHeading().props.value, '')
    assert.equal(findAll(node => node.tag === 'input' && node.props.type === 'checkbox')[0].props.checked, false)
    findAll(node => node.tag === 'button' && hostText(node) === 'Ver detalhes')[0].props.onClick?.()
    await settle()
    assert.equal(fileInput().props['data-selected-file'], '')
  })

  it('keeps a new create draft when an old-context create and refresh finish late', async (t) => {
    const panel = mountPanel({ deferCreate: true })
    t.after(() => panel.app.unmount())
    const settle = async () => {
      await Vue.nextTick()
      await Promise.resolve()
      await Vue.nextTick()
    }
    const findAll = (predicate: (node: HostNode) => boolean) => hostDescendants(panel.root).filter(predicate)
    const headingInput = () => findAll(node => node.tag === 'input' && node.props.name === 'heading')[0]
    headingInput().props.onInput?.({ target: { value: 'Criação no contexto anterior' } })
    await settle()
    findAll(node => node.tag === 'form')[0].props.onSubmit?.({ preventDefault() {} })
    await settle()
    assert.equal((panel.getCreatePayload() as { heading: string }).heading, 'Criação no contexto anterior')
    panel.resolveCreatePost()
    await settle()
    assert.equal(panel.isCreateRefreshPending(), true)

    panel.notesApi.contextKey.value = 'actor-5-company-42'
    await settle()
    headingInput().props.onInput?.({ target: { value: 'Novo rascunho do contexto atual' } })
    await settle()
    panel.resolveCreateRefresh()
    await settle()
    assert.equal(headingInput().props.value, 'Novo rascunho do contexto atual')
  })
})
