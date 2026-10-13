export type DepartmentColor = 'neutral' | 'primary' | 'success' | 'info' | 'warning' | 'error'

export interface TeamDepartmentRef {
  id: number
  name: string
  color: DepartmentColor
}

export interface MemberDirectoryEntry {
  id: number
  name: string
  role: string
  departments: TeamDepartmentRef[]
}

export interface Department {
  id: number
  name: string
  color: DepartmentColor
  members_count?: number
  members?: MemberDirectoryEntry[]
}

export interface DepartmentWritePayload {
  name: string
  color: DepartmentColor
  member_ids?: number[]
}

export type DepartmentUpdatePayload = Partial<DepartmentWritePayload>
