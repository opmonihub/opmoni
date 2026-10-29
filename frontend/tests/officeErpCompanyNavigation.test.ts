import assert from 'node:assert/strict'
import { it } from 'node:test'
import { officeErpCompanyListQuery } from '../app/utils/officeErpCompanyNavigation.ts'

it('preserves allowlisted company list intent from legacy customer URLs', () => {
  assert.deepEqual(officeErpCompanyListQuery({ q: '  acme  ', pagina: '3', porPagina: '50', cliente: '24', sort: 'registered_at', direction: 'desc', token: 'secret' }), {
    q: 'acme', pagina: '3', porPagina: '50', cliente: '24', sort: 'registered_at', direction: 'desc'
  })
})

it('drops malformed, duplicated, long and unsupported navigation query values', () => {
  assert.deepEqual(officeErpCompanyListQuery({ q: ['a', 'b'], pagina: '-1', porPagina: '200', cliente: '0', sort: 'cnpj', direction: 'sideways', section: 'members' }), {})
})
