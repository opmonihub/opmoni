<script setup lang="ts">
import { monitoringGroups, monitoringListPath, monitoringObligations } from '~/utils/monitoringNav'

definePageMeta({ middleware: 'auth' })

const firstObligation = monitoringObligations[0]
const attentionFor = () => '0'
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col gap-8 overflow-y-auto p-4 sm:p-6">
    <section class="flex flex-col gap-4">
      <div>
        <h2 class="text-lg font-semibold text-highlighted">
          Empresas
        </h2>
        <p class="text-sm text-muted">
          Clientes acompanhados neste painel.
        </p>
      </div>

      <UPageGrid class="lg:grid-cols-4 gap-4 sm:gap-6 lg:gap-px">
        <MetricCard
          icon="i-lucide-building-2"
          title="Na carteira"
          :to="firstObligation ? monitoringListPath(firstObligation) : undefined"
          value="0"
        />
      </UPageGrid>
    </section>

    <section v-for="group in monitoringGroups" :key="group.label" class="flex flex-col gap-4">
      <div class="flex items-start gap-3">
        <UIcon :name="group.icon" class="mt-0.5 size-5 text-muted" />
        <div>
          <h2 class="text-lg font-semibold text-highlighted">
            {{ group.label }}
          </h2>
          <p class="text-sm text-muted">
            {{ group.description }}
          </p>
        </div>
      </div>

      <UPageGrid class="lg:grid-cols-4 gap-4 sm:gap-6 lg:gap-px">
        <MetricCard
          v-for="obligation in group.pages"
          :key="monitoringListPath(obligation)"
          :icon="group.icon"
          :title="obligation.label"
          :to="monitoringListPath(obligation)"
          :value="attentionFor()"
        />
      </UPageGrid>
    </section>
  </div>
</template>
