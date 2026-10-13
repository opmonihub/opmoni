import test from 'node:test'
import assert from 'node:assert/strict'
import { apiErrorMessage, apiMessage, apiFieldErrors } from '../app/composables/useApiError.ts'

test('extrai message e mapeia errors.* do 422', () => {
  const err = { data: { message: 'Falhou.', errors: { name: ['obrigatório.'] } } }
  assert.equal(apiMessage(err), 'Falhou.')
  assert.deepEqual(apiFieldErrors(err), { name: 'obrigatório.' })
})

test('a recusa de um 422 é a frase do campo, não a genérica de topo', () => {
  // A resposta de validação do Laravel nomeia a recusa em `errors.<campo>` e
  // repõe `The given data was invalid.` no `message`. Mostrar o `message`
  // entrega ao operador uma frase que não diz o que fazer, e é a única frase
  // que a tela da conexão do Integra Contador exibiria.
  const err = {
    data: {
      message: 'The given data was invalid.',
      errors: {
        consumer_key: [
          'Outra gravação da credencial do Integra Contador chegou primeiro e criou a linha única.'
        ]
      }
    }
  }

  assert.equal(apiErrorMessage(err), 'Outra gravação da credencial do Integra Contador chegou primeiro e criou a linha única.')
})

test('a recusa nomeada do primeiro campo é a que aparece', () => {
  const err = {
    data: {
      message: 'The given data was invalid.',
      errors: { certificate: ['O certificado do contratante está vencido.'], password: ['Informe a senha do certificado do contratante.'] }
    }
  }

  assert.equal(apiErrorMessage(err), 'O certificado do contratante está vencido.')
})

test('sem recusa nomeada, o que a API disse continua sendo a resposta', () => {
  // Falha de infraestrutura não vem em `errors`: o `message` é a única frase que
  // existe, e trocá-lo por `undefined` deixaria o toast sem descrição.
  assert.equal(apiErrorMessage({ data: { message: 'O serviço de autenticação está indisponível.' } }), 'O serviço de autenticação está indisponível.')
  assert.equal(apiErrorMessage({ message: 'Falhou.' }), 'Falhou.')
  // Uma falha sem resposta HTTP nenhuma chega como `Error`, e a própria mensagem
  // dele é a única descrição que existe — o mesmo que `apiMessage` já fazia.
  assert.equal(apiErrorMessage(new Error('rede fora')), 'rede fora')
  assert.equal(apiErrorMessage(undefined), undefined)
})

test('uma lista de recusas vazia não é resposta', () => {
  const err = { data: { message: 'Recusado.', errors: { consumer_key: [] } } }

  assert.deepEqual(apiFieldErrors(err), {})
  assert.equal(apiErrorMessage(err), 'Recusado.')
})
