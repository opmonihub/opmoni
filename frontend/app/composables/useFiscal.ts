import type {
  FiscalCaptureAccepted,
  FiscalClientSummary,
  FiscalClientsFilters,
  FiscalDetail,
  FiscalListFilters,
  FiscalPage,
  FiscalSource,
  FiscalSummary
} from '~/types/fiscal'
import { queryOf } from './useApiQuery'

/**
 * O cliente do módulo Fiscal.
 *
 * Envelopado fino e sem estado: cada método é uma chamada ao plugin `$api`, que
 * já carrega o `/api`, o cookie de sessão e o `X-XSRF-TOKEN`, e já trata 401 e
 * 419. Todo caminho aqui é escrito **sem** o `/api` — um segundo cliente com
 * base própria perderia os cookies, e é a sessão que autentica a captura.
 */
export function useFiscal() {
  const { $api } = useNuxtApp()

  /**
   * O resumo do painel: cobertura da carteira, o que precisa de alguém e o que
   * o último lote deixou.
   */
  async function summary() {
    const response = await $api<{ data: FiscalSummary }>('/fiscal/summary')
    return response.data
  }

  /**
   * A visão agregada por cliente: totais, saídas, entradas, volume por modelo,
   * última emissão e o estado do A1 de cada um.
   *
   * Devolve o envelope `{ data }` como o resumo, e os filtros vão pelo mesmo
   * `queryOf` da lista: vazio, `null` ou `undefined` é descartado. Um 404 aqui
   * é "o backend ainda não subiu" — e quem chama trata, porque a página tem um
   * estado próprio para isso em vez de um alerta genérico.
   */
  async function clients(filters: FiscalClientsFilters = {}) {
    const response = await $api<{ data: FiscalClientSummary[] }>('/fiscal/clients', {
      query: queryOf(filters)
    })
    return response.data
  }
  /**
   * A tabela única de documentos capturados, filtrada e paginada.
   *
   * Devolve o envelope inteiro e não só `data`: `available_models` alimenta o
   * filtro de modelo e `meta` alimenta a paginação, e uma tela que emitisse duas
   * chamadas para desenhar uma lista poderia discordar de si mesma. O filtro
   * esvaziado vai como `undefined`/`null` e `queryOf` o descarta.
   */
  async function list(filters: FiscalListFilters) {
    return $api<FiscalPage>('/fiscal/documents', { query: queryOf(filters) })
  }

  /** A linha mais a linha do tempo e a prévia do XML. */
  async function show(id: number) {
    const response = await $api<{ data: FiscalDetail }>(`/fiscal/documents/${id}`)
    return response.data
  }

  /**
   * O XML gravado, como arquivo.
   *
   * É uma requisição autenticada, então é um `Blob` pelo plugin e nunca um
   * `<a href>`: um link nu seria uma navegação sem a sessão, que o servidor
   * responderia com 401 em vez do arquivo.
   *
   * Só no cliente, e nunca no SSR: um `Blob` não existe no servidor, e
   * `responseType: 'blob'` lá devolveria uma bufferização de resposta que não é o
   * documento. Quem chama no servidor não tem o que fazer com isto.
   */
  async function download(id: number) {
    return $api<Blob>(`/fiscal/documents/${id}/xml`, { responseType: 'blob' })
  }

  /**
   * Enfileira a captura de um cliente e devolve o aceite da fila — 202, com o
   * cliente e nada de posição: a posição só existe depois que o fisco devolveu.
   *
   * Recusa (409) e membro sem escrita (403) chegam como erro do `$api`, e quem
   * chama trata: são duas mensagens diferentes para o operador.
   */
  async function capture(clientId: number, source: FiscalSource) {
    const response = await $api<{ data: FiscalCaptureAccepted }>(`/fiscal/clients/${clientId}/capture`, {
      method: 'POST',
      body: { source }
    })
    return response.data
  }

  return { summary, clients, list, show, download, capture }
}
