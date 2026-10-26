/** Reconcile scalar fields owned by one editor without sharing its private draft. */
export function createSettingsFieldDraft(form: Record<string, unknown>) {
  let baseline: Record<string, unknown> = {}
  const conflicts = new Set<string>()

  function reconcile(values: Record<string, unknown>): string[] {
    for (const [key, value] of Object.entries(values)) {
      if (!Object.hasOwn(baseline, key) || Object.is(form[key], baseline[key]) || Object.is(form[key], value)) {
        form[key] = value
        conflicts.delete(key)
      } else if (!Object.is(value, baseline[key])) {
        conflicts.add(key)
      }
      baseline[key] = value
    }
    return [...conflicts]
  }

  function reset(values: Record<string, unknown> = {}) {
    for (const key of Object.keys(form)) Reflect.deleteProperty(form, key)
    baseline = {}
    conflicts.clear()
    reconcile(values)
  }

  function confirm(values: Record<string, unknown>, submitted: Record<string, unknown>) {
    for (const key of Object.keys(submitted)) {
      if (!Object.hasOwn(values, key)) continue
      if (Object.is(form[key], submitted[key])) form[key] = values[key]
      baseline[key] = values[key]
      conflicts.delete(key)
    }
    return [...conflicts]
  }

  return { reconcile, reset, confirm }
}
