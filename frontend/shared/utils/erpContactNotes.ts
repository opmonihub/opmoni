import type { ErpContactNoteListState } from '../types/erpContactNotes'

function contactNotesPath(contactId: number): string {
  return `/erp/contacts/${contactId}/notes`
}

export const erpContactNoteRoutes = {
  index(contactId: number, page: number, state: ErpContactNoteListState): string {
    const query = new URLSearchParams({ page: String(page), state })
    return `${contactNotesPath(contactId)}?${query.toString()}`
  },
  show(contactId: number, noteId: number, attachmentsPage = 1, attachmentsPerPage = 25): string {
    const query = new URLSearchParams({
      attachments_page: String(attachmentsPage),
      attachments_per_page: String(attachmentsPerPage)
    })
    return `${contactNotesPath(contactId)}/${noteId}?${query.toString()}`
  },
  store(contactId: number): string {
    return contactNotesPath(contactId)
  },
  note(contactId: number, noteId: number): string {
    return `${contactNotesPath(contactId)}/${noteId}`
  },
  attachments(contactId: number, noteId: number): string {
    return `${contactNotesPath(contactId)}/${noteId}/attachments`
  },
  attachment(contactId: number, noteId: number, attachmentId: number): string {
    return `${contactNotesPath(contactId)}/${noteId}/attachments/${attachmentId}`
  },
  download(contactId: number, noteId: number, attachmentId: number): string {
    return `${contactNotesPath(contactId)}/${noteId}/attachments/${attachmentId}/download`
  }
} as const
