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
export function useWorkReferenceMonth() {
  const route = useRoute()
  const router = useRouter()

  const month = useState<string>('work-reference-month', () => {
    return parseReferenceMonth(route.query.reference_month) ?? currentReferenceMonth()
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

  watch(
    () => route.query.reference_month,
    (raw) => {
      const parsed = parseReferenceMonth(raw)
      if (parsed && parsed !== month.value) {
        month.value = parsed
      }
    },
    { immediate: true }
  )

  watch(
    [isMonthScoped, month],
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
