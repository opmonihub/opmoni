import type { ClientMonitoringModules, MonitoringModuleItem, ObligationCategory } from '../types/serpro.ts'

/**
 * A leitura da etapa de módulos do cadastro: quais obrigações a lista mostra,
 * em que ordem, e o que o "confirmar" manda.
 *
 * Módulo puro por construção — só `import type`, com a extensão explícita,
 * porque o runner de teste do Node o carrega por stripping nativo, sem bundler
 * e sem resolver alias. A mesma disciplina de `fiscalPresentation.ts`.
 */

/**
 * As categorias que o provedor serve. `unavailable` e `extinct` não entram na
 * etapa: a API já responde 422 para elas, e um checkbox que sempre falha é um
 * rótulo mentindo sobre o que o produto faz.
 */
export function isServedObligationCategory(category: ObligationCategory): boolean {
  return category === 'direct' || category === 'derived'
}

/**
 * A obrigação sugerida vem antes da não sugerida, e dentro de cada grupo a
 * ordem é alfabética de rótulo — a etapa é uma escolha de conferência, e a
 * sugestão escondida no meio da lista é a que ninguém desmarca.
 */
export function sortMonitoringModules(obligations: readonly MonitoringModuleItem[]): MonitoringModuleItem[] {
  return [...obligations]
    .filter(item => isServedObligationCategory(item.category))
    .sort((a, b) => {
      if (a.suggested !== b.suggested) return a.suggested ? -1 : 1
      return a.label.localeCompare(b.label, 'pt-BR')
    })
}

export interface MonitoringModuleGroup {
  id: 'suggested' | 'available'
  label: string
  items: MonitoringModuleItem[]
}

/**
 * Os dois grupos da etapa: as que o mapa regime → obrigações sugere, e as
 * demais servidas. Um grupo vazio some — um cabeçalho "Sugeridas" sobre zero
 * itens afirmaria que a sugestão existe e não foi mostrada.
 */
export function monitoringModuleGroups(payload: Pick<ClientMonitoringModules, 'obligations'>): MonitoringModuleGroup[] {
  const sorted = sortMonitoringModules(payload.obligations)
  const suggested = sorted.filter(item => item.suggested)
  const available = sorted.filter(item => !item.suggested)

  const groups: MonitoringModuleGroup[] = []
  if (suggested.length > 0) groups.push({ id: 'suggested', label: 'Sugeridas para o regime', items: suggested })
  if (available.length > 0) groups.push({ id: 'available', label: 'Outras obrigações', items: available })
  return groups
}

/**
 * O que a etapa marca quando abre: cada obrigação servida marcada por
 * `suggested`, mais as que o cliente **já** tem associadas — a etapa não pode
 * nascer oferecendo remover uma associação que ela mesma não sabe remover.
 */
export function initialMonitoringModuleSelection(payload: Pick<ClientMonitoringModules, 'obligations'>): Set<string> {
  const selected = new Set<string>()
  for (const item of sortMonitoringModules(payload.obligations)) {
    if (item.suggested || item.associated) selected.add(item.slug)
  }
  return selected
}

/**
 * O body do `POST .../monitoring-modules`: os slugs marcados, na mesma ordem
 * da lista — a resposta do backend não depende da ordem, mas um payload que
 * reflete o que a tela mostra é o que um log de suporte consegue ler.
 *
 * Uma obrigação já associada continua no payload quando marcada: o backend a
 * conta em `already`, e tirá-la da lista seria pedir desassociação — que o
 * endpoint não faz de propósito.
 */
export function monitoringModulesPayload(payload: Pick<ClientMonitoringModules, 'obligations'>, selected: ReadonlySet<string>): string[] {
  return sortMonitoringModules(payload.obligations)
    .filter(item => selected.has(item.slug))
    .map(item => item.slug)
}
