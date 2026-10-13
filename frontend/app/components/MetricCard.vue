<script setup lang="ts">
const props = withDefaults(defineProps<{
  icon: string
  title: string
  to?: string
  loading?: boolean
  value?: string | number
  valueClass?: string
  /** `brand` keeps the template's green icon. `quiet` is the neutral portfolio tile. */
  tone?: 'brand' | 'quiet'
  compact?: boolean
  selected?: boolean
  unavailable?: boolean
  iconClass?: string
}>(), {
  loading: false,
  valueClass: 'text-highlighted',
  tone: 'quiet',
  compact: false,
  selected: false,
  unavailable: false,
  iconClass: 'text-muted'
})

const cardUi = computed(() => ({
  container: 'gap-y-1.5',
  wrapper: 'items-start',
  leading: props.tone === 'brand'
    ? 'p-2.5 rounded-full bg-primary/10 ring ring-inset ring-primary/25 flex-col'
    : 'p-2.5 rounded-full bg-elevated ring ring-inset ring-default flex-col',
  title: props.tone === 'brand'
    ? 'font-normal text-muted text-xs uppercase'
    : 'font-normal text-muted text-sm'
}))
</script>

<template>
  <ULink
    v-if="compact"
    :to="to"
    as="div"
    raw
    exact
    :active="selected"
    :aria-current="selected ? 'page' : undefined"
    :aria-busy="loading"
    class="flex h-16 min-w-0 items-center gap-2 rounded-lg px-3 text-left ring ring-inset transition-colors hover:bg-elevated focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none"
    :class="selected ? 'bg-primary/5 ring-primary' : 'bg-elevated/50 ring-default'"
  >
    <UIcon
      :name="icon"
      class="size-4 shrink-0"
      :class="iconClass"
      aria-hidden="true"
    />
    <span class="flex min-w-0 flex-col gap-0.5">
      <span class="truncate text-sm leading-4 text-muted">{{ title }}</span>
      <slot>
        <span class="text-xl leading-6 font-semibold tabular-nums" :class="valueClass">
          <template v-if="loading">
            <USkeleton class="h-6 w-10" aria-hidden="true" />
            <span class="sr-only">Carregando contagem</span>
          </template>
          <template v-else-if="unavailable || value == null">
            <span aria-hidden="true">—</span>
            <span class="sr-only">Contagem indisponível</span>
          </template>
          <template v-else>{{ value }}</template>
        </span>
      </slot>
    </span>
  </ULink>

  <UPageCard
    v-else
    :icon="icon"
    :title="title"
    :to="to"
    variant="subtle"
    :ui="cardUi"
    class="lg:rounded-none first:rounded-l-lg last:rounded-r-lg hover:z-1"
  >
    <slot>
      <span class="text-2xl font-semibold tabular-nums" :class="valueClass">
        <USkeleton v-if="loading" class="h-8 w-12" />
        <template v-else>{{ value }}</template>
      </span>
    </slot>
  </UPageCard>
</template>
