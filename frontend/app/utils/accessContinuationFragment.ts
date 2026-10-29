import { shallowRef, type ShallowRef } from 'vue'

export type AccessContinuationKind = 'member-invitation' | 'password-recovery'

export type AccessContinuationFragment
  = | { kind: 'member-invitation', token: string }
    | { kind: 'password-recovery', token: string, delivery_id: string }

const pending = new Map<AccessContinuationKind, AccessContinuationFragment>()
const invalidRouteCaptures = new Set<AccessContinuationKind>()
const routeCaptureVersions: Record<AccessContinuationKind, ShallowRef<number>> = {
  'member-invitation': shallowRef(0),
  'password-recovery': shallowRef(0)
}

export function accessContinuationRouteCaptureVersion(kind: AccessContinuationKind): Readonly<ShallowRef<number>> {
  return routeCaptureVersions[kind]
}

function expectedPath(kind: AccessContinuationKind) {
  return kind === 'member-invitation' ? '/erp/convite' : '/redefinir-senha'
}

function removeContinuationFromRouterLocation(value: string) {
  if (typeof window === 'undefined' || !value.startsWith('/')) return value
  try {
    const url = new URL(value, window.location.origin)
    // These are dedicated bearer receivers. Drop the full fragment on these
    // routes because URLSearchParams accepts reordered and encoded keys too.
    if ((url.pathname === '/erp/convite' || url.pathname === '/redefinir-senha') && url.hash)
      return `${url.pathname}${url.search}`
  } catch { /* Preserve non-URL router state values. */ }
  return value
}

function sanitizeRouterHistoryState(state: unknown) {
  if (!state || typeof state !== 'object' || Array.isArray(state)) return state
  const clean = { ...(state as Record<string, unknown>) }
  for (const key of ['back', 'current', 'forward']) {
    if (typeof clean[key] === 'string') clean[key] = removeContinuationFromRouterLocation(clean[key] as string)
  }
  return clean
}

export function parseAccessContinuationFragment(kind: AccessContinuationKind, hash: string): AccessContinuationFragment | null {
  const values = new URLSearchParams(hash.replace(/^#/, ''))
  const tokenValues = values.getAll('token')
  const deliveryValues = values.getAll('delivery')
  const allowedCount = kind === 'member-invitation' ? 1 : 2
  if (values.size !== allowedCount || tokenValues.length !== 1 || !/^[a-f\d]{64}$/i.test(tokenValues[0]!)) return null
  if (kind === 'member-invitation')
    return { kind, token: tokenValues[0]! }
  if (deliveryValues.length !== 1 || !/^[1-9]\d{0,19}$/.test(deliveryValues[0]!)) return null
  return { kind, token: tokenValues[0]!, delivery_id: deliveryValues[0]! }
}

/** Capture a bearer from an SPA destination before Vue Router writes that URL into history. */
export function captureAccessContinuationFromRoute(kind: AccessContinuationKind, hash: string): boolean {
  if (!hash) return false
  const fragment = parseAccessContinuationFragment(kind, hash)
  if (fragment) {
    pending.set(kind, fragment)
    invalidRouteCaptures.delete(kind)
  } else {
    pending.delete(kind)
    invalidRouteCaptures.add(kind)
  }
  routeCaptureVersions[kind].value++
  return true
}

/** Capture a delivery bearer into module memory and remove it from browser history immediately. */
export function captureAccessContinuationFromLocation(kind: AccessContinuationKind): AccessContinuationFragment | null {
  if (typeof window === 'undefined' || window.location.pathname !== expectedPath(kind)) return null
  const hash = window.location.hash
  if (!hash) return null
  const fragment = parseAccessContinuationFragment(kind, hash)
  window.history.replaceState(sanitizeRouterHistoryState(window.history.state), '', `${window.location.pathname}${window.location.search}`)
  if (fragment) {
    pending.set(kind, fragment)
    invalidRouteCaptures.delete(kind)
  } else {
    pending.delete(kind)
    invalidRouteCaptures.add(kind)
  }
  return fragment
}

/** Consume whether a receiver route carried a malformed replacement fragment. */
export function takeInvalidAccessContinuationCapture(kind: AccessContinuationKind): boolean {
  const invalid = invalidRouteCaptures.has(kind)
  invalidRouteCaptures.delete(kind)
  return invalid
}

export function takeAccessContinuationFragment(kind: AccessContinuationKind): AccessContinuationFragment | null {
  const fragment = pending.get(kind) ?? null
  pending.delete(kind)
  return fragment
}
