// tests/monitoringTermGuidance.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  serproCertificateAsk,
  serproCertificateMissingNotice,
  serproCertificateRemoval,
  serproCertificateRemovalText,
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
 * quando alguém reescreve a frase.
 *
 * **As três families, e o que cada uma deixa passar.** A lista cobre as formas
 * verbais do verbo (`assine`, `assinar`, `reassine`, `reassinar`, `assando`,
 * `assinaram`, `assinei`), as duas maneiras de dizer "sem a plataforma" ("à
 * mão", "manualmente") e o substantivo **quando ele vem como pedido** — "precisa
 * de assinatura digital do escritório" é o mesmo defeito dito com substantivo.
 * O substantivo sozinho não é defeito: "Assinatura digital: feita pela
 * plataforma" é a verdade do produto, e o último caso deste arquivo prova que
 * ela passa.
 *
 * **O que ele não pega é "a plataforma assina", e essa exclusão é o motivo de a
 * lista ser de formas verbais e não de `assin\w+`.** A assinatura acontece — pelo
 * backend, com o e-CNPJ que o escritório entregou — e uma frase honesta pode
 * dizer isso; o defeito é pedir a alguém, não nomear o ato.
 */
const pedidoDeAssinatura = new RegExp([
  /\b(?:re)?assin(?:e|ei|i|ar|ou|ando|aram|amento|ou-se)\b/.source,
  '|à mão',
  '|manualmente',
  '|',
  /(?:\b(?:precisa|precisam|precisar|exige|exigem|pede|pedem|requer|requerem|necessita|necessitam)\s+(?:d[eoa]\s+)?(?:uma\s+)?assinatura)/i.source
].join(''), 'i')

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

    const ask = serproCertificateAsk('ausente', SEM_CERTIFICADO)
    assert.ok(ask, 'o estado ausente sem certificado tem de ter o que pedir')
    assert.match(ask.title, /certificado/i)
    assert.doesNotMatch(`${ask.title} ${ask.description} ${ask.label}`, pedidoDeAssinatura)
  })

  it('o texto do termo ausente sem certificado não diz que nada está sendo pedido', () => {
    // O oposto do caso de baixo: aqui a conta **não** entregou nada e a
    // plataforma está esperando o e-CNPJ. Dizer "nada está sendo pedido" aqui
    // seria a frase de quem já entregado aplicada a quem não entregou.
    assert.doesNotMatch(serproTermGuidance('ausente', SEM_CERTIFICADO), /nada está sendo pedido|não há nada a fazer/i)
    assert.match(serproTermGuidance('ausente', SEM_CERTIFICADO), /plataforma/i)
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

  it('o texto do termo ausente com certificado diz quem tem o termo e que nada é pedido', () => {
    // **Este é o estado em que todo escritório real está hoje**, porque o gate de
    // emissão está fechado: o e-CNPJ foi entregue, o termo não existe, e a única
    // ação possível é nenhuma. O texto precisa dizer as duas coisas — o
    // certificado está com a plataforma e nada está sendo pedido — porque um
    // badge de aviso sozinho lê como defeito do escritório.
    const guidance = serproTermGuidance('ausente', COM_CERTIFICADO)

    assert.match(guidance, /certificado/i)
    assert.match(guidance, /plataforma/i)
    assert.match(guidance, /nada está sendo pedido|não há nada a fazer/i)
  })

  it('o texto do termo pendente sem certificado não afirma que o e-CNPJ foi entregue', () => {
    // **A remoção produz este par**: o botão de remoção aparece sempre que há
    // certificado, sem filtro de estado, e `serproTermRequest('pendente', false)`
    // é `'nenhuma'` — então "pendente sem certificado" é um estado alcançável e
    // tratado. Dizer que o e-CNPJ "já foi entregue" nesse estado é afirmar o
    // contrário do que a tela mostra logo acima, onde não há certificado.
    const guidance = serproTermGuidance('pendente', SEM_CERTIFICADO)

    assert.doesNotMatch(guidance, /e-CNPJ/i)
    assert.doesNotMatch(guidance, /já foi entregue/i)
    assert.doesNotMatch(guidance, /já está com a plataforma/i)
    assert.match(guidance, /não há nada a fazer/i)
  })

  it('o texto do termo pendente com certificado diz que ele está com a plataforma', () => {
    assert.match(serproTermGuidance('pendente', COM_CERTIFICADO), /já está com a plataforma/i)
  })

  it('um termo pendente é processo em curso, e não erro nem pedido', () => {
    for (const temCertificado of [COM_CERTIFICADO, SEM_CERTIFICADO]) {
      assert.equal(serproTermRequest('pendente', temCertificado), 'nenhuma')
      assert.equal(serproCertificateAsk('pendente', temCertificado), null)
    }

    // A cor é a mesma afirmação que o texto: um estado que não pede nada não
    // aparece em vermelho na tela de um escritório cujo termo está aguardando o
    // provedor. E o rótulo do badge não afirma que alguém está validando o
    // termo: `pendente` também é o estado em que o envio falhou e ninguém nunca
    // respondeu, e "Em validação" descreve um trabalho que não está acontecendo.
    assert.equal(serproTermStatePresentation.pendente.color, 'info')
    assert.doesNotMatch(serproTermStatePresentation.pendente.label, /valida/i)
    assert.doesNotMatch(serproTermGuidance('pendente', COM_CERTIFICADO), pedidoDeAssinatura)
    assert.doesNotMatch(serproTermGuidance('pendente', SEM_CERTIFICADO), pedidoDeAssinatura)
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
    for (const temCertificado of [SEM_CERTIFICADO, COM_CERTIFICADO]) {
      assert.match(serproTermGuidance('vencido', temCertificado), /escritório/i)
      assert.doesNotMatch(serproTermGuidance('vencido', temCertificado), /nada a fazer|sem nenhuma ação/i)
    }

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
    assert.match(serproTermGuidance('recusado', COM_CERTIFICADO), /escritório/i)

    const ask = serproCertificateAsk('recusado', COM_CERTIFICADO)
    assert.ok(ask, 'o termo recusado tem de dizer o que o escritório entrega')
    assert.doesNotMatch(`${ask.title} ${ask.description} ${ask.label}`, pedidoDeAssinatura)
  })

  it('termo válido não pede nova assinatura ao Account', () => {
    // A frase do plano, e o padrão largo logo abaixo: o `assert` do roteiro casa
    // com um defeito específico, e a linha seguinte cobre os outros.
    assert.doesNotMatch(serproTermGuidance('autenticado', COM_CERTIFICADO), /assine novamente/i)

    for (const estado of ['pendente', 'validado', 'autenticado'] as const) {
      assert.equal(serproTermRequest(estado, COM_CERTIFICADO), 'nenhuma')
      assert.equal(serproTermRequest(estado, SEM_CERTIFICADO), 'nenhuma')
      assert.equal(serproCertificateAsk(estado, COM_CERTIFICADO), null)
      for (const temCertificado of [COM_CERTIFICADO, SEM_CERTIFICADO]) {
        assert.doesNotMatch(serproTermGuidance(estado, temCertificado), pedidoDeAssinatura)
      }
    }
  })

  it('o termo validado é distinguido do autenticado pelo token, e não só pela cor', () => {
    // **A spec separa os dois estados, e a distinção é o token.** `validado` é o
    // provedor ter aceitado o documento sem devolver token que valha — sem token,
    // ou sem a validade dele — e `authorizesGateway()` só aceita `validado` e
    // `autenticado` com token em uso. As duas palavras eram idênticas na tela, e
    // a diferença que importa (a integração fala ou não com o provedor) não
    // aparecia em lugar nenhum.
    const validado = serproTermGuidance('validado', COM_CERTIFICADO)
    const autenticado = serproTermGuidance('autenticado', COM_CERTIFICADO)

    assert.notEqual(validado, autenticado)
    assert.match(validado, /token/i)
    assert.match(validado, /plataforma/i)
    assert.match(autenticado, /autoriz/i)
  })

  it('um termo validado ou autenticado não pede certificado, e diz quem renova', () => {
    // A tela que diz "nada a fazer" precisa dizer de quem é a renovação, ou o
    // escritório não sabe se pode esquecer do assunto.
    for (const estado of ['validado', 'autenticado'] as const) {
      assert.match(serproTermGuidance(estado, COM_CERTIFICADO), /plataforma/i)
      assert.doesNotMatch(serproTermGuidance(estado, COM_CERTIFICADO), /certificado/i)
    }
  })

  it('todo estado tem guidance nos dois pares, e nenhum deles discorda do pedido', () => {
    for (const estado of ESTADOS) {
      for (const temCertificado of [SEM_CERTIFICADO, COM_CERTIFICADO]) {
        const guidance = serproTermGuidance(estado, temCertificado)
        assert.ok(guidance.length > 0, `${estado}/${temCertificado} não tem guidance`)

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

        // O texto do certificado gravado só entra no texto do termo quando o
        // certificado está gravado, e nunca o contrário.
        if (temCertificado && guidance.includes('já está com a plataforma')) {
          assert.notEqual(estado, 'validado')
          assert.notEqual(estado, 'autenticado')
        }
      }
    }
  })
})

describe('o cartão do certificado sem e-CNPJ guardado', () => {
  it('a nota some quando há certificado guardado', () => {
    // A função devolve `null` em vez de um texto: com o e-CNPJ gravado não há
    // "certificado que falta" para dizer, e uma nota de ausência aqui mostraria
    // ao escritório uma falta que não existe.
    for (const estado of ESTADOS) {
      assert.equal(serproCertificateMissingNotice(estado, COM_CERTIFICADO), null, `${estado} com certificado`)
    }
  })

  it('a nota é do pedido quando o escritório tem algo a entregar', () => {
    // `ausente`, `vencido` e `recusado` sem certificado são pedido de entrega, e
    // quem fala nesses três é o `ask` — com o texto que diz o que entregar e o
    // que a plataforma faz depois. A nota de "certificates removido" não entra
    // aí, e o seu texto sobre termo assinado seria falso em `vencido`.
    for (const estado of ['ausente', 'vencido', 'recusado'] as const) {
      assert.equal(serproCertificateMissingNotice(estado, SEM_CERTIFICADO), null, estado)
    }
  })

  it('a consequência de não ter certificado mora no texto do pedido, não numa nota morta', () => {
    // Uma conta sem certificado e sem termo é o caso do `ask`, e é o único texto
    // que ela lê. A consequência — sem termo não há conversa com o provedor —
    // precisa estar **nesse** texto: a nota de "certificado removido" devolveria
    // `null` aqui, e o que se perderia é a informação de que a integração está
    // parada, não a de que falta o certificado.
    const ask = serproCertificateAsk('ausente', SEM_CERTIFICADO)

    assert.ok(ask)
    assert.match(ask.description, /não fala com o provedor/i)
    assert.doesNotMatch(`${ask.title} ${ask.description} ${ask.label}`, pedidoDeAssinatura)
  })

  it('com termo pendente e certificado removido, o texto não diz que o termo continua valendo', () => {
    // **O que é falso aqui é "continua valendo", não "está assinado".** Um termo
    // `pendente` tem o documento assinado e gravado — `SerproTermManager::guardar()`
    // grava o XML e o estado `Pendente` na mesma escrita, e é por isso que
    // `document_present` é verdadeiro — mas `authorizesGateway()` só aceita
    // `validado` e `autenticado`: ele não autoriza nenhuma chamada. A nota dizia
    // que ele continuava valendo, e é isso que a tela não pode afirmar.
    const notice = serproCertificateMissingNotice('pendente', SEM_CERTIFICADO)

    assert.doesNotMatch(notice.description, /continua valendo/i)
    assert.doesNotMatch(notice.description, /não fala com o provedor|não tem com o que/i)
    assert.match(notice.description, /ainda não respondeu|aguardando/i)
    assert.match(notice.description, /termo novo/i)
    assert.doesNotMatch(`${notice.title} ${notice.description}`, pedidoDeAssinatura)
  })

  it('com termo validado e certificado removido, o texto diz que ele continua valendo', () => {
    // O contrário do caso de cima, e a diferença entre os dois textos é o que
    // prova que nenhum deles é uma frase única jogada nos dois lugares.
    const notice = serproCertificateMissingNotice('validado', SEM_CERTIFICADO)

    assert.match(notice.description, /continua valendo/i)
    assert.doesNotMatch(notice.description, /não fala com o provedor|não tem com o que/i)
    assert.doesNotMatch(`${notice.title} ${notice.description}`, pedidoDeAssinatura)
  })

  it('as notas de pendente e de validado não são a mesma frase', () => {
    const pendente = serproCertificateMissingNotice('pendente', SEM_CERTIFICADO)
    const validado = serproCertificateMissingNotice('validado', SEM_CERTIFICADO)

    assert.notEqual(pendente.description, validado.description)
  })

  it('sem certificado guardado, o botão nunca promete substituir', () => {
    // O formulário existe para quem pode escrever mesmo sem pedido, e é a nota
    // que diz o que o botão diz: nesta conta não há certificado nenhum, e
    // "substituir" seria uma afirmação falsa sobre o que está gravado.
    for (const estado of ESTADOS) {
      const notice = serproCertificateMissingNotice(estado, SEM_CERTIFICADO)
      if (!notice) continue
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
    const texto = serproCertificateRemovalText('validado', COM_CERTIFICADO)

    assert.match(texto, /não revoga o termo/i)
    assert.doesNotMatch(texto, /integração para|interrompe|derruba/i)
    assert.doesNotMatch(`${serproCertificateRemoval.title} ${texto}`, pedidoDeAssinatura)
  })

  it('a confirmação da remoção sem termo assinado não fala de documento assinado', () => {
    // **O botão de remover aparece sempre que há certificado, sem filtro de
    // estado** — e o gate de emissão fechado significa que "há certificado e não
    // há termo" é o estado mais comum que existe. Dizer ali que "o documento já
    // assinado continua gravado" descreve um documento que ninguém tem.
    const texto = serproCertificateRemovalText('ausente', COM_CERTIFICADO)

    assert.doesNotMatch(texto, /não revoga o termo/i)
    assert.doesNotMatch(texto, /documento já assinado|continua sendo enviado/i)
    assert.match(texto, /ainda não há termo/i)
    assert.doesNotMatch(texto, pedidoDeAssinatura)
  })

  it('a confirmação da remoção sem certificado nenhum não promete apagar nada', () => {
    // O par completo: sem certificado, `AccountCertificateVault::remove()` não
    // encontra linha e devolve `204` sem apagar nada. Dizer que o conteúdo foi
    // apagado seria afirmar sobre um certificado que não existe.
    const texto = serproCertificateRemovalText('ausente', SEM_CERTIFICADO)

    assert.doesNotMatch(texto, /é apagado/i)
    assert.match(texto, /nada a remover|não há o que remover/i)
  })

  it('a confirmação da remoção de um termo vencido não diz que a plataforma o continua enviando', () => {
    // **O predicado honesto é "o `refresh()` ainda reenvia", não "o documento
    // existe".** `SerproTermManager::refresh()` devolve antes de `enviar()`
    // quando a vigência do documento acabou, e o documento continua gravado: eram
    // duas frases, uma para "existe" e outra para "é reenviado", e o ramo
    // escolhia pela primeira. O botão de remover não tem filtro de estado, então
    // este par é alcançável pela própria tela.
    const texto = serproCertificateRemovalText('vencido', COM_CERTIFICADO)

    assert.doesNotMatch(texto, /continua sendo enviado pela plataforma/i)
    assert.match(texto, /não revoga o termo/i)
    assert.match(texto, /não o está reenviando|não está reenviando/i)
  })

  it('a confirmação da remoção de um termo recusado não diz que a plataforma o continua enviando', () => {
    // O mesmo ramo, o outro estado em que `refresh()` não reenvia: recusado
    // devolve antes de `enviar()` porque os mesmos bytes drawingiam a mesma
    // recusa.
    const texto = serproCertificateRemovalText('recusado', COM_CERTIFICADO)

    assert.doesNotMatch(texto, /continua sendo enviado pela plataforma/i)
    assert.match(texto, /não revoga o termo/i)
    assert.match(texto, /não o está reenviando|não está reenviando/i)
  })

  it('a confirmação só promete reenvio nos três estados em que refresh() reenvia', () => {
    // O oráculo está escrito aqui, à mão, e não lido da implementação: quem
    // manda é `SerproTermManager::refresh()`, que reenvia o documento guardado em
    // `pendente`, `validado` e `autenticado` e devolve antes em `vencido` e
    // `recusado`.
    const reenvia = new Set<SerproAuthorizationTermState>(['pendente', 'validado', 'autenticado'])

    for (const estado of ESTADOS) {
      const texto = serproCertificateRemovalText(estado, COM_CERTIFICADO)

      if (reenvia.has(estado)) {
        assert.match(texto, /continua sendo enviado pela plataforma/i, `${estado} é reenviado`)
      } else {
        assert.doesNotMatch(texto, /continua sendo enviado pela plataforma/i, `${estado} não é reenviado`)
        assert.match(texto, /Ainda não há termo|não o está reenviando/i, `${estado} precisa negar o reenvio`)
      }
    }
  })

  it('a nota de certificado removido só afirma vigor onde o termo está em vigor', () => {
    // Mesmo padrão, na outra função: o ramo precisa ser escolhido pelo fato que a
    // frase afirma. "Continua valendo" é `authorizesGateway()`, que aceita só
    // `validado` e `autenticado` — um `pendente` tem documento e não está em
    // vigor, e é o que a frase da nota precisa dizer.
    const emVigor = new Set<SerproAuthorizationTermState>(['validado', 'autenticado'])

    for (const estado of ESTADOS) {
      const nota = serproCertificateMissingNotice(estado, SEM_CERTIFICADO)
      const afirmaVigor = nota?.description.includes('continua valendo') ?? false

      assert.equal(afirmaVigor, emVigor.has(estado), `${estado}: nota afirma vigor em ${afirmaVigor}`)
    }
  })
})

describe('o guarda de "ninguém assina"', () => {
  it('o padrão pega a reformulação, e não só a frase do roteiro', () => {
    // Sem esta prova, os `doesNotMatch` acima só diriam que a string não contém
    // algo que ninguém escreveria: um guarda com um padrão que não casa com nada
    // é o mesmo que não ter guarda.
    assert.match('Assine o termo novamente', pedidoDeAssinatura)
    assert.match('Assine o termo de novo', pedidoDeAssinatura)
    assert.match('Assinei o termo de novo', pedidoDeAssinatura)
    assert.match('É preciso reassinar o documento', pedidoDeAssinatura)
    assert.match('O escritório precisa assinar o termo', pedidoDeAssinatura)
    assert.match('A assinatura tem de ser feita manualmente', pedidoDeAssinatura)
    assert.match('O termo precisa ser assinado à mão', pedidoDeAssinatura)
    assert.match('Reenvie o e-CNPJ para a plataforma assinar de novo', pedidoDeAssinatura)
    // O substantivo como pedido: a mesma frase, dita com substantivo.
    assert.match('O escritório precisa de assinatura digital do escritório', pedidoDeAssinatura)
    assert.match('Esta tela exige uma assinatura digital do escritório', pedidoDeAssinatura)
  })

  it('o padrão deixa passar a assinatura que é da plataforma', () => {
    // A assinatura acontece — com o e-CNPJ do escritório, pelo backend — e uma
    // frase honesta pode dizer isso. O defeito é pedir a alguém, e é por isso que
    // o substantivo só entra no padrão acompanhado de um verbo de exigência.
    assert.doesNotMatch('A plataforma assina e renova o termo sozinha', pedidoDeAssinatura)
    assert.doesNotMatch('O e-CNPJ do escritório assina o termo de autorização', pedidoDeAssinatura)
    assert.doesNotMatch('Assinatura digital: feita pela plataforma, com o e-CNPJ do escritório', pedidoDeAssinatura)
  })
})
