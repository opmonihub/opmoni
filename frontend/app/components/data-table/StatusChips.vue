<script setup lang="ts">
defineProps<{
  items: { label: string, value: string, to: string, count?: number }[]
  active: string
}>()
</script>

<template>
  <!--
    One scrolling line. Five counters at two per row spent three rows of chrome
    before the list even started, and the two actions that used to sit beside
    them took the width the counters needed. A phone shows three and a half and
    swipes for the rest, and the count of the selected one is always on screen.

    A scroller and not a select: a select would keep one reading visible and
    hide the four the office is comparing, and on this page the counters are
    the primary reading, not a filter. The negative margin bleeds to the screen
    edge because that is what tells a thumb there is more — it matches the
    `p-3` of the sheet body this sits in, so it only ever applies on a phone.
  -->
  <div class="-mx-3 flex snap-x snap-proximity scroll-px-3 gap-1.5 overflow-x-auto px-3 py-0.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
    <UButton
      v-for="item in items"
      :key="item.value"
      :label="item.label"
      :to="item.to"
      size="sm"
      :color="active === item.value ? 'primary' : 'neutral'"
      :variant="active === item.value ? 'soft' : 'outline'"
      class="shrink-0 snap-start"
      :ui="{ label: 'truncate' }"
      :aria-pressed="active === item.value"
    >
      <template v-if="item.count !== undefined" #trailing>
        <UKbd>{{ item.count }}</UKbd>
      </template>
    </UButton>
  </div>
</template>
