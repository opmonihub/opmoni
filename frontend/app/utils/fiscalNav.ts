import type { NavigationMenuItem } from '@nuxt/ui'

/**
 * Os destinos do módulo Fiscal — o que a barra de abas do shell carrega e o que
 * a lateral repete como filhos.
 *
 * O tipo vem em `import type` de propósito: o runner de teste do Node executa
 * este arquivo por stripping de tipos nativo, sem bundler e sem resolver alias,
 * e um import de runtime de um pacote não carregaria. O mesmo cuidado vale para
 * qualquer tipo que este arquivo declarar.
 */
export interface FiscalNavItem {
  label: string
  icon: string
  to: string
}

export const fiscalNav: readonly FiscalNavItem[] = [
  { label: 'Painel', icon: 'i-lucide-layout-dashboard', to: '/fiscal' },
  { label: 'Clientes', icon: 'i-lucide-building-2', to: '/fiscal/clientes' },
  { label: 'Documentos', icon: 'i-lucide-files', to: '/fiscal/documentos' }
]

/**
 * O item mais específico que contém a rota — o prefixo mais longo que casa.
 *
 * `/fiscal` é pai de `/fiscal/documentos`, então casar por prefixo sozinho
 * acenderia os dois em `/fiscal/documentos`, e o rail mostraria duas posições ao
 * mesmo tempo. Aqui os dois são irmãos: o painel do módulo é a tela do `/fiscal`
 * exato, e `/fiscal/documentos` é a tabela. O mais longo que casa é o que está
 * aceso.
 */
function containingItem(path: string): FiscalNavItem | undefined {
  let found: FiscalNavItem | undefined
  for (const item of fiscalNav) {
    if (path !== item.to && !path.startsWith(`${item.to}/`)) continue
    if (!found || item.to.length > found.to.length) found = item
  }
  return found
}

/**
 * O par `exact`/`active` de um item para a rota dada.
 *
 * `exact` é obrigatório no item ancestral, e não um detalhe: o
 * `UNavigationMenu` recalcula `active` sozinho a partir do próprio `to`, e sem
 * ele o componente voltaria a acender `/fiscal` pelo prefixo — desmentindo na
 * tela o `active` que este arquivo calcula. Marcar exato é o que diz ao
 * componente que aquele item tem filho na sua própria frente.
 */
function marking(path: string, item: FiscalNavItem): Pick<NavigationMenuItem, 'exact' | 'active'> {
  return {
    exact: fiscalNav.some(other => other.to !== item.to && other.to.startsWith(`${item.to}/`)),
    active: containingItem(path)?.to === item.to
  }
}

/**
 * Os filhos da lateral do módulo, com a rota atual marcada.
 *
 * O prefixo continua sendo o que segura o rail aceso embaixo do item: a regra é
 * para qualquer rota filha do módulo, e não só para as duas que existem hoje.
 * O detalhe de um documento é uma folha sobre a tabela — não é uma tela com
 * rota — mas qualquer rota filha que o módulo ganhe depois vai precisar do mesmo
 * prefixo, e é ele que decide se o rail acende.
 */
export function fiscalSidebarChildren(path: string): NavigationMenuItem[] {
  return fiscalNav.map(item => ({ label: item.label, to: item.to, ...marking(path, item) }))
}

/**
 * A barra de abas do shell, na mesma forma que `adminTabs` e `monitoringTabs`.
 *
 * Sai de `marking` de propósito, e não de `fiscalSidebarChildren`: a barra e o
 * rail precisam concordar sobre onde o operador está, e duas cópias da regra de
 * prefixo divergem na primeira tela de detalhe.
 */
export function fiscalTabs(path: string): NavigationMenuItem[][] {
  return [[
    ...fiscalNav.map(item => ({
      label: item.label,
      icon: item.icon,
      to: item.to,
      ...marking(path, item)
    }))
  ]]
}
