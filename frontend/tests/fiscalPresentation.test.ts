// tests/fiscalPresentation.test.ts
//
// O painel fiscal em quatro estados e nove motivos, testado sem Vue e sem
// framework: o módulo importa só tipos com `import type` e a extensão
// explícita, então o runner do Node carrega o arquivo por stripping nativo e
// nada mais é resolvido.
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import type { FiscalAttentionItem, FiscalAttentionReason, FiscalSummary } from '../app/types/fiscal.ts'
import {
  attentionDescription,
  attentionGroups,
  attentionIcon,
  attentionLabel,
  attentionTone,
  blockedRemaining,
  coverageShare,
  coverageState,
  fiscalMonthLabel,
  fiscalNoAttention,
  fiscalMonthSeries,
  fiscalReferenceNow,
  fiscalSourceLabel,
  fiscalStateCopy,
  formatFiscalCount,
  formatFiscalDateTime,
  lastCaptureOutcome,
  modelLabel,
  modelVolumes
} from '../app/utils/fiscalPresentation.ts'

/** Os nove motivos, na precedência que o backend avalia. */
const ALL_REASONS = [
  'certificate_absent',
  'certificate_expired',
  'certificate_password_missing',
  'certificate_reupload',
  'gap_abandoned',
  'history_interrupted',
  'capture_blocked',
  'continuity_warning',
  'capture_failed'
] as const satisfies readonly FiscalAttentionReason[]

function summary(over: Partial<FiscalSummary> = {}): FiscalSummary {
  return {
    coverage: { total: 0, capturable: 0, not_capturable: 0 },
    attention: [],
    documents: { total: 0, models: {}, over_time: [] },
    last_capture: null,
    ...over
  }
}

let nextId = 1

function item(name: string, reason: FiscalAttentionReason, blockedUntil: string | null = null): FiscalAttentionItem {
  return { client_id: nextId++, client_name: name, reason, blocked_until: blockedUntil }
}

describe('estado da cobertura', () => {
  it('distingue carteira vazia de carteira sem certificado', () => {
    // Os dois casos mostram "0" em quase tudo, e significam coisas opostas: um
    // é "cadastre alguém", o outro é "os certificados não estão de pé". Um
    // estado único para os dois é a ambiguidade que o painel existe para não ter.
    assert.equal(coverageState({ coverage: { total: 0, capturable: 0, not_capturable: 0 }, documents: { total: 0 } }), 'no_clients')
    assert.equal(coverageState({ coverage: { total: 2, capturable: 0, not_capturable: 2 }, documents: { total: 0 } }), 'no_capturable')
  })

  it('separa "capturável sem documento" de "com documento"', () => {
    const capturable = { total: 1, capturable: 1, not_capturable: 0 }
    assert.equal(coverageState({ coverage: capturable, documents: { total: 0 } }), 'no_documents')
    assert.equal(coverageState({ coverage: capturable, documents: { total: 7 } }), 'with_documents')
  })

  it('conta documento nenhum como não capturável, e não o contrário', () => {
    // Um cliente com A1 válido e a consulta parada continua capturável. O
    // estado é o da cobertura, que é medida de certificado; documento é volume.
    assert.equal(
      coverageState({ coverage: { total: 3, capturable: 0, not_capturable: 3 }, documents: { total: 12 } }),
      'no_capturable'
    )
  })

  it('aceita o resumo inteiro, como a interface declara', () => {
    assert.equal(coverageState(summary({ coverage: { total: 1, capturable: 1, not_capturable: 0 } })), 'no_documents')
  })

  it('dá a cada estado sem documento uma frase que não existe para o outro', () => {
    // O estado sem documento não pode acusar certificado: o que falta ali é o
    // tempo, não a configuração. E o sem certificado não pode dizer que "não há
    // documentos", que seria a leitura de quem espera por um lote.
    assert.match(fiscalStateCopy('no_capturable', true).description, /certificado/i)
    assert.doesNotMatch(fiscalStateCopy('no_documents', false).description, /certificado/i)
    assert.match(fiscalStateCopy('no_documents', false).description, /nenhum documento/i)
    assert.doesNotMatch(fiscalStateCopy('no_clients', false).description, /certificado/i)
    assert.match(fiscalStateCopy('no_clients', false).description, /cliente/i)
  })
})

describe('a frase do estado conforme a atenção', () => {
  // O cartão de estado e a lista de atenção são as duas metades da mesma tela.
  // O cartão responde "em que estado a carteira está" e a lista responde "por
  // que aquele cliente está assim". Quando o cartão nomeia o mecanismo, ele
  // repete a lista — e passa a poder contradizê-la, porque os cinco motivos de
  // captura nomeiam mecanismos muito diferentes entre si, e nem todos impedem a
  // consulta.

  it('não diz que a consulta está impedida quando o motivo só avisa sobre o futuro', () => {
    // `continuity_warning` é um cliente com A1 válido, `last_seen_at` de 46 dias
    // e uma consulta que não trouxe nada. O backend o mantém na cobertura de
    // propósito — `motivoDaCaptura()` não desconta ninguém — e a lista de baixo
    // convida a voltar a capturar. Um cartão dizendo que a consulta não pode
    // rodar seria a segunda mentira no mesmo item: uma por dizer que a consulta
    // roda, outra por dizer que não.
    const copy = fiscalStateCopy('no_documents', true)
    assert.doesNotMatch(copy.description, /impede|impedid|não roda|bloqueia|bloquead|parou|parada/i)
    assert.match(copy.description, /nenhum documento/i)
    assert.match(copy.description, /atenção/i, 'a frase precisa mandar o operador à lista que carrega o porquê')
  })

  it('também não nomeia mecanismo com um motivo que de fato bloqueia', () => {
    // O caso espelho, e o que torna o primeiro não-trivial: `capture_blocked`
    // é o motivo em que a consulta está de fato parada, e é por isso que ele
    // não pode ser o motivo do cartão. Se o cartão nomeasse o bloqueio aqui, a
    // frase valeria para um dos cinco e mentiria para os outros quatro.
    assert.match(attentionDescription('capture_blocked'), /bloqueou/i)
    assert.doesNotMatch(fiscalStateCopy('no_documents', true).description, /bloque|parou|impede|não roda/i)
  })

  it('não nomeia nenhum dos mecanismos que a lista nomeia', () => {
    // A cobertura do caso acima: em vez de um motivo, todos os verbos que as
    // cinco frases de captura usam. Um cartão que nomeia mecanismo é um cartão
    // que fica errado no motivo que ele não conhece.
    const copy = fiscalStateCopy('no_documents', true)
    for (const mecanismo of ['impede', 'impedid', 'bloque', 'parou', 'interrompid', 'esgotad', 'falhou', 'retom', 'aguard']) {
      assert.doesNotMatch(copy.description, new RegExp(mecanismo, 'i'), `o cartão de estado nomeia "${mecanismo}"`)
    }
  })

  it('não afirma nada sobre a operação quando a lista está vazia', () => {
    // Dia um: cliente novo com A1 válido e sem cursor ainda. A API devolve
    // capturable 1, nenhum documento, lista vazia e `last_capture` null — e o
    // cartão de última consulta diz, na mesma tela, que a conta nunca consultou
    // o fisco. A frase do estado não pode afirmar o contrário, nem prometer a
    // hora de um lote que ninguém consultou.
    const nuncaConsultou = lastCaptureOutcome(null)
    const copy = fiscalStateCopy('no_documents', false)
    assert.match(`${nuncaConsultou.title} ${nuncaConsultou.description}`, /nunca consultou/i)
    assert.doesNotMatch(copy.description, /no ar|rodando|capturando|próxima consulta|próximo lote|próxima execução/i)
    assert.match(copy.description, /nenhum documento/i)
  })

  it('não manda à lista de atenção um estado que diz que a lista está vazia', () => {
    // `no_capturable` com a lista vazia é inalcançável pela derivação do
    // backend hoje, e é exatamente por isso que importa: uma tela que só não
    // quebra porque o dado não pode chegar é uma tela esperando pelo dia em que
    // o dado muda. Aí o painel afirmaria "o motivo de cada um está na lista" ao
    // lado de "nenhum cliente precisa de ação".
    //
    // A asserção é sobre a promessa, não sobre a palavra "lista": a frase nova
    // cita a lista justamente para desmenti-la, e um `doesNotMatch` na palavra
    // reprovaria a correção.
    const copy = fiscalStateCopy('no_capturable', false)
    assert.doesNotMatch(copy.description, /o motivo de cada um está na lista/i)
    assert.match(copy.description, /certificado/i)
    assert.match(copy.description, /não traz motivo/i, 'a ausência de motivo precisa ser dita, não escondida')
  })

  it('manda à lista de atenção quando a lista tem o que dizer', () => {
    const copy = fiscalStateCopy('no_capturable', true)
    assert.match(copy.description, /certificado/i)
    assert.match(copy.description, /lista de atenção/i)
  })

  it('diz a mesma coisa para uma carteira sem cliente, com ou sem atenção', () => {
    // Sem cliente não há de onde vir atenção, e a frase não faz nenhuma
    // afirmação que a lista pudesse contradizer — logo ela não muda de forma.
    assert.equal(fiscalStateCopy('no_clients', true).description, fiscalStateCopy('no_clients', false).description)
  })

  it('nenhuma das frases afirma o que a outra metade da tela afirma ao contrário', () => {
    // Os dois payloads que o backend consegue montar, e as duas metades da tela
    // lado a lado.
    const listaVazia = `${fiscalNoAttention.title} ${fiscalNoAttention.description}`
    const listaCheia = attentionDescription('capture_blocked')

    // `no_capturable` sem lista não pode prometer que a lista explica cada
    // cliente, porque a lista vazia diz que ninguém precisa de ação.
    assert.match(listaVazia, /nenhum cliente precisa de ação/i)
    assert.doesNotMatch(fiscalStateCopy('no_capturable', false).description, /o motivo de cada um está na lista/i)

    // `no_documents` com lista não pode dizer que a captura roda, porque a
    // lista cheia diz que o fisco segurou a consulta.
    assert.match(listaCheia, /bloqueou/i)
    assert.doesNotMatch(fiscalStateCopy('no_documents', true).description, /no ar|próxima consulta|próximo lote/i)
  })
})

describe('participação da carteira', () => {
  it('mede a proporção de capturáveis sobre o total', () => {
    assert.deepEqual(coverageShare({ total: 4, capturable: 3, not_capturable: 1 }), { total: 4, capturable: 3, percent: 75 })
    assert.equal(coverageShare({ total: 3, capturable: 2, not_capturable: 1 })?.percent, 67)
  })

  it('não devolve 0% para uma carteira que não existe', () => {
    // 0 de 0 é uma divisão que ninguém fez. Sem `null`, o painel teria que
    // escolher entre mentir com 0% e esconder a carteira — e é o segundo erro
    // que a tela do "nenhum cliente" resolve.
    assert.equal(coverageShare({ total: 0, capturable: 0, not_capturable: 0 }), null)
  })
})

describe('rótulo dos nove motivos', () => {
  it('nomeia a ação do reenvio exatamente como o contrato manda', () => {
    assert.equal(attentionLabel('certificate_reupload'), 'Reenvie o certificado')
  })

  it('cobre os nove motivos, cada um com frase própria', () => {
    const labels = ALL_REASONS.map(attentionLabel)
    assert.equal(labels.length, 9)
    for (const label of labels) assert.ok(label.length > 0, 'rótulo vazio')
    assert.equal(new Set(labels).size, 9, 'dois motivos com o mesmo rótulo')
  })

  it('dá a cada motivo uma frase que diz o que fazer', () => {
    for (const reason of ALL_REASONS) {
      assert.ok(attentionDescription(reason).length > 0, `frase vazia em ${reason}`)
      assert.ok(attentionTone(reason), `sem cor em ${reason}`)
    }
  })

  it('distingue senha não guardada de senha que não abre o certificado', () => {
    // São dois ação diferentes e um erro de meio de minuto: a primeira se
    // resolve gravando a senha, a segunda se resolve mandando o arquivo de novo.
    assert.notEqual(attentionLabel('certificate_password_missing'), attentionLabel('certificate_reupload'))
    assert.match(attentionDescription('certificate_password_missing'), /senha/i)
    assert.match(attentionDescription('certificate_reupload'), /reenv/i)
  })

  it('não oferece certificado para o bloqueio do fisco', () => {
    // O backend é explícito: o conserto que o painel ofereceria aqui — "reenvie
    // o certificado" — não resolveria nada, porque o certificado está de pé e a
    // parada é uma janela.
    const description = attentionDescription('capture_blocked')
    assert.doesNotMatch(description, /certificado/i)
    assert.match(description, /fisco/i)
  })

  it('diz que o histórico interrompido não volta, e não promete a captura', () => {
    const description = attentionDescription('history_interrupted')
    assert.match(description, /período perdido não pode ser recuperado/)
    // Nenhum verbo de retomar, refazer ou recuperar: depois de 60 dias parado o
    // fisco não gera posição retroativa, e um "retome a captura" seria uma
    // promessa que a próxima execução não pode cumprir.
    assert.doesNotMatch(description, /\b(retome|retomar|retomada|reanudar|reanude|recupere|recuperar|recuperação|restaura|restaurar)\b/)
  })

  it('não derruba a tela com um motivo que o painel não conhece', () => {
    // O tipo é fechado, e mesmo assim um décimo motivo do backend não pode
    // derrubar o SSR inteiro. Ele aparece com o próprio código, que é a única
    // coisa que o operador consegue comparar com o que a API mandou.
    const reason = 'capture_retry_budget' as FiscalAttentionReason
    assert.equal(attentionLabel(reason), 'capture_retry_budget')
    assert.ok(attentionDescription(reason).length > 0)
    assert.ok(attentionIcon(reason).length > 0)
    assert.ok(attentionTone(reason))
  })

  it('diz que nenhum motivo é a vez do outro', () => {
    // Cada motivo existe porque é a ação de um operador diferente: quem repara
    // certificado, quem grava senha e quem espera a janela do fisco não têm
    // nada em comum. Uma lista de rótulos repetidos ensinaria o escritório a
    // ignorar a lista inteira.
    const descriptions = ALL_REASONS.map(attentionDescription)
    assert.equal(new Set(descriptions).size, 9, 'dois motivos com a mesma frase')
  })

  it('diz o contrário no aviso de continuidade, que ainda é recuperável', () => {
    // A faixa antes da interrupção é justamente a que ainda tem remediado, e é
    // por isso que ela convida a voltar a capturar. Um teste que tratasse as
    // duas como o mesmo texto apagaria a diferença que o fisco faz.
    assert.match(attentionDescription('continuity_warning'), /retom/i)
  })

  it('trata a posição esgotada como documento perdido, não como atraso', () => {
    const description = attentionDescription('gap_abandoned')
    assert.match(description, /perdido/i)
    assert.doesNotMatch(description, /retom/i)
  })
})

describe('grupos de atenção', () => {
  it('devolve lista vazia quando nada está em atenção', () => {
    // Sem cliente em atenção não existe grupo para desenhar, e nenhum número
    // substituto: um "0 clientes em atenção" seria uma contagem que o painel
    // inventou para preencher a seção.
    assert.deepEqual(attentionGroups([]), [])
    assert.ok(fiscalNoAttention.title.length > 0)
  })

  it('não afirma que a carteira está capturando quando a lista está vazia', () => {
    // A lista vazia é a mesma em três carteiras diferentes — uma capturando bem,
    // uma parada há dois meses sem nenhum cliente, uma com zero cliente. Uma
    // frase sobre a operação ("está rodando", "em dia") seria uma afirmação
    // que nenhuma das três sustenta; a frase tem que falar do que foi
    // conferido, e só.
    assert.doesNotMatch(fiscalNoAttention.description, /rodando|funcionando|em dia|saud/i)
  })

  it('agrupa pela precedência do backend, não pela ordem que chegou', () => {
    const items = [
      item('Alfa', 'capture_failed'),
      item('Beta', 'certificate_absent'),
      item('Gama', 'history_interrupted'),
      item('Delta', 'certificate_expired')
    ]
    // O backend entrega em ordem alfabética de nome e diz, no seu próprio
    // comentário, que agrupar é com o painel. A ordem que os grupos recebem é a
    // precedência de `FiscalCoverage::motivo()`: o que impede a consulta de
    // existir vem antes do que se resolve sozinho.
    assert.deepEqual(attentionGroups(items).map(group => group.reason), [
      'certificate_absent',
      'certificate_expired',
      'history_interrupted',
      'capture_failed'
    ])
  })

  it('mantém a ordem do backend dentro de cada grupo', () => {
    const items = [item('Ana', 'capture_blocked'), item('Bruno', 'capture_blocked'), item('Carla', 'certificate_absent')]
    const blocked = attentionGroups(items).find(group => group.reason === 'capture_blocked')
    assert.deepEqual(blocked?.items.map(i => i.client_name), ['Ana', 'Bruno'])
  })

  it('esconde nenhum cliente quando o motivo não é conhecido', () => {
    // Um motivo fora da precedência sumiria da lista se o agrupamento só
    // varresse a ordem conhecida — e um cliente que some da lista de atenção é
    // pior que um cliente com rótulo genérico, porque ninguém percebe a falta.
    const groups = attentionGroups([
      item('Desconhecido', 'capture_retry_budget' as FiscalAttentionReason),
      item('Conhecido', 'certificate_absent')
    ])
    assert.deepEqual(groups.map(group => group.reason), ['certificate_absent', 'capture_retry_budget'])
    assert.equal(groups[1]?.items[0]?.client_name, 'Desconhecido')
  })

  it('cria um grupo por motivo, com os nove juntos, uma vez cada', () => {
    const groups = attentionGroups(ALL_REASONS.map((reason, index) => item(`Cliente ${index}`, reason)))
    assert.equal(groups.length, 9)
    assert.deepEqual(groups.map(group => group.reason), [...ALL_REASONS])
    for (const group of groups) assert.equal(group.items.length, 1)
  })

  it('carrega a hora do bloqueio só no item que a tem', () => {
    const groups = attentionGroups([
      item('Ana', 'capture_blocked', '2026-10-01T12:00:00Z'),
      item('Bruno', 'certificate_absent')
    ])
    const blocked = groups.find(group => group.reason === 'capture_blocked')
    assert.equal(blocked?.items[0]?.blocked_until, '2026-10-01T12:00:00Z')
    assert.equal(groups.find(group => group.reason === 'certificate_absent')?.items[0]?.blocked_until, null)
  })
})

describe('tempo restante do bloqueio', () => {
  const now = new Date('2026-09-28T16:00:00Z')

  it('não diz nada quando o item não é um bloqueio', () => {
    assert.equal(blockedRemaining(null, now), null)
  })

  it('diz quanto falta, e não só que está bloqueado', () => {
    assert.equal(blockedRemaining('2026-09-30T16:00:00Z', now), 'faltam 2 dias')
    assert.equal(blockedRemaining('2026-09-30T20:00:00Z', now), 'faltam 2 dias e 4 h')
    assert.equal(blockedRemaining('2026-09-28T19:20:00Z', now), 'faltam 3 h 20 min')
    assert.equal(blockedRemaining('2026-09-28T16:40:00Z', now), 'faltam 40 min')
  })

  it('diz que a janela passou em vez de dizer que falta tempo negativo', () => {
    assert.equal(blockedRemaining('2026-09-28T15:00:00Z', now), 'a janela já passou')
  })

  it('não quebra com uma data que o cliente não sabe ler', () => {
    assert.equal(blockedRemaining('amanhã', now), null)
  })

  it('conta a partir do relógio que a página passa, não do relógio que a função tem', () => {
    // A função não tem relógio próprio: quem conta é quem tem o dado, e o erro
    // que ela precisa tornar impossível é o painel continuar contando do
    // relógio do render anterior depois de recarregar.
    const until = '2026-09-30T16:00:00Z'
    assert.equal(blockedRemaining(until, new Date('2026-09-28T16:00:00Z')), 'faltam 2 dias')
    assert.equal(blockedRemaining(until, new Date('2026-09-29T16:00:00Z')), 'faltam 1 dia')
    assert.equal(blockedRemaining(until, new Date('2026-09-30T16:00:00Z')), 'a janela já passou')
  })

  it('nunca cresce quando o relógio avança, para o mesmo valor do servidor', () => {
    // Esta é a propriedade que o relógio congelado quebrava: um painel que
    // reancora a cada resposta conta a janela de menos, nunca de mais. As três
    // âncoras abaixo saem da mesma resposta do servidor; a última é o que a
    // tela mostra uma hora depois de aberta, sem nenhum clique.
    const until = '2026-10-05T12:00:00Z'
    const naResposta = blockedRemaining(until, fiscalReferenceNow('2026-10-01T12:00:00Z'))
    const umaHoraDepois = blockedRemaining(until, fiscalReferenceNow('2026-10-01T13:00:00Z'))
    const umDiaDepois = blockedRemaining(until, fiscalReferenceNow('2026-10-02T12:00:00Z'))

    assert.equal(naResposta, 'faltam 4 dias')
    assert.equal(umaHoraDepois, 'faltam 3 dias e 23 h')
    assert.equal(umDiaDepois, 'faltam 3 dias')
    assert.ok(umaHoraDepois !== naResposta, 'um painel aberto não pode mostrar a mesma contagem para sempre')
  })

  it('conta a janela a partir do instante em que a resposta chegou, não do anterior', () => {
    // O caso que a revisão nomeou: o servidor reenvia `blocked_until` três
    // horas depois do primeiro render. Contar do relógio antigo daria "faltam
    // 2 dias e 10 h" para uma janela que o servidor, naquele instante, disse
    // que durava 2 dias e 7 h — o painel growria a janela sozinho.
    const servidorEmT0 = blockedRemaining('2026-10-03T19:00:00Z', fiscalReferenceNow('2026-10-01T12:00:00Z'))
    const servidorEmT1 = blockedRemaining('2026-10-03T19:00:00Z', fiscalReferenceNow('2026-10-01T15:00:00Z'))

    assert.equal(servidorEmT0, 'faltam 2 dias e 7 h')
    assert.equal(servidorEmT1, 'faltam 2 dias e 4 h')
    assert.notEqual(servidorEmT1, servidorEmT0)
  })

  it('ancora no instante da resposta, e não em outro relógio qualquer', () => {
    const ancora = fiscalReferenceNow('2026-10-01T12:00:00Z')
    assert.equal(ancora.getTime(), new Date('2026-10-01T12:00:00Z').getTime())
    assert.equal(fiscalReferenceNow(new Date('2026-10-01T12:00:00Z')).getTime(), ancora.getTime())
  })

  it('ancora em agora quando a resposta não trouxe um instante legível', () => {
    // Sem âncora não há contagem, e a única resposta segura para um relógio
    // ilegível é o próprio agora — nunca o relógio de um render anterior, que é
    // o defeito que esta função existe para impedir.
    const antes = Date.now()
    const ancora = fiscalReferenceNow('quinta-feira que vem')
    assert.ok(ancora.getTime() >= antes)
    assert.ok(ancora.getTime() <= Date.now())
  })
})

describe('volume por modelo', () => {
  it('lê a chave do modelo e o número que o resumo mandou', () => {
    assert.deepEqual(modelVolumes({ nfe: 12, cte: 3 }), [
      { model: 'nfe', label: 'NF-e', total: 12 },
      { model: 'cte', label: 'CT-e', total: 3 }
    ])
  })

  it('não inventa linha para o modelo que o resumo não mandou', () => {
    // O mapa vem só com o modelo que tem documento. Preencher `nfse: 0` seria
    // um número que o fisco não emitiu, e é o que a placa de modelo faria se
    // alguém indexasse o enum em vez de percorrer o mapa.
    assert.deepEqual(modelVolumes({ nfe: 2 }).map(row => row.model), ['nfe'])
    assert.deepEqual(modelVolumes({}), [])
  })

  it('conta os modelos que o mapa traz, e só eles', () => {
    // O total de documentos é uma medida da conta inteira; a quebra por modelo
    // é uma leitura desse mesmo total. Se os dois divergissem, o painel estaria
    // exibindo duas quantidades incompatíveis para o mesmo conjunto de linhas.
    const documents = { total: 15, models: { nfe: 12, cte: 3 }, over_time: [] }
    const sum = modelVolumes(documents.models).reduce((total, row) => total + row.total, 0)
    assert.equal(sum, documents.total)
  })

  it('nomeia um modelo que ele não conhece em vez de sumir com ele', () => {
    // CT-e chega pelo mesmo mapa quando o conector entrar; um rótulo fixo por
    // modelo quebraria na primeira linha nova.
    assert.equal(modelLabel('nfe'), 'NF-e')
    assert.equal(modelLabel('nfce'), 'NFC-e')
    assert.equal(modelLabel('cte'), 'CT-e')
    assert.equal(modelLabel('nfse'), 'NFS-e')
    assert.equal(modelLabel('nfe_retirada'), 'nfe_retirada')
  })
})

describe('rótulo do mês e da fonte', () => {
  it('mantém a série na ordem e com o rótulo do mês', () => {
    // A ordem vem do backend e o eixo depende dela: reordered, o gráfico contaria
    // a série de trás para frente, e nenhuma asserção de conteúdo pegaria.
    assert.deepEqual(
      fiscalMonthSeries([{ month: '2026-01', total: 2 }, { month: '2026-02', total: 5 }]),
      [{ label: '01/26', total: 2 }, { label: '02/26', total: 5 }]
    )
    assert.deepEqual(fiscalMonthSeries([]), [])
  })

  it('escreve o mês da chave sem construir data', () => {
    // A chave já é `YYYY-MM`. Montar uma data com ela passaria a hora do mês
    // para o mês anterior em fuso negativo, e o eixo erraria por construção.
    assert.equal(fiscalMonthLabel('2026-09'), '09/26')
    assert.equal(fiscalMonthLabel('2026-01'), '01/26')
  })

  it('devolve a chave como veio quando ela não é um mês', () => {
    assert.equal(fiscalMonthLabel('setembro'), 'setembro')
    // `2026-13` também não é um mês, e é o caso que separa a validação da
    // aritmética: `new Date(2026, 12, 1)` não erra, ele vira janeiro de 2027 e
    // pinta o eixo com um mês que o banco nunca mandou.
    assert.equal(fiscalMonthLabel('2026-13'), '2026-13')
    assert.equal(fiscalMonthLabel('2026-00'), '2026-00')
  })

  it('nomeia a fonte da última consulta', () => {
    assert.equal(fiscalSourceLabel('nfe_distribuicao'), 'NF-e')
    assert.equal(fiscalSourceLabel('cte_distribuicao'), 'CT-e')
  })
})

describe('última consulta', () => {
  it('distingue "nunca consultou" de "consultou e não achou nada"', () => {
    // O backend manda `null` para o primeiro caso, e a distinção é a primeira
    // coisa que o painel precisa fazer: uma captura que nunca rodou não é a
    // mesma notícia que uma captura que rodou e não trouxe nada.
    const never = lastCaptureOutcome(null)
    const ran = lastCaptureOutcome({ source: 'nfe_distribuicao', ran_at: '2026-09-28T14:30:00Z', error: null })
    assert.equal(never.tone, 'neutral')
    assert.match(never.title, /nunca/i)
    assert.notEqual(never.title, ran.title)
    assert.notEqual(never.description, ran.description)
    // E nenhuma das duas diz que "não há documento": essa é a frase do estado
    // `no_documents`, que responde por ela com a cobertura do lado.
    assert.doesNotMatch(never.description, /nenhum documento|nenhum ainda/i)
  })

  it('conta a última consulta com a hora e a fonte', () => {
    const outcome = lastCaptureOutcome({ source: 'nfe_distribuicao', ran_at: '2026-09-28T14:30:00Z', error: null })
    assert.equal(outcome.tone, 'success')
    assert.match(outcome.title, /NF-e/)
    assert.match(formatFiscalDateTime('2026-09-28T14:30:00Z'), /2026/)
  })

  it('mostra a frase de erro como ela veio, sem transformá-la em motivo', () => {
    // `last_capture.error` é texto limitado de propósito; `reason` é uma das
    // nove palavras. Mostrar a frase e dizer ao lado "Captura falhou" seria
    // inventar o motivo que o backend não mandou.
    const outcome = lastCaptureOutcome({ source: 'cte_distribuicao', ran_at: '2026-09-28T14:30:00Z', error: 'NfeCommunicationException' })
    assert.equal(outcome.tone, 'warning')
    assert.match(outcome.description, /NfeCommunicationException/)
  })

  it('trata um instante ausente como dado ausente, não como agora', () => {
    assert.equal(formatFiscalDateTime(null), '—')
  })
})

describe('contagem em pt-BR', () => {
  it('formata o número como o resto do produto formata', () => {
    assert.equal(formatFiscalCount(1234), '1.234')
  })
})
