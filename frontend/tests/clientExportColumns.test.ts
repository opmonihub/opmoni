import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import type { ClientExportField } from '../app/utils/clientExportColumns.ts'

const { clientExportFields } = loadAppComposable<{ clientExportFields: (columnIds: readonly string[]) => ClientExportField[] }>('utils/clientExportColumns.ts')

describe('colunas da exportação da carteira', () => {
  it('mantém a ordem da tabela e acrescenta documento e validade das colunas escolhidas', () => {
    const headers = clientExportFields(['status', 'name', 'certificate']).map(field => field.header)

    assert.deepEqual(headers, [
      'Nome/Razão social',
      'CPF/CNPJ',
      'Situação',
      'Cert. A1',
      'Validade do certificado'
    ])
  })

  it('omite a coluna que não foi marcada', () => {
    assert.deepEqual(clientExportFields(['tags']).map(field => field.header), ['Tags'])
    assert.deepEqual(clientExportFields([]), [])
  })
})
