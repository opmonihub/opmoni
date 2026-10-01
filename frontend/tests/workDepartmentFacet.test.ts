import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import type { DataTableFilterModel } from '../app/components/data-table/filter-model.ts'
import type { WorkTask } from '../app/types/work.ts'
import {
  filterWorkTarefasLeaves,
  filterWorkTasks,
  workDepartmentLabel,
  workTarefasFilterColumns
} from '../app/utils/workTarefasFilters.ts'
import {
  WORK_NO_DEPARTMENT_LABEL,
  WORK_NO_DEPARTMENT_VALUE,
  workDepartmentFacetColumn,
  workDepartmentKey,
  workDepartmentOptions
} from '../app/utils/workFacetFilters.ts'
import { filterWorkClientesLeaves, workClientesFilterColumns } from '../app/utils/workClientesFilters.ts'
import { filterWorkProcessosLeaves, workProcessosFilterColumns } from '../app/utils/workProcessosFilters.ts'

function task(id: number, department_id: number | null, name: string | null): WorkTask {
  return {
    id,
    title: `Tarefa ${id}`,
    department_id,
    department: department_id === null || name === null ? null : { id: department_id, name, color: 'neutral' },
    description: null,
    status: 'todo',
    due_on: '2026-03-10',
    priority: 'medium',
    assignee_member_id: null,
    order: id
  }
}

const DEPARTMENTS = [
  { id: 7, name: 'Fiscal' },
  { id: 9, name: 'Pessoal' }
]

describe('Facet de departamento por id com Sem departamento', () => {
  it('tarefas: opcoes sao ids do catalogo mais Sem departamento', () => {
    const columns = workTarefasFilterColumns({
      clients: [],
      processes: [],
      departments: DEPARTMENTS,
      assignees: [],
      includeAssignee: false
    })

    const dept = columns.find(column => column.id === 'department')
    assert.ok(dept, 'facet department existe')
    assert.deepEqual(
      dept?.options?.map(option => option.value),
      ['7', '9', WORK_NO_DEPARTMENT_VALUE]
    )
    assert.equal(
      dept?.options?.find(option => option.value === WORK_NO_DEPARTMENT_VALUE)?.label,
      WORK_NO_DEPARTMENT_LABEL
    )
  })

  it('tarefas: filtro none casa com task sem departamento e busca acha o nome atual', () => {
    const columns = workTarefasFilterColumns({
      clients: [],
      processes: [],
      departments: DEPARTMENTS,
      assignees: [],
      includeAssignee: false
    })
    const filters: DataTableFilterModel[] = [
      { columnId: 'department', type: 'option', operator: 'is', values: ['none'] }
    ]

    const tasks = [task(1, null, null), task(2, 7, 'Fiscal')]
    assert.deepEqual(filterWorkTasks(tasks, filters, columns, '').map(item => item.id), [1])
    assert.deepEqual(filterWorkTasks(tasks, [], columns, 'fiscal').map(item => item.id), [2])
    assert.equal(workDepartmentLabel(tasks[0]!), 'Sem departamento')
    assert.equal(workDepartmentLabel(tasks[1]!), 'Fiscal')
  })

  it('tarefas: filtro por id casa com leaves e busca acha o nome', () => {
    const columns = workTarefasFilterColumns({
      clients: [],
      processes: [],
      departments: DEPARTMENTS,
      assignees: [],
      includeAssignee: false
    })
    const leaves = [
      { id: 1, clientId: 1, clientName: 'Alpha', processId: 10, processName: 'Rotina', cascade: false, order: 1, title: 'Avulsa', status: 'todo' as const, department_id: null, departmentName: 'Sem departamento', due_on: '2026-03-10', priority: 'medium' as const, assignee_member_id: null },
      { id: 2, clientId: 1, clientName: 'Alpha', processId: 10, processName: 'Rotina', cascade: false, order: 2, title: 'Fiscal', status: 'todo' as const, department_id: 7, departmentName: 'Fiscal', due_on: '2026-03-10', priority: 'medium' as const, assignee_member_id: null }
    ]
    const byId: DataTableFilterModel[] = [
      { columnId: 'department', type: 'option', operator: 'is', values: ['7'] }
    ]
    assert.deepEqual(filterWorkTarefasLeaves(leaves, byId, columns, '').map(item => item.id), [2])
    const byNone: DataTableFilterModel[] = [
      { columnId: 'department', type: 'option', operator: 'is', values: ['none'] }
    ]
    assert.deepEqual(filterWorkTarefasLeaves(leaves, byNone, columns, '').map(item => item.id), [1])
  })

  it('facet generico: opcoes do catalogo mais Sem departamento e chave none', () => {
    assert.deepEqual(
      workDepartmentOptions(DEPARTMENTS).map(option => option.value),
      ['7', '9', 'none']
    )
    assert.equal(workDepartmentKey({ department_id: null }), 'none')
    assert.equal(workDepartmentKey({ department_id: 7 }), '7')
    const column = workDepartmentFacetColumn([], DEPARTMENTS)
    assert.ok(column, 'coluna do facet existe')
    assert.deepEqual(column?.options?.map(option => option.value), ['7', '9', 'none'])
  })

  it('clientes: filtro none casa com leaf sem department_id', () => {
    const leaves = [
      { id: '1', clientId: 1, clientName: 'Alpha', processId: 10, processName: 'Rotina', processRatio: 0, cascade: false, order: 1, title: 'Avulsa', status: 'todo' as const, department_id: null, departmentName: 'Sem departamento', due_on: '2026-03-10', empty: false, taskId: 1 },
      { id: '2', clientId: 1, clientName: 'Alpha', processId: 10, processName: 'Rotina', processRatio: 0, cascade: false, order: 2, title: 'Fiscal', status: 'todo' as const, department_id: 7, departmentName: 'Fiscal', due_on: '2026-03-10', empty: false, taskId: 2 }
    ]
    const columns = workClientesFilterColumns(leaves, [], DEPARTMENTS)
    assert.ok(columns.find(column => column.id === 'department'), 'facet department existe')
    assert.deepEqual(
      filterWorkClientesLeaves(leaves, [{ columnId: 'department', type: 'option', operator: 'is', values: ['none'] }], '').map(leaf => leaf.id),
      ['1']
    )
    assert.deepEqual(
      filterWorkClientesLeaves(leaves, [{ columnId: 'department', type: 'option', operator: 'is', values: ['7'] }], '').map(leaf => leaf.id),
      ['2']
    )
  })

  it('processos: filtro none casa com leaf sem department_id', () => {
    const leaves = [
      { id: '1', processKey: 'template-1', processId: 10, processName: 'Rotina', processStatus: 'open', processDueOn: null, templateId: 1, templateName: 'Modelo', clientId: 1, clientName: 'Alpha', cascade: false, title: 'Avulsa', status: 'todo' as const, department_id: null, departmentName: 'Sem departamento', due_on: '2026-03-10', empty: false, taskId: 1 },
      { id: '2', processKey: 'template-1', processId: 10, processName: 'Rotina', processStatus: 'open', processDueOn: null, templateId: 1, templateName: 'Modelo', clientId: 1, clientName: 'Alpha', cascade: false, title: 'Fiscal', status: 'todo' as const, department_id: 7, departmentName: 'Fiscal', due_on: '2026-03-10', empty: false, taskId: 2 }
    ]
    const columns = workProcessosFilterColumns(leaves, [], DEPARTMENTS)
    assert.ok(columns.find(column => column.id === 'department'), 'facet department existe')
    assert.deepEqual(
      filterWorkProcessosLeaves(leaves, [{ columnId: 'department', type: 'option', operator: 'is', values: ['none'] }], '').map(leaf => leaf.id),
      ['1']
    )
    assert.deepEqual(
      filterWorkProcessosLeaves(leaves, [], 'fiscal').map(leaf => leaf.id),
      ['2']
    )
  })
})
