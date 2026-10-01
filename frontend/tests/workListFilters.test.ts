import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import type { DataTableFilterModel } from '../app/components/data-table/filter-model.ts'
import type { WorkGroupedClient, WorkTask } from '../app/types/work.ts'
import {
  filterWorkClientesLeaves,
  workClientesFilterColumns
} from '../app/utils/workClientesFilters.ts'
import {
  filterWorkProcessosLeaves,
  workProcessosFilterColumns
} from '../app/utils/workProcessosFilters.ts'
import { filterWorkTasks, tasksForWorkScope } from '../app/utils/workTarefasFilters.ts'

function task(id: number, dueOn: string | null): WorkTask {
  return {
    id,
    title: `Tarefa ${id}`,
    department_id: 7,
    department: { id: 7, name: 'Fiscal', color: 'neutral' },
    description: null,
    status: 'todo',
    due_on: dueOn,
    priority: 'medium',
    assignee_member_id: null,
    order: id
  }
}

describe('Work task filters', () => {
  it('keeps only tasks inside an inclusive due-date range', () => {
    const tasks = [
      task(1, '2026-09-09'),
      task(2, '2026-09-10'),
      task(3, '2026-09-20'),
      task(4, '2026-09-21'),
      task(5, null)
    ]

    const filtered = filterWorkTasks(tasks, [], [], '', {
      from: '2026-09-10',
      to: '2026-09-20'
    })

    assert.deepEqual(filtered.map(item => item.id), [2, 3])
  })
})

describe('Work task scope', () => {
  it('keeps scoped undated tasks in their competence and separates unscoped undated tasks', () => {
    const scopedTask = task(3, null)
    const groups: WorkGroupedClient[] = [{
      client: { id: 1, name: 'Alpha' },
      totals: { processes: 1, tasks: 1 },
      processes: [{
        process: { id: 10, name: 'Rotina', status: 'open', due_on: null, reference_month: '2026-09', template: null },
        totals: { tasks: 1, done: 0, dismissed: 0, open: 1 },
        progress: { total: 1, done: 0, dismissed: 0, open: 1, ratio: 0 },
        ratio: 0,
        tasks: [scopedTask]
      }]
    }]
    const unscoped = [task(1, '2026-09-20'), task(2, null)]

    assert.deepEqual(tasksForWorkScope(groups, unscoped, 'month').map(item => item.id), [3, 1])
    assert.deepEqual(tasksForWorkScope(groups, unscoped, 'undated').map(item => item.id), [2])
  })
})

describe('Work process filters', () => {
  const leaves = [
    {
      id: '1',
      processKey: 'template-11',
      processId: 101,
      processName: 'Folha',
      processStatus: 'open',
      processDueOn: '2026-09-20',
      templateId: 11,
      templateName: 'Folha mensal',
      clientId: 1,
      clientName: 'Alpha',
      cascade: true,
      title: 'Conferir folha',
      status: 'todo' as const,
      department_id: 9,
      departmentName: 'Pessoal',
      due_on: '2026-09-10',
      empty: false,
      taskId: 1
    },
    {
      id: '2',
      processKey: 'template-22',
      processId: 202,
      processName: 'Fiscal',
      processStatus: 'done',
      processDueOn: '2026-09-25',
      templateId: 22,
      templateName: 'Fiscal mensal',
      clientId: 2,
      clientName: 'Beta',
      cascade: false,
      title: 'Transmitir obrigação',
      status: 'done' as const,
      department_id: 7,
      departmentName: 'Fiscal',
      due_on: '2026-09-18',
      empty: false,
      taskId: 2
    }
  ]

  it('exposes template and process-status facets from grouped process metadata', () => {
    const columns = workProcessosFilterColumns(leaves, [], [{ id: 7, name: 'Fiscal' }, { id: 9, name: 'Pessoal' }])

    assert.deepEqual(
      columns.find(column => column.id === 'template')?.options?.map(option => option.value),
      ['22', '11']
    )
    assert.deepEqual(
      columns.find(column => column.id === 'processStatus')?.options?.map(option => option.value),
      ['open', 'done']
    )
  })

  it('filters process rows by template and process status independently of task status', () => {
    const filters: DataTableFilterModel[] = [
      { columnId: 'template', type: 'option', operator: 'is', values: ['11'] },
      { columnId: 'processStatus', type: 'option', operator: 'is', values: ['open'] }
    ]

    assert.deepEqual(
      filterWorkProcessosLeaves(leaves, filters, '').map(leaf => leaf.id),
      ['1']
    )
  })
})

describe('Work client filters', () => {
  const leaves = [
    {
      id: '1',
      clientId: 1,
      clientName: 'Alpha',
      processId: 101,
      processName: 'Folha',
      processRatio: 0.5,
      cascade: true,
      order: 1,
      title: 'Conferir folha',
      status: 'todo' as const,
      department_id: 9,
      departmentName: 'Pessoal',
      due_on: '2026-09-10',
      empty: false,
      taskId: 1
    },
    {
      id: '2',
      clientId: 2,
      clientName: 'Beta',
      processId: 202,
      processName: 'Fiscal',
      processRatio: 0,
      cascade: false,
      order: 1,
      title: 'Transmitir obrigação',
      status: 'done' as const,
      department_id: 7,
      departmentName: 'Fiscal',
      due_on: '2026-09-18',
      empty: false,
      taskId: 2
    }
  ]

  it('offers the four shared Work facets in bar order', () => {
    assert.deepEqual(
      workClientesFilterColumns(leaves, [], [{ id: 7, name: 'Fiscal' }, { id: 9, name: 'Pessoal' }]).map(column => column.id),
      ['status', 'department', 'cascade', 'client']
    )
  })

  it('keeps a fixed facet an operator already picked even when no leaf holds it', () => {
    const filters: DataTableFilterModel[] = [
      { columnId: 'status', type: 'option', operator: 'is', values: ['dismissed'] }
    ]

    assert.deepEqual(
      workClientesFilterColumns(leaves, filters).find(column => column.id === 'status')?.options?.map(option => option.value),
      ['todo', 'doing', 'done', 'dismissed']
    )
  })

  it('keeps a client filter column while leaves still carry a name to narrow', () => {
    const filters: DataTableFilterModel[] = [
      { columnId: 'client', type: 'option', operator: 'is', values: ['Gamma'] }
    ]

    assert.deepEqual(
      workClientesFilterColumns(leaves, filters).find(column => column.id === 'client')?.options?.map(option => option.value),
      ['Alpha', 'Beta']
    )
    assert.deepEqual(filterWorkClientesLeaves(leaves, filters, ''), [])
  })

  it('narrows cascade and status to the values the leaves actually hold', () => {
    const columns = workClientesFilterColumns(leaves, [], [{ id: 7, name: 'Fiscal' }, { id: 9, name: 'Pessoal' }])

    assert.deepEqual(
      columns.find(column => column.id === 'cascade')?.options?.map(option => option.value),
      ['true', 'false']
    )
    assert.deepEqual(
      columns.find(column => column.id === 'status')?.options?.map(option => option.label),
      ['A fazer', 'Concluída']
    )
  })

  it('searches across cliente, processo, tarefa and departamento', () => {
    assert.deepEqual(filterWorkClientesLeaves(leaves, [], 'beta').map(leaf => leaf.id), ['2'])
    assert.deepEqual(filterWorkClientesLeaves(leaves, [], 'folha').map(leaf => leaf.id), ['1'])
    assert.deepEqual(filterWorkClientesLeaves(leaves, [], 'fiscal').map(leaf => leaf.id), ['2'])
  })

  it('combines a facet filter with the search term', () => {
    const filters: DataTableFilterModel[] = [
      { columnId: 'department', type: 'option', operator: 'is', values: ['9'] }
    ]

    assert.deepEqual(filterWorkClientesLeaves(leaves, filters, '').map(leaf => leaf.id), ['1'])
    assert.deepEqual(filterWorkClientesLeaves(leaves, filters, 'beta'), [])
  })
})
