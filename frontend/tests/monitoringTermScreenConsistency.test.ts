// tests/monitoringTermScreenConsistency.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { serproTermScreen, serproTermRequest } from '../app/utils/monitoringPresentation.ts'
import type { SerproAuthorizationTermState } from '../app/types/serpro.ts'

/**
 * A varredura **mecânica** das frases da tela do termo.
 *
 * Três rondas seguidas de revisão acharam, cada uma, só o que o leitor estava
 * olhando. A Premissa continua valendo — *a frase de um ramo tem que afirmar o que
 * o predicado daquele ramo prova* —, mas conferi-la com o olho é o que falhou. A
 * forma mecânica é esta: para cada um dos seis estados e para cada par de
 * certificado, **junta todas as frases que a tela renderiza** e afirma que nenhuma
 * delas contradiz as outras. Um oráculo escrito à mão com o conjunto de estados
 * e as backend facts é o instrumento; o código de produção não é lido para saber a
 * resposta, e é por isso que ele acha o que ninguém foi procurar.
 *
 * **As três instâncias que a varredura anterior perdeu estão aqui:**
 * - `A` — a confirmação de remoção dizia que nenhuma das duas se resolvia com o
 *   certificado, ao lado do pedido que manda exatamente entregar um;
 * - `B` — o texto de `vencido` atribuía a emissão ao escritório, ao lado do texto
 *   de `recusado` que a atribuía à plataforma;
 * - `C` — o cabeçalho da tela prometia renovação em todos os seis estados,
 *   inclusive nos em que `refresh()` nem reenvia.
 *
 * **O limite declarado do instrumento, e por quê.** A atribuição de montagem,
 * assinatura e envio é verificada por *quem*: essas frases dizem quem age, e a
 * resposta é a mesma nos seis estados. A renovação é a única que é comportamento
 * por estado — `refresh()` reenvia o documento em `pendente`, `validado` e
 * `autenticado` e devolve antes em `vencido` e `recusado`
 * (`backend/app/Services/SerproTermManager.php:216-255`) — e é a única que muda de
 * verdade entre um estado e outro. As demais claims por estado são verificadas
 * pelos testes de cada função, e este arquivo não tenta substituí-los.
 */

const ESTADOS: SerproAuthorizationTermState[] = ['ausente', 'pendente', 'validado', 'autenticado', 'vencido', 'recusado']
const PARES = [false, true]

/** Estados em que a plataforma ainda reenvia o documento: o oráculo, escrito aqui. */
const REENVIA = new Set<SerproAuthorizationTermState>(['pendente', 'validado', 'autenticado'])
/** Estados em que o termo autoriza chamada: `authorizesGateway()`. */
const EM_VIGOR = new Set<SerproAuthorizationTermState>(['validado', 'autenticado'])

/** Uma atribuição de ato do termo ao escritório, com o escritório como sujeito ativo. */
const ESCRITORIO_AGE = /\b(?:o |do |da )escritório\s+(?:assina\w*|monta\w*|emite\w*|envia\w*|renova\w*|reenvia\w*|precisa\s+(?:assinar|montar|emitir|enviar|renovar|reenviar)|deve\s+(?:assinar|montar|emitir|enviar|renovar|reenviar))\b/i
/** A mesma atribuição com o verbo na frente: "emitir outro é do escritório". */
const ATO_ANTES_DO_ESCRITORIO = /\b(?:assina\w*|monta\w*|emite\w*|emitir|envia\w*|renova\w*|reenvia\w*|assinatura|emissão|renovação)\b[^.?!]{0,24}\b(?:é|do|da|pelo|pela)\s+(?:do\s+|da\s+)?escritório/i
/**
 * Renewal as a **behaviour**, and only that.
 *
 * The two grammars are different claims and the instrument has to tell them
 * apart: "Quem monta, assina e renova o termo é a plataforma" attributes an act to
 * an actor and is true in all six states, while "o documento continua sendo
 * enviado" asserts what the platform is doing, and that is per state. A pattern
 * that matched the bare verb `renova` would fire on the first and drown the
 * second, which is why the header gets its own gate test below.
 */
const RENOVA_COMO_COMPORTAMENTO = /continua sendo enviado|sendo renovado|é renovada|é renovado|reenvia o documento|continua sendo renovada/i
/** A denial whose object is the certificate — the remedy this screen offers. */
const NEGA_REMEDIO = /\b(?:nada|ninguém|nenhuma|nenhum)\b[^.?!]{0,48}\bse resolve\b[^.?!]{0,32}\bcom (?:o |um )?certificado\b/i
/** "Nothing is being asked of you". */
const NADA_PEDIDO = /nada está sendo pedido|não há nada a fazer|sem nenhuma ação do escritório/i

type Frase = { onde: string, texto: string }

function frasesDaTela(state: SerproAuthorizationTermState, hasCertificate: boolean): Frase[] {
  const tela = serproTermScreen(state, hasCertificate)
  const frases: Frase[] = [
    { onde: 'cabeçalho', texto: tela.header },
    { onde: 'cartão do certificado', texto: tela.certificateHeader },
    { onde: 'fato assinatura', texto: tela.signature },
    { onde: 'texto do estado', texto: tela.state },
    // As duas ajudas do formulário entram porque fazem afirmação sobre a API — a
    // primeira diz que a decisão é por extensão, e a segunda diz que a senha não
    // volta. Nenhuma delas varia com o estado, e é por isso que a conferência
    // delas é sobre a verdade, não sobre o estado.
    { onde: 'ajuda do arquivo', texto: tela.form.fileHelp },
    { onde: 'ajuda da senha', texto: tela.form.passwordHelp }
  ]

  if (tela.action) frases.push({ onde: 'alerta de ação', texto: tela.action.description })
  if (tela.notice) frases.push({ onde: 'aviso do certificado', texto: tela.notice.description })
  if (tela.replacement) frases.push({ onde: 'substituição', texto: `${tela.replacement.title} ${tela.replacement.description}` })
  if (tela.removal) frases.push({ onde: 'remoção', texto: tela.removal.description })
  if (tela.readOnly) frases.push({ onde: 'somente leitura', texto: tela.readOnly })

  return frases
}

describe('a tela do termo não se contradiz, estado por estado', () => {
  it('nenhum estado atribui a emissão do termo ao escritório', () => {
    // **A emissão é da plataforma nos seis estados.** O que o escritório entrega é o
    // e-CNPJ; o que a plataforma faz com ele é montar, assinar e enviar. A
    // verificação vai pelos dois lados do período, porque "emitir outro é do
    // escritório" e "a emissão é da plataforma" estão na mesma tela em estados
    // vizinhos, e é a diferença entre eles que o texto tem de respectar.
    for (const state of ESTADOS) {
      for (const hasCertificate of PARES) {
        for (const { onde, texto } of frasesDaTela(state, hasCertificate)) {
          assert.doesNotMatch(texto, ESCRITORIO_AGE, `${state}/${hasCertificate}: ${onde} atribui ao escritório`)
          assert.doesNotMatch(texto, ATO_ANTES_DO_ESCRITORIO, `${state}/${hasCertificate}: ${onde} atribui ao escritório`)
        }
      }
    }
  })

  it('o cabeçalho só promete renovação onde refresh() ainda reenvia', () => {
    // A frase do cabeçalho é uma lista de atos sem qualificação — "monta, assina,
    // envia e renova" —, e por isso é a única que precisa do portão inteiro: a
    // segunda frase só entra quando `serproTermReenviado` deixa. Renderizada nos
    // seis estados, ela dizia a um escritório com termo vencido que a plataforma
    // estava renovando um termo que não reenvia.
    for (const state of ESTADOS) {
      for (const hasCertificate of PARES) {
        const { header } = serproTermScreen(state, hasCertificate)

        if (REENVIA.has(state)) {
          assert.match(header, /renova/i, `${state}: o cabeçalho devia dizer quem renova`)
        } else {
          assert.doesNotMatch(header, /renova/i, `${state}: o cabeçalho promete renovação`)
        }
      }
    }
  })

  it('nenhuma frase afirma renovação como comportamento onde refresh() não reenvia', () => {
    for (const state of ESTADOS) {
      if (REENVIA.has(state)) continue

      for (const hasCertificate of PARES) {
        for (const { onde, texto } of frasesDaTela(state, hasCertificate)) {
          assert.doesNotMatch(texto, RENOVA_COMO_COMPORTAMENTO, `${state}/${hasCertificate}: ${onde} afirma renovação`)
        }
      }
    }
  })

  it('só diz que o termo continua valendo onde ele está em vigor', () => {
    for (const state of ESTADOS) {
      if (EM_VIGOR.has(state)) continue

      for (const hasCertificate of PARES) {
        for (const { onde, texto } of frasesDaTela(state, hasCertificate)) {
          assert.doesNotMatch(texto, /continua valendo/i, `${state}/${hasCertificate}: ${onde}`)
        }
      }
    }
  })

  it('nenhuma tela nega um remédio que outra frase da mesma tela oferece', () => {
    // A regra é o par: **se o escritório está sendo pedido a entregar um
    // certificado, nenhuma frase da tela pode dizer que entregar certificado não
    // resolve**. Uma negação de remédio é vraie no seu contexto e falsa ao lado do
    // pedido, e é por isso que ela só se prova no conjunto.
    for (const state of ESTADOS) {
      for (const hasCertificate of PARES) {
        if (serproTermRequest(state, hasCertificate) !== 'certificado') continue

        for (const { onde, texto } of frasesDaTela(state, hasCertificate)) {
          assert.doesNotMatch(texto, NEGA_REMEDIO, `${state}/${hasCertificate}: ${onde} nega o remédio que o pedido oferece`)
        }
      }
    }
  })

  it('pede e dispensa ao mesmo tempo não', () => {
    for (const state of ESTADOS) {
      for (const hasCertificate of PARES) {
        if (serproTermRequest(state, hasCertificate) !== 'certificado') continue

        for (const { onde, texto } of frasesDaTela(state, hasCertificate)) {
          assert.doesNotMatch(texto, NADA_PEDIDO, `${state}/${hasCertificate}: ${onde} dispensa o que o estado pede`)
        }
      }
    }
  })

  it('toda tela tem frases para renderizar, e nenhuma delas é vazia', () => {
    for (const state of ESTADOS) {
      for (const hasCertificate of PARES) {
        const frases = frasesDaTela(state, hasCertificate)

        assert.ok(frases.length >= 4, `${state}/${hasCertificate}: ${frases.length} frases`)
        for (const { onde, texto } of frases) {
          assert.ok(texto.trim().length > 0, `${state}/${hasCertificate}: ${onde} está vazia`)
        }
      }
    }
  })
})
