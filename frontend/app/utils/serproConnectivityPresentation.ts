import type { SerproConnectivityResult } from '~/types/serpro'

type Tone = 'success' | 'warning' | 'error'

/**
 * What the provider rejected, in the operator's words. An element this client
 * does not know falls back to the code itself: a fault nobody can read is worse
 * than one that is merely untranslated.
 */
export const failedElementLabels: Record<string, string> = {
  configuracao: 'Configuração',
  certificado: 'Certificado',
  credencial: 'Credencial',
  provedor: 'Provedor'
}

/**
 * A failure that is not the operator's credential to fix. The backend maps both
 * the provider being out of reach and the machine running the check being unable
 * to run it to `provedor`, because the action is the same for the two: wait, and
 * do not re-enter any data.
 */
export function isProviderFailure(result: SerproConnectivityResult | null): boolean {
  return result?.failed_element === 'provedor'
}

export function connectivityTone(result: SerproConnectivityResult | null): Tone {
  if (result?.ok) return 'success'
  return isProviderFailure(result) ? 'warning' : 'error'
}

export function connectivityIcon(result: SerproConnectivityResult | null): string {
  if (result?.ok) return 'i-lucide-circle-check'
  return isProviderFailure(result) ? 'i-lucide-cloud-off' : 'i-lucide-circle-alert'
}

/**
 * The title never names a culprit.
 *
 * The four-element contract cannot tell the two causes of `provedor` apart — the
 * SERPRO service being down, and this machine being unable to run the check at
 * all — so a title reading "the provider did not answer" would send an operator
 * to watch a status page for a disk that filled up. `message` is where the
 * backend says which of the two it was.
 */
export function connectivityTitle(result: SerproConnectivityResult | null): string {
  if (result?.ok) return 'Conexão autenticada com sucesso'
  if (isProviderFailure(result)) return 'A verificação não pôde ser concluída'
  return 'Não foi possível autenticar'
}

export function failedElementName(result: SerproConnectivityResult | null): string | null {
  const element = result?.failed_element
  if (!element) return null
  return failedElementLabels[element] ?? element
}
