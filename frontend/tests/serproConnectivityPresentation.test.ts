// tests/serproConnectivityPresentation.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  connectivityIcon,
  connectivityTitle,
  connectivityTone,
  failedElementName,
  isProviderFailure
} from '../app/utils/serproConnectivityPresentation.ts'
import type { SerproConnectivityResult } from '../app/types/serpro.ts'

function result(overrides: Partial<SerproConnectivityResult> = {}): SerproConnectivityResult {
  return {
    ok: false,
    failed_element: 'credencial',
    message: 'A credencial configurada não pôde ser usada.',
    checked_at: '2026-09-28T10:00:00.000000Z',
    ...overrides
  }
}

describe('apresentação da conectividade', () => {
  it('nomeia cada elemento do contrato no idioma do operador', () => {
    assert.equal(failedElementName(result({ failed_element: 'configuracao' })), 'Configuração')
    assert.equal(failedElementName(result({ failed_element: 'certificado' })), 'Certificado')
    assert.equal(failedElementName(result({ failed_element: 'credencial' })), 'Credencial')
    assert.equal(failedElementName(result({ failed_element: 'provedor' })), 'Provedor')
  })

  it('não some um elemento novo e devolve o próprio código', () => {
    // Um quinto valor que o backend não mandou é melhor lido cru do que
    // traduzido errado.
    assert.equal(failedElementName(result({ failed_element: 'algo_novo' })), 'algo_novo')
    assert.equal(failedElementName(result({ failed_element: null })), null)
  })

  it('trata a autenticação bem-sucedida como sucesso, sem elemento', () => {
    const ok = result({ ok: true, failed_element: null, message: null })

    assert.equal(connectivityTitle(ok), 'Conexão autenticada com sucesso')
    assert.equal(connectivityTone(ok), 'success')
    assert.equal(connectivityIcon(ok), 'i-lucide-circle-check')
    assert.equal(isProviderFailure(ok), false)
  })

  it('trata um desfecho da credencial como erro que o operador precisa corrigir', () => {
    assert.equal(connectivityTitle(result()), 'Não foi possível autenticar')
    assert.equal(connectivityTone(result()), 'error')
    assert.equal(connectivityIcon(result()), 'i-lucide-circle-alert')
    assert.equal(isProviderFailure(result()), false)
  })

  it('trata `provedor` como recuperável, e é o que o título de terceiro elemento diz', () => {
    // `provedor` cobre mais de uma causa que o contrato de quatro não separa: o
    // SERPRO fora do ar, a máquina que não conseguiu rodar a verificação e o
    // limite de tentativas. Nem o elemento nem a `message` dizem qual foi — a
    // `message` do backend abre uma disjunção entre serviço e máquina e não a
    // fecha. Um título que dissesse "o provedor não respondeu" mandaria o
    // operador olhar a página de status do SERPRO por causa de um disco cheio
    // aqui.
    const provider = result({ failed_element: 'provedor' })

    assert.equal(isProviderFailure(provider), true)
    assert.equal(connectivityTone(provider), 'warning')
    assert.equal(connectivityIcon(provider), 'i-lucide-cloud-off')
    assert.equal(connectivityTitle(provider), 'A verificação não pôde ser concluída')
    assert.doesNotMatch(connectivityTitle(provider), /provedor/i)
  })

  it('nomeia o desfecho do certificado como problema de certificado, não de autenticação', () => {
    // `certificado` significa que o certificado do contratante está ausente,
    // divergente ou vencido. O conserto é conferir o certificado, e um título que
    // falasse em autenticação mandaria o operador mexer na chave de integração e
    // no segredo — que estão bons, e continuam bons depois da troca.
    const certificado = result({ failed_element: 'certificado' })

    assert.equal(connectivityTitle(certificado), 'Não foi possível autenticar com este certificado')
    assert.notEqual(connectivityTitle(certificado), connectivityTitle(result()))
    assert.equal(connectivityTone(certificado), 'error')
    assert.equal(failedElementName(certificado), 'Certificado')
  })

  it('sem veredito nenhum, nenhum título afirma o que aconteceu', () => {
    // Ainda não rodada: nada rodou, então nada pode ser dito sobre a credencial.
    assert.equal(failedElementName(null), null)
    assert.equal(isProviderFailure(null), false)
    assert.equal(connectivityTone(null), 'error')
  })
})
