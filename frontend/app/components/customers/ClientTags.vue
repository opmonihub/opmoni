<script setup lang="ts">
import type { ClientTag } from '~/types/client'

const props = defineProps<{
  tags?: ClientTag[]
  collapse?: boolean
}>()

const root = useTemplateRef<HTMLElement>('root')
const measure = useTemplateRef<HTMLElement>('measure')
const visibleCount = ref(props.tags?.length ?? 0)

const visibleTags = computed(() => {
  if (!props.collapse || !props.tags) return props.tags ?? []
  return props.tags.slice(0, visibleCount.value)
})

const hiddenTags = computed(() => {
  if (!props.collapse || !props.tags) return []
  return props.tags.slice(visibleCount.value)
})

const hiddenLabel = computed(() => hiddenTags.value.map(tag => tag.name).join(', '))

function measureFit() {
  const tags = props.tags
  if (!props.collapse || !tags?.length || !root.value || !measure.value) {
    visibleCount.value = tags?.length ?? 0
    return
  }

  const available = root.value.clientWidth
  const badges = [...measure.value.querySelectorAll<HTMLElement>('[data-measure]')]
  const counter = measure.value.querySelector<HTMLElement>('[data-counter]')
  if (!available || badges.length !== tags.length) return

  const gap = Number.parseFloat(getComputedStyle(measure.value).columnGap) || 0
  const counterWidth = counter?.offsetWidth ?? 0
  const widths = badges.map(badge => badge.offsetWidth)
  const total = widths.reduce((sum, width) => sum + width, 0) + gap * Math.max(0, widths.length - 1)

  if (total <= available) {
    visibleCount.value = tags.length
    return
  }

  let used = 0
  let count = 0
  for (let index = 0; index < widths.length; index++) {
    const width = widths[index] ?? 0
    const moreAfter = index < widths.length - 1
    const reserve = moreAfter ? counterWidth + gap : 0
    const next = used + width + (count > 0 ? gap : 0)
    if (next + reserve > available) break
    used = next
    count++
  }

  visibleCount.value = Math.max(1, count)
}

let observer: ResizeObserver | undefined

onMounted(() => {
  measureFit()
  if (!root.value || !props.collapse) return
  observer = new ResizeObserver(() => measureFit())
  observer.observe(root.value)
})

onBeforeUnmount(() => observer?.disconnect())

watch(() => props.tags, () => nextTick(measureFit), { deep: true })
</script>

<template>
  <div v-if="tags?.length && !collapse" class="flex flex-wrap gap-1">
    <UBadge
      v-for="tag in tags"
      :key="tag.id"
      :label="tag.name"
      :color="tag.color"
      variant="subtle"
      size="md"
    />
  </div>

  <div v-else-if="tags?.length" ref="root" class="relative min-w-0">
    <div
      ref="measure"
      class="pointer-events-none invisible absolute top-0 left-0 flex w-max flex-nowrap gap-1"
      aria-hidden="true"
    >
      <span
        v-for="tag in tags"
        :key="tag.id"
        data-measure
        class="inline-flex"
      >
        <UBadge
          :label="tag.name"
          :color="tag.color"
          variant="subtle"
          size="md"
        />
      </span>
      <span data-counter class="inline-flex">
        <UBadge
          :label="`+${tags.length}`"
          color="neutral"
          variant="subtle"
          size="md"
        />
      </span>
    </div>

    <div class="flex flex-nowrap items-center gap-1">
      <UBadge
        v-for="tag in visibleTags"
        :key="tag.id"
        :label="tag.name"
        :color="tag.color"
        variant="subtle"
        size="md"
        class="min-w-0 shrink"
        :title="tag.name"
        :ui="{ label: 'truncate' }"
      />
      <UTooltip v-if="hiddenTags.length" :text="hiddenLabel">
        <UBadge
          :label="`+${hiddenTags.length}`"
          color="neutral"
          variant="subtle"
          size="md"
          class="shrink-0"
          :aria-label="`Mais ${hiddenTags.length} tags: ${hiddenLabel}`"
        />
      </UTooltip>
    </div>
  </div>
</template>
