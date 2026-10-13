/**
 * The enablement switch of the current Account, in the operator's words.
 *
 * Every sentence lives here and not in the `.vue` template for the same
 * reason as `monitoringPresentation`: `node --test` cannot import an SFC, and
 * a line written in the template is a line no consistency check can read.
 * Disabling never erases anything — runs and synchronized data stay
 * readable — and the texts below are the ones that carry that promise.
 */

/** The status line under the switch, next to the badge. */
export function enablementNotice(enabled: boolean): string {
  return enabled ? 'Integração habilitada' : 'Integração desabilitada para este Account'
}

/**
 * The badge and its caption. The disabled caption says what disabling keeps,
 * because "desabilitada" alone could be read as "history deleted".
 */
export function enablementState(enabled: boolean): { label: string, description: string } {
  return enabled
    ? { label: 'Habilitada', description: 'Este Account sincroniza clientes com o Integra Contador.' }
    : { label: 'Desabilitada', description: 'Nenhuma sincronização nova é disparada; o histórico permanece.' }
}

/** The action button — always the opposite of the current state. */
export function enablementAction(enabled: boolean): string {
  return enabled ? 'Desabilitar integração' : 'Habilitar integração'
}

/**
 * What the confirmation says when turning the integration off. Turning it on
 * asks nothing — enabling is recoverable in one click — and disabling is the
 * step that stops future work, so it is the one that gets a second look.
 * Returns `null` when no confirmation applies.
 */
export function enablementConfirm(enabled: boolean): { title: string, description: string } | null {
  return enabled
    ? {
        title: 'Desabilitar a integração deste Account?',
        description: 'Nenhuma sincronização nova será disparada. Execuções e dados já sincronizados permanecem.'
      }
    : null
}
