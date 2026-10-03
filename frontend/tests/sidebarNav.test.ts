import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { sidebarOpenGroupFromPath } from '../app/utils/sidebarNav.ts'

describe('sidebarOpenGroupFromPath', () => {
  it('mapeia prefixos de módulo para o value do accordion', () => {
    assert.equal(sidebarOpenGroupFromPath('/customers/painel'), 'clientes')
    assert.equal(sidebarOpenGroupFromPath('/equipe/membros'), 'equipe')
    assert.equal(sidebarOpenGroupFromPath('/monitoring/obrigacoes'), 'monitoramento')
    assert.equal(sidebarOpenGroupFromPath('/fiscal/notas'), 'fiscal')
    assert.equal(sidebarOpenGroupFromPath('/work/calendario'), 'work')
    assert.equal(sidebarOpenGroupFromPath('/settings/security'), 'configuracoes')
    assert.equal(sidebarOpenGroupFromPath('/admin/serpro'), 'admin')
  })

  it('retorna vazio fora dos módulos com submenu', () => {
    assert.equal(sidebarOpenGroupFromPath('/'), '')
    assert.equal(sidebarOpenGroupFromPath('/inbox'), '')
  })
})
