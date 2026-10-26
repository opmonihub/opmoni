import {
  currentReferenceMonth,
  formatReferenceMonthLabel,
  isWorkMonthScopedPath,
  parseReferenceMonth,
  REFERENCE_MONTH_RE,
  shiftReferenceMonth
} from '~/utils/workReferenceMonth'

/**
 * Shared competência (YYYY-MM) for Work list views.
 * Persists in `useState` so tab switches keep the month, and mirrors
 * `?reference_month=` on month-scoped routes.
 */
export function useWorkReferenceMonth(options: { syncRoute?: boolean } = {}) {
  const route = useRoute()
  const router = useRouter()

  const navigation = useListNavigationState('work-reference-month', {
    defaults: () => ({ month: currentReferenceMonth() }),
    normalize: value => ({ month: parseReferenceMonth((value as { month?: unknown } | null)?.month) ?? currentReferenceMonth() }),
    fromQuery: () => {
      const parsed = isWorkMonthScopedPath(route.path) ? parseReferenceMonth(route.query.reference_month) : null
      return parsed ? { month: parsed } : null
    }
  })
  const month = computed({
    get: () => navigation.value.month,
    set: (value: string) => { navigation.value = { month: value } }
  })

  const isMonthScoped = computed(() => isWorkMonthScopedPath(route.path))

  const referenceMonth = computed({
    get: () => month.value,
    set: (value: string) => {
      if (!REFERENCE_MONTH_RE.test(value)) return
      month.value = value
    }
  })

  const label = computed(() => formatReferenceMonthLabel(month.value))

  if (options.syncRoute) {
    watch(
      [isMonthScoped, month, () => route.query.reference_month],
      () => {
        if (!isMonthScoped.value) return
        if (route.query.reference_month === month.value) return
        router.replace({
          query: {
            ...route.query,
            reference_month: month.value
          }
        })
      },
      { immediate: true }
    )
  }

  function prevMonth() {
    month.value = shiftReferenceMonth(month.value, -1)
  }

  function nextMonth() {
    month.value = shiftReferenceMonth(month.value, 1)
  }

  return {
    referenceMonth,
    label,
    isMonthScoped,
    prevMonth,
    nextMonth
  }
}
