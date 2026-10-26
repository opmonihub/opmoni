import { computed } from 'vue'
import type { ComputedRef } from 'vue'

/** First confirmation belongs to the current read; later grant changes invalidate it. */
export function useOfficeErpReadIdentity(authority: () => unknown[], capabilityKey: () => string | null | undefined): ComputedRef<string> {
  const snapshot = computed<{ authority: string, capability: string | null, revision: number }>((previous) => {
    const owner = JSON.stringify(authority())
    const capability = capabilityKey() ?? null
    return {
      authority: owner,
      capability,
      revision: previous?.authority === owner
        ? previous.revision + (previous.capability !== null && previous.capability !== capability ? 1 : 0)
        : 0
    }
  })
  return computed(() => JSON.stringify([snapshot.value.authority, snapshot.value.revision]))
}
