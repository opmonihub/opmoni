/**
 * SSR-safe media query for DOM branches (`v-if` / structural swaps).
 *
 * VueUse `useMediaQuery` is `false` on the server, then flips to the real
 * match during client setup — before hydration — which mismatches the HTML.
 * Keep the SSR value until `onMounted` so the first client render matches.
 */
export function useClientMediaQuery(query: string) {
  const matches = useMediaQuery(query)
  const ready = ref(false)

  onMounted(() => {
    ready.value = true
  })

  return computed(() => ready.value && matches.value)
}
