export type ErpContactNoteState = 'active' | 'deletion_pending'

export type ErpContactNoteAttachmentStatus = 'ready' | 'unavailable' | 'deletion_pending'

export type ErpContactNoteAttachment = {
  id: number
  original_name: string
  mime_type: string
  byte_length: number
  sha256: string
  status: ErpContactNoteAttachmentStatus
}

export type ErpContactNote = {
  id: number
  heading: string | null
  description: string | null
  is_private: boolean
  state: ErpContactNoteState
  can_change_privacy: boolean
  attachments_count: number
  created_at: string | null
  updated_at: string | null
}

export type ErpContactNoteDetail = ErpContactNote & {
  deletion_pending_attachments_count: number
  attachments: ErpContactNoteAttachment[]
}

export type ErpContactNoteListState = 'active' | 'deletion_pending'

export type ErpContactNoteListResponse = {
  data: ErpContactNote[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    state: ErpContactNoteListState
  }
}

export type ErpContactNoteDetailResponse = {
  data: ErpContactNoteDetail
  meta: {
    attachments: {
      current_page: number
      last_page: number
      per_page: number
      total: number
    }
  }
}

export type ErpContactNotePayload = {
  heading: string | null
  description: string | null
  is_private: boolean
}

export type ErpContactNoteUpdatePayload = Partial<ErpContactNotePayload>

export type ErpContactNotePageMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
}
