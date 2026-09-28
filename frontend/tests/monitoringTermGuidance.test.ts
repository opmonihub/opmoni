// tests/monitoringTermGuidance.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  serproCertificateAsk,
  serproCertificateMissingNotice,
  serproCertificateRemoval,
  serproCertificateReplacement,
  serproTermGuidance,
  serproTermRequest,
  serproTermStatePresentation
} from '../app/utils/monitoringPresentation.ts'
import type { SerproAuthorizationTermState } from '../app/types/serpro.ts'

/**
 * Um pedido de assinatura ao escritório, que é o defeito que D3 proíbe e que o
 * cenário "Escritório não assina nada" descreve.
 *
 * **O padrão é largo de propósito.** Um guarda que casasse só com "assine
 * novamente" passaria com "assine o termo de novo", "reassine o documento",
 * "é preciso assinar o termo" e "a assinatura tem de ser manual" — que são o
 * mesmo defeito com outras palavras, e reformular é exatamente o que acontece
 * quando alguém reescreve a frase. O padrão pega as formas verbais (`assine`,
 * `assinar`, `reassine`, `reassinar`, `assando`, `assinaram`) e as duas maneiras
 * de dizer "sem a plataforma" — "à mão" e "manualmente".
 *
 * **O que ele não pega é "a plataforma assina", e essa exclusão é o motivo de a
 * lista ser de formas verbais e não de `assin\w+`.** A assinatura acontece — pelo
 * backend, com o e-CNPJ que o escritório entregou — e uma frase honesta pode
 * dizer isso; o defeito é pedir a alguém, não nomear o ato.
 */
const pedidoDeAssinatura = /\b(?:re)?assin(?:e|ar|ou|ando|aram|amento|ou-se)\b|à mão|manualmente/i

/** O e-CNPJ entregue, que é a segunda leitura da tela e vale para qualquer estado. */
const COM_CERTIFICADO = true
/** E a primeira: a conta ainda não entregou certificado nenhum. */
const SEM_CERTIFICADO = false

const ESTADOS: SerproAuthorizationTermState[] = ['ausente', 'pendente', 'validado', 'autenticado', 'vencido', 'recusado']

describe('o que a tela do termo pede ao escritório', () => {
  it('um termo ausente sem certificado pede o e-CNPJ do escritório', () => {
    // A única coisa que falta para existir termo é o certificado: a emissão é
    // disparada pelo upload, e sem upload não há o que assinar. O pedido é do
    // certificado — nunca do termo, que ninguém da conta assina.
    assert.equal(serproTermRequest('ausente', SEM_CERTIFICADO), 'certificado')
    assert.match(serproTermGuidance.ausente, /certificado/i)

    const ask = serproCertificateAsk('ausente', SEM_CERTIFICADO)
    assert.ok(ask, 'o estado ausente sem certificado tem de ter o que pedir')
    assert.match(ask.title, /certificado/i)
    assert.doesNotMatch(`${ask.title} ${ask.description} ${ask.label}`, pedidoDeAssinatura)
  })

  it('um termo ausente com certificado já entregue não pede nada ao escritório', () => {
    // **A emissão está com o gate fechado**: `issue()` recusa enquanto a prova de
    // contrato não existir, e o job que o upload despacha transforma essa recusa
    // em uma linha de log, sem gravar estado — o `200` do upload não muda por
    // causa dela. O e-CNPJ fica gravado e o termo continua `ausente`, e a única
    // leitura honesta é "o certificado está com a gente, o termo é da
    // plataforma". Pedir o certificado de novo aqui seria a tela inventando um
    // atraso que o escritório não pode resolver.
    assert.equal(serproTermRequest('ausente', COM_CERTIFICADO), 'nenhuma')
    assert.equal(serproCertificateAsk('ausente', COM_CERTIFICADO), null)
  })

  it('um termo pendente é processo em curso, e não erro nem pedido', () => {
    for (const temCertificado of [COM_CERTIFICADO, SEM_CERTIFICADO]) {
      assert.equal(serproTermRequest('pendente', temCertificado), 'nenhuma')
      assert.equal(serproCertificateAsk('pendente', temCertificado), null)
    }

    // A cor é a mesma afirmação que o texto: um estado que não pede nada não
    // aparece em vermelho na tela de um escritório cujo termo está aguardando o
    // provedor.
    assert.equal(serproTermStatePresentation.pendente.color, 'info')
    assert.match(serproTermGuidance.pendente, /não há nada a fazer/i)
    assert.doesNotMatch(serproTermGuidance.pendente, pedidoDeAssinatura)
  })

  it('um termo vencido devolve a ação ao escritório, e o que ele precisa é o certificado', () => {
    // A vigência do documento acabou, e `refresh()` não ressuscita documento
    // vencido — grava `vencido` e devolve. A única alavanca que o produto tem
    // hoje é a reentrega do e-CNPJ, que dispara a emissão de um termo novo. Por
    // isso o pedido **não** desaparece quando já existe certificado guardado: o
    // pedido é a reentrega, e é a reentrega que dispara a emissão.
    assert.equal(serproTermRequest('vencido', SEM_CERTIFICADO), 'certificado')
    assert.equal(serproTermRequest('vencido', COM_CERTIFICADO), 'certificado')

    // De quem é a ação, dito no texto da tela e não só na cor do badge.
    assert.match(serproTermGuidance.vencido, /escritório/i)
    assert.doesNotMatch(serproTermGuidance.vencido, /nada a fazer|sem nenhuma ação/i)

    const ask = serproCertificateAsk('vencido', COM_CERTIFICADO)
    assert.ok(ask, 'o termo vencido tem de dizer o que o escritório entrega')
    assert.doesNotMatch(`${ask.title} ${ask.description} ${ask.label}`, pedidoDeAssinatura)
  })

  it('um termo recusado devolve a ação ao escritório pelo mesmo caminho', () => {
    // `refresh()` não reenvia um termo recusado — o provedor leria os mesmos
    // bytes e recusaria de novo — e o caminho que resolve é a reentrega do
    // e-CNPJ. Dizer "tente de novo" ao escritório seria pedir a única coisa que
    // ele não pode fazer.
    assert.equal(serproTermRequest('recusado', SEM_CERTIFICADO), 'certificado')
    assert.equal(serproTermRequest('recusado', COM_CERTIFICADO), 'certificado')
    assert.match(serproTermGuidance.recusado, /escritório/i)

    const ask = serproCertificateAsk('recusado', COM_CERTIFICADO)
    assert.ok(ask, 'o termo recusado tem de dizer o que o escritório entrega')
    assert.doesNotMatch(`${ask.title} ${ask.description} ${ask.label}`, pedidoDeAssinatura)
  })

  it('termo válido não pede nova assinatura ao Account', () => {
    // A frase do plano, e o padrão largo logo abaixo: o `assert` do roteiro casa
    // com um defeito específico, e a linha seguinte cobre os outros.
    assert.doesNotMatch(serproTermGuidance.autenticado, /assine novamente/i)

    for (const estado of ['pendente', 'validado', 'autenticado'] as const) {
      assert.equal(serproTermRequest(estado, COM_CERTIFICADO), 'nenhuma')
      assert.equal(serproTermRequest(estado, SEM_CERTIFICADO), 'nenhuma')
      assert.equal(serproCertificateAsk(estado, COM_CERTIFICADO), null)
      assert.doesNotMatch(serproTermGuidance[estado], pedidoDeAssinatura)
    }
  })

  it('um termo validado ou autenticado não pede certificado, e diz quem renova', () => {
    // A tela que diz "nada a fazer" precisa dizer de quem é a renovação, ou o
    // escritório não sabe se pode esquecer do assunto.
    for (const estado of ['validado', 'autenticado'] as const) {
      assert.match(serproTermGuidance[estado], /plataforma/i)
      assert.doesNotMatch(serproTermGuidance[estado], /certificado/i)
    }
  })

  it('todo estado tem guidance e o pedido nunca discorda do que ele diz', () => {
    for (const estado of ESTADOS) {
      assert.ok(serproTermGuidance[estado].length > 0, `${estado} não tem guidance`)

      for (const temCertificado of [SEM_CERTIFICADO, COM_CERTIFICADO]) {
        const pedido = serproTermRequest(estado, temCertificado)
        const ask = serproCertificateAsk(estado, temCertificado)

        // O pedido e o texto do pedido andam juntos: um `ask` sem o pedido de
        // certificado — ou o contrário — é a tela pedindo uma coisa e
        // explicando outra.
        assert.equal(ask !== null, pedido === 'certificado', `${estado}/${temCertificado}`)
        if (ask) {
          assert.ok(ask.title.length > 0 && ask.description.length > 0 && ask.label.length > 0)
          assert.doesNotMatch(`${ask.title} ${ask.description} ${ask.label}`, pedidoDeAssinatura)
        }
      }
    }
  })
})

describe('o cartão do certificado sem e-CNPJ guardado', () => {
  it('sem termo nenhum, o texto é o de quem ainda não entregou nada', () => {
    const notice = serproCertificateMissingNotice('ausente')

    assert.match(notice.title, /certificado/i)
    assert.match(notice.description, /e-CNPJ/i)
    // Ainda não existe termo, e a consequência que é verdadeira aqui é a única
    // que este texto pode afirmar: a integração não chega ao provedor. O outro
    // texto diz o contrário, porque com o termo assinado ela chega.
    assert.match(notice.description, /não fala com o provedor/i)
    assert.doesNotMatch(`${notice.title} ${notice.description}`, pedidoDeAssinatura)
  })

  it('com termo assinado e certificado removido, o texto não chama isso de integração quebrada', () => {
    // **A remoção não revoga o termo.** `refresh()` reenvia o documento
    // guardado e não recebe certificado nenhum, e `validToken()` serve o token
    // enquanto ele valer: o que se perde é a emissão de um termo novo, e nada
    // mais. Um texto que dissesse que a integração parou seria uma afirmação
    // falsa sobre um escritório cujo termo continua valendo — e foi por isso que
    // a confirmação da remoção promete exatamente o que acontece.
    for (const estado of ['pendente', 'validado', 'autenticado'] as const) {
      const notice = serproCertificateMissingNotice(estado)

      assert.match(notice.description, /termo novo/i)
      assert.doesNotMatch(notice.description, /não fala com o provedor|não tem com o que/i)
      assert.doesNotMatch(`${notice.title} ${notice.description}`, pedidoDeAssinatura)
    }
  })

  it('os dois textos são distintos, porque a situação é', () => {
    assert.notEqual(serproCertificateMissingNotice('ausente').description, serproCertificateMissingNotice('validado').description)
  })

  it('sem certificado guardado, o botão nunca promete substituir', () => {
    // O formulário existe para quem pode escrever mesmo sem pedido, e é a nota
    // que diz o que o botão diz: nesta conta não há certificado nenhum, e
    // "substituir" seria uma afirmação falsa sobre o que está gravado.
    for (const estado of ESTADOS) {
      const notice = serproCertificateMissingNotice(estado)
      assert.match(notice.label, /entregar/i)
      assert.doesNotMatch(notice.label, /substituir|trocar/i)
    }
  })
})

describe('a entrega voluntária e a remoção do certificado', () => {
  it('trocar o certificado continua disponível com o termo válido, porque o e-CNPJ expira sozinho', () => {
    // **O e-CNPJ tem validade própria e o termo tem a dele, de trinta dias.** O
    // termo válido não quer dizer certificado válido para sempre: quando o e-CNPJ
    // do escritório expira, `issue()` recusa emitir um termo novo com ele, e a
    // renovação para no dia em que o documento vencer. Uma tela que só oferece a
    // troca quando há algo a pedir deixa o escritório sem caminho para entregar o
    // certificado novo — e o caminho alternativo, remover o antigo, também some,
    // porque a remoção é uma escrita.
    const { title, description, label } = serproCertificateReplacement

    assert.match(title, /certificado/i)
    assert.match(description, /expira/i)
    assert.match(description, /plataforma/i)
    assert.ok(label.length > 0)
    assert.doesNotMatch(`${title} ${description} ${label}`, pedidoDeAssinatura)
  })

  it('a confirmação da remoção promete o que a remoção faz, e não inventa o que ela não faz', () => {
    // A remoção não revoga o termo. `refresh()` reenvia o documento guardado
    // sem certificado nenhum e `validToken()` serve o token enquanto ele valer,
    // então uma confirmação que dissesse "a integração para" faria o escritório
    // temer um corte de serviço que não existe — e faria a tela mentir sobre a
    // posição dele. O que a remoção tira é o conteúdo cifrado e a emissão de um
    // termo novo.
    const { title, description } = serproCertificateRemoval

    assert.match(description, /não revoga o termo/i)
    assert.doesNotMatch(description, /integração para|interrompe|derruba/i)
    assert.doesNotMatch(`${title} ${description}`, pedidoDeAssinatura)
  })
})

describe('o guarda de "ninguém assina"', () => {
  it('o padrão pega a reformulação, e não só a frase do roteiro', () => {
    // Sem esta prova, os `doesNotMatch` acima só diriam que a string não contém
    // algo que ninguém escreveria: um guarda com um padrão que não casa com nada
    // é o mesmo que não ter guarda.
    assert.match('Assine o termo novamente', pedidoDeAssinatura)
    assert.match('Assine o termo de novo', pedidoDeAssinatura)
    assert.match('É preciso reassinar o documento', pedidoDeAssinatura)
    assert.match('O escritório precisa assinar o termo', pedidoDeAssinatura)
    assert.match('A assinatura tem de ser feita manualmente', pedidoDeAssinatura)
    assert.match('O termo precisa ser assinado à mão', pedidoDeAssinatura)
    assert.match('Reenvie o e-CNPJ para a plataforma assinar de novo', pedidoDeAssinatura)
  })

  it('o padrão deixa passar a assinatura que é da plataforma', () => {
    // A assinatura acontece — com o e-CNPJ do escritório, pelo backend — e uma
    // frase honesta pode dizer isso. O defeito é pedir a alguém.
    assert.doesNotMatch('A plataforma assina e renova o termo sozinha', pedidoDeAssinatura)
    assert.doesNotMatch('O e-CNPJ do escritório assina o termo de autorização', pedidoDeAssinatura)
  })
})
